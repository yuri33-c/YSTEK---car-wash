<?php
namespace App\Core;

use PDO;
use RuntimeException;

final class PostgresSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private ?PDO $pdo = null;
    private ?string $lockedId = null;

    public function open(string $path, string $name): bool
    {
        $config = require __DIR__ . '/../config/application.php';
        $db = $config['database'];
        $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
        if ($db['sslmode'] !== '') {
            $dsn .= ";sslmode={$db['sslmode']}";
        }
        $this->pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return true;
    }

    public function close(): bool
    {
        $this->releaseLock();
        $this->pdo = null;
        return true;
    }

    public function read(string $id): string|false
    {
        $this->acquireLock($id);
        $statement = $this->connection()->prepare(
            'SELECT payload FROM ystek_sessions WHERE session_id = :id AND expires_at > :now'
        );
        $statement->execute([':id' => $id, ':now' => time()]);
        $payload = $statement->fetchColumn();

        if ($payload === false) {
            return '';
        }

        $data = base64_decode((string)$payload, true);
        if ($data === false) {
            throw new RuntimeException('Os dados da sessão armazenada são inválidos.');
        }

        return $data;
    }

    public function write(string $id, string $data): bool
    {
        $this->acquireLock($id);
        $now = time();
        $expiresAt = $now + max(1, (int)ini_get('session.gc_maxlifetime'));
        $statement = $this->connection()->prepare(
            'INSERT INTO ystek_sessions (session_id, payload, updated_at, expires_at)
             VALUES (:id, :payload, :updated_at, :expires_at)
             ON CONFLICT (session_id) DO UPDATE
             SET payload = EXCLUDED.payload,
                 updated_at = EXCLUDED.updated_at,
                 expires_at = EXCLUDED.expires_at'
        );

        return $statement->execute([
            ':id' => $id,
            ':payload' => base64_encode($data),
            ':updated_at' => $now,
            ':expires_at' => $expiresAt,
        ]);
    }

    public function destroy(string $id): bool
    {
        $this->acquireLock($id);
        $statement = $this->connection()->prepare('DELETE FROM ystek_sessions WHERE session_id = :id');
        return $statement->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $cutoff = time() - max(1, $max_lifetime);
        $statement = $this->connection()->prepare('DELETE FROM ystek_sessions WHERE updated_at < :cutoff');
        $statement->execute([':cutoff' => $cutoff]);
        return $statement->rowCount();
    }

    public function validateId(string $id): bool
    {
        $statement = $this->connection()->prepare(
            'SELECT 1 FROM ystek_sessions WHERE session_id = :id AND expires_at > :now'
        );
        $statement->execute([':id' => $id, ':now' => time()]);
        return $statement->fetchColumn() !== false;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $this->acquireLock($id);
        $now = time();
        $expiresAt = $now + max(1, (int)ini_get('session.gc_maxlifetime'));
        $statement = $this->connection()->prepare(
            'UPDATE ystek_sessions SET updated_at = :updated_at, expires_at = :expires_at WHERE session_id = :id'
        );
        $statement->execute([
            ':id' => $id,
            ':updated_at' => $now,
            ':expires_at' => $expiresAt,
        ]);

        return $statement->rowCount() > 0;
    }

    private function acquireLock(string $id): void
    {
        if ($this->lockedId === $id) {
            return;
        }

        $this->releaseLock();
        $statement = $this->connection()->prepare('SELECT pg_advisory_lock(hashtext(:id))');
        $statement->execute([':id' => $id]);
        $statement->fetchColumn();
        $this->lockedId = $id;
    }

    private function releaseLock(): void
    {
        if ($this->lockedId === null || $this->pdo === null) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(hashtext(:id))');
        $statement->execute([':id' => $this->lockedId]);
        $unlocked = $statement->fetchColumn();
        if (!in_array($unlocked, [true, 't', '1', 1], true)) {
            throw new RuntimeException('Não foi possível liberar o bloqueio da sessão PostgreSQL.');
        }

        $this->lockedId = null;
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('A conexão PostgreSQL das sessões não está disponível.');
        }

        return $this->pdo;
    }
}
