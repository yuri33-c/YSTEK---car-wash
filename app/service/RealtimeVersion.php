<?php
namespace App\Service;

use Adianti\Database\TTransaction;

/** Leitura da revisão transacional usada para sincronizar telas do tenant. */
final class RealtimeVersion
{
    private function __construct() {}

    public static function current(string $tenantId): ?string
    {
        if ($tenantId === '') return null;

        try {
            $pdo = TTransaction::open();
            $stmt = $pdo->prepare('SELECT version FROM public.tenant_realtime_versions WHERE tenant_id = :tenant_id');
            $stmt->execute([':tenant_id' => $tenantId]);
            $version = $stmt->fetchColumn();
            TTransaction::close();
            return $version === false ? null : (string)$version;
        } catch (\Throwable $e) {
            TTransaction::close();
            return null;
        }
    }
}
