<?php
namespace App\Service;

use Adianti\Database\TTransaction;
use PDO;

/** Leitura da revisão transacional usada para sincronizar telas do tenant. */
final class RealtimeVersion
{
    private static array $requestCache = [];

    private function __construct() {}

    /**
     * Usa a conexão do chamador quando disponível. Isso evita abrir uma segunda
     * conexão PostgreSQL no mesmo carregamento de página.
     */
    public static function current(string $tenantId, ?PDO $connection = null): ?string
    {
        if ($tenantId === '') return null;
        if (array_key_exists($tenantId, self::$requestCache)) {
            return self::$requestCache[$tenantId];
        }

        $ownsConnection = $connection === null;
        try {
            $pdo = $connection ?? TTransaction::open();
            $stmt = $pdo->prepare('SELECT version FROM public.tenant_realtime_versions WHERE tenant_id = :tenant_id');
            $stmt->execute([':tenant_id' => $tenantId]);
            $version = $stmt->fetchColumn();
            $result = $version === false ? null : (string)$version;
            self::$requestCache[$tenantId] = $result;
            if ($ownsConnection) TTransaction::close();
            return $result;
        } catch (\Throwable $e) {
            if ($ownsConnection) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
            }
            self::$requestCache[$tenantId] = null;
            return null;
        }
    }
}
