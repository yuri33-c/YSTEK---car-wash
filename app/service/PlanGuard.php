<?php
namespace App\Service;

use Adianti\Database\TTransaction;
use App\Model\Tenant;
use Adianti\Widget\TMessage;

/**
 * Guarda de acesso aos recursos do tenant.
 *
 * PREMIUM libera os recursos sem limite comercial; a assinatura continua sendo
 * validada separadamente pelo Auth em cada requisição.
 */
final class PlanGuard
{
    public static function currentPlan(): string
    {
        return PlanCatalog::normalize($_SESSION['tenant_plan'] ?? PlanCatalog::PREMIUM);
    }

    public static function currentSubscriptionState(): string
    {
        return (string)($_SESSION['subscription_state'] ?? 'active');
    }

    public static function allows(string $feature): bool
    {
        $role = (string)($_SESSION['role'] ?? '');
        if ($role === 'SUPER_ADMIN') return true;
        if (!in_array($role, ['ADMIN_TENANT', 'MANAGER', 'CASHIER', 'WASHER'], true)) return false;
        // A liberação de recursos não contorna a autenticação nem a validade comercial.
        return in_array((string)($_SESSION['subscription_state'] ?? ''), ['active', 'grace'], true)
            && PlanCatalog::allows(self::currentPlan(), $feature);
    }

    public static function ensure(string $feature): bool
    {
        if (self::allows($feature)) return true;

        TMessage::add('error', 'Este recurso exige uma assinatura válida e ativa.');
        return false;
    }

    public static function enforceLimit(string $resource, int $currentCount): void
    {
        if (($_SESSION['role'] ?? '') === 'SUPER_ADMIN') return;

        // Limites de volume não são mais diferenciados por categoria de plano.
        $plan = self::currentPlan();
        $limit = PlanCatalog::limit($plan, $resource);
        if ($limit !== null && $currentCount >= $limit) {
            $label = match ($resource) {
                'clients' => 'clientes',
                'vehicles' => 'veículos',
                'services' => 'serviços',
                'users' => 'usuários',
                default => $resource,
            };
            throw new \Exception(sprintf(
                'O plano %s permite até %s de %s.',
                $plan,
                number_format($limit, 0, ',', '.'),
                $label
            ));
        }
    }

    /** Atualiza na sessão o plano e o estado comercial diretamente do PostgreSQL. */
    public static function refreshTenantPlan(): void
    {
        if (!in_array((string)($_SESSION['role'] ?? ''), ['ADMIN_TENANT','MANAGER','CASHIER','WASHER'], true) || empty($_SESSION['tenant_id'])) return;

        try {
            TTransaction::open();
            $tenantId = (string)$_SESSION['tenant_id'];
            $tenant = new Tenant($tenantId);
            $pdo = TTransaction::get();
            $subscription = SubscriptionService::current($pdo, $tenantId);
            $_SESSION['tenant_plan'] = PlanCatalog::normalize($subscription['plan'] ?? $tenant->plan ?? PlanCatalog::PREMIUM);
            $_SESSION['subscription_state'] = SubscriptionService::state($subscription, (string)$tenant->status);
            $_SESSION['subscription_due_date'] = $subscription['due_date'] ?? ($tenant->due_date ?? null);
            $_SESSION['subscription_days_to_due'] = SubscriptionService::daysToDue($subscription);
            TTransaction::close();
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('PlanGuard refresh warning: ' . $e->getMessage());
        }
    }
}
