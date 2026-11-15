<?php
namespace App\Core;

use Adianti\Database\TTransaction;
use App\Model\Tenant;
use App\Model\User;
use App\Service\PlanCatalog;
use App\Service\SubscriptionService;

/**
 * Guarda central de autenticação e contexto do usuário.
 *
 * A sessão guarda apenas o mínimo necessário para navegar; a situação do
 * tenant e da assinatura é revalidada no banco para evitar sessões obsoletas.
 */
final class Auth
{
    private const IDLE_TIMEOUT = 28800; // 8 horas de inatividade.
    private function __construct() {}

    public static function start(): void
    {
        Security::startSession();
    }

    public static function check(): bool
    {
        self::start();
        if (empty($_SESSION['user_id']) || empty($_SESSION['role'])) return false;

        $lastActivity = (int)($_SESSION['_last_activity'] ?? time());
        if ($lastActivity > 0 && time() - $lastActivity > self::IDLE_TIMEOUT) {
            self::logout();
            return false;
        }
        $_SESSION['_last_activity'] = time();
        return true;
    }

    public static function role(): string
    {
        self::start();
        return strtoupper(trim((string)($_SESSION['role'] ?? '')));
    }

    public static function tenantId(): ?string
    {
        self::start();
        $id = trim((string)($_SESSION['tenant_id'] ?? ''));
        return $id !== '' ? $id : null;
    }

    /** Define se o papel pertence a uma conta operacional de tenant. */
    public static function isTenantRole(?string $role = null): bool
    {
        $role = strtoupper(trim((string)($role ?? self::role())));
        return in_array($role, ['ADMIN_TENANT', 'MANAGER', 'CASHIER', 'WASHER'], true);
    }

    /** Retorna o contexto atual da assinatura para a interface. */
    public static function subscription(): array
    {
        self::start();
        return [
            'plan' => PlanCatalog::normalize($_SESSION['tenant_plan'] ?? PlanCatalog::PREMIUM),
            'state' => (string)($_SESSION['subscription_state'] ?? 'active'),
            'due_date' => $_SESSION['subscription_due_date'] ?? null,
            'days_to_due' => (int)($_SESSION['subscription_days_to_due'] ?? 0),
        ];
    }

    /**
     * Confirma em cada requisição que o usuário da sessão ainda existe, está ativo
     * e mantém o mesmo papel/contexto autenticado. Isso permite invalidar acessos
     * administrativos sem depender de o usuário sair manualmente.
     */
    public static function validateCurrentUser(): bool
    {
        $userId = trim((string)($_SESSION['user_id'] ?? ''));
        $sessionRole = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        if ($userId === '' || $sessionRole === '') return false;

        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $stmt = $pdo->prepare('SELECT active, role, tenant_id, name FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            TTransaction::close();

            if (!$user || !self::isActive($user['active'] ?? false)) {
                return false;
            }

            $dbRole = strtoupper(trim((string)($user['role'] ?? '')));
            $sessionTenant = (string)($_SESSION['tenant_id'] ?? '');
            $dbTenant = (string)($user['tenant_id'] ?? '');
            if ($dbRole !== $sessionRole || $dbTenant !== $sessionTenant) {
                return false;
            }

            $_SESSION['user_name'] = (string)($user['name'] ?? $_SESSION['user_name'] ?? 'Usuário');
            return true;
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Auth user validation error: ' . $e->getMessage());
            return false;
        }
    }

    /** Normaliza a coluna active que pode ser booleana ou texto em bancos legados. */
    private static function isActive($value): bool
    {
        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'f', 'no'], true);
    }

    /**
     * Revalida o tenant, o usuário e a assinatura. Em caso de inconsistência,
     * o acesso é negado com segurança.
     */
    public static function validateCurrentTenant(): bool
    {
        if (!self::isTenantRole()) return true;
        $tenantId = self::tenantId();
        if (!$tenantId) return false;

        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $tenant = new Tenant($tenantId);
            if (!$tenant->id || strtolower((string)$tenant->status) !== 'ativo') {
                TTransaction::close();
                return false;
            }

            // Confirma que o usuário ainda existe e permanece ativo.
            $stmt = $pdo->prepare('SELECT active, tenant_id FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (string)($_SESSION['user_id'] ?? '')]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$user || !self::isActive($user['active'] ?? false) || (string)$user['tenant_id'] !== $tenantId) {
                TTransaction::close();
                return false;
            }

            $subscription = SubscriptionService::current($pdo, $tenantId);
            $state = SubscriptionService::state($subscription, (string)$tenant->status);
            // Compatibilidade de leitura: valores antigos são apresentados como Premium,
            // sem modificar datas ou status da assinatura no fluxo de autenticação.
            $_SESSION['tenant_plan'] = PlanCatalog::normalize($subscription['plan'] ?? $tenant->plan ?? PlanCatalog::PREMIUM);
            $_SESSION['subscription_state'] = $state;
            $_SESSION['subscription_due_date'] = $subscription['due_date'] ?? ($tenant->due_date ?? null);
            $_SESSION['subscription_days_to_due'] = SubscriptionService::daysToDue($subscription);

            TTransaction::close();
            return in_array($state, ['active', 'grace'], true);
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Auth validation error: ' . $e->getMessage());
            return false;
        }
    }

    /** Remove completamente a autenticação atual. */
    public static function logout(): void
    {
        Security::destroySession();
    }
}
