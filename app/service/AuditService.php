<?php
namespace App\Service;

use Adianti\Database\TTransaction;

/**
 * Registra ações importantes do sistema para rastreabilidade administrativa.
 *
 * A auditoria é opcional em instalações antigas: se a tabela ainda não existir,
 * o erro é apenas registrado no log, sem quebrar a operação principal.
 */
final class AuditService
{
    private function __construct() {}

    /** Cria a tabela de auditoria de forma idempotente. */


    /** Registra uma ação do usuário atual. */
    public static function log(string $action, ?string $entity = null, ?string $entityId = null, array $details = [], ?string $tenantId = null, ?string $userId = null): void
    {
        try {
            $pdo = TTransaction::get();
            $stmt = $pdo->prepare('INSERT INTO audit_logs (tenant_id, user_id, action, entity, entity_id, details, ip) VALUES (:tenant_id, :user_id, :action, :entity, :entity_id, CAST(:details AS jsonb), :ip)');
            $stmt->execute([
                ':tenant_id' => $tenantId ?? ($_SESSION['tenant_id'] ?? null),
                ':user_id' => $userId ?? ($_SESSION['user_id'] ?? null),
                ':action' => $action,
                ':entity' => $entity,
                ':entity_id' => $entityId,
                ':details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ]);
        } catch (\Throwable $e) {
            error_log('AuditService warning: ' . $e->getMessage());
        }
    }
}
