<?php
namespace App\Service;

use Adianti\Database\TTransaction;

/**
 * Regras de acesso aos registros que pertencem ao tenant autenticado.
 *
 * A classe evita que cada controller replique validações frágeis e, principalmente,
 * mantém a consulta explicitamente vinculada ao tenant atual.
 */
final class TenantAccessService
{
    private function __construct() {}

    /** Retorna o tenant atual e falha quando não existe contexto autenticado. */
    public static function id(): string
    {
        $id = trim((string)($_SESSION['tenant_id'] ?? ''));
        if ($id === '') {
            throw new \Exception('Sessão de empresa inválida. Faça login novamente.');
        }
        return $id;
    }

    /**
     * Confirma que um cliente pertence ao tenant atual.
     * O retorno contém os dados básicos necessários para regras de negócio.
     */
    public static function client(string $clientId): array
    {
        $tenantId = self::id();
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare('SELECT id, name FROM clients WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':id' => $clientId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \Exception('Cliente não pertence a esta empresa.');
        }
        return $row;
    }

    /** Confirma que um veículo pertence ao tenant atual e ao cliente informado. */
    public static function vehicle(string $vehicleId, string $clientId): array
    {
        $tenantId = self::id();
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare('SELECT id, client_id, plate, brand, model FROM vehicles WHERE id = :id AND tenant_id = :tenant_id AND client_id = :client_id LIMIT 1');
        $stmt->execute([
            ':id' => $vehicleId,
            ':tenant_id' => $tenantId,
            ':client_id' => $clientId,
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \Exception('Veículo não pertence ao cliente selecionado ou à empresa atual.');
        }
        return $row;
    }

    /**
     * Impede que o tenant administrativo da plataforma seja alterado por
     * operações da carteira comercial.
     */
    public static function ensureNotSystemTenant(string $tenantId): void
    {
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare("SELECT CASE WHEN
            EXISTS (SELECT 1 FROM users WHERE tenant_id = :user_tenant AND role = 'SUPER_ADMIN')
            OR EXISTS (SELECT 1 FROM tenants WHERE id = :tenant_id AND LOWER(COALESCE(email, '')) = 'plataforma@ystek.com.br')
            THEN 1 ELSE 0 END");
        $stmt->execute([':user_tenant' => $tenantId, ':tenant_id' => $tenantId]);
        if ($stmt->fetchColumn()) {
            throw new \Exception('A empresa administrativa da YSTEK não pode ser alterada por esta operação.');
        }
    }
}
