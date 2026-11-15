<?php
namespace App\Service;

/**
 * Proteção básica contra tentativas repetidas de login.
 *
 * O controle é armazenado no PostgreSQL para não depender apenas da sessão do
 * navegador. O limite é deliberadamente simples e conservador para o MVP comercial.
 */
final class LoginProtectionService
{
    private const WINDOW_MINUTES = 15;
    private const MAX_FAILURES_PER_EMAIL = 5;
    private const MAX_FAILURES_PER_IP = 20;

    private function __construct() {}

    /** Cria a infraestrutura da limitação de tentativas de forma idempotente. */


    /** Bloqueia temporariamente o abuso por e-mail ou endereço IP. */
    public static function assertAllowed(\PDO $pdo, string $email, string $ip): void
    {
        $since = date('Y-m-d H:i:s', time() - (self::WINDOW_MINUTES * 60));

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = :email AND success = FALSE AND attempted_at >= :since");
        $stmt->execute([':email' => strtolower(trim($email)), ':since' => $since]);
        $emailFailures = (int)$stmt->fetchColumn();
        if ($emailFailures >= self::MAX_FAILURES_PER_EMAIL) {
            throw new \RuntimeException('Muitas tentativas de acesso. Aguarde alguns minutos e tente novamente.');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip = :ip AND success = FALSE AND attempted_at >= :since");
        $stmt->execute([':ip' => $ip, ':since' => $since]);
        $ipFailures = (int)$stmt->fetchColumn();
        if ($ipFailures >= self::MAX_FAILURES_PER_IP) {
            throw new \RuntimeException('Muitas tentativas de acesso a partir deste dispositivo. Aguarde alguns minutos e tente novamente.');
        }
    }


    /**
     * Remove falhas recentes após uma autenticação válida. Isso evita que tentativas
     * antigas feitas durante testes mantenham uma conta válida temporariamente bloqueada.
     */
    public static function clearFailures(\PDO $pdo, string $email, string $ip): void
    {
        $since = date('Y-m-d H:i:s', time() - (self::WINDOW_MINUTES * 60));
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE email = :email AND ip = :ip AND success = FALSE AND attempted_at >= :since');
        $stmt->execute([
            ':email' => strtolower(trim($email)),
            ':ip' => $ip,
            ':since' => $since,
        ]);
    }

    /** Registra o resultado da tentativa sem armazenar a senha. */
    public static function record(\PDO $pdo, string $email, string $ip, bool $success): void
    {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (email, ip, success) VALUES (:email, :ip, :success)');
        $stmt->execute([
            ':email' => strtolower(trim($email)),
            ':ip' => $ip,
            // PDO/PostgreSQL envia false como '' (inválido para BOOLEAN): converte explicitamente.
            ':success' => $success ? 'true' : 'false',
        ]);
    }
}
