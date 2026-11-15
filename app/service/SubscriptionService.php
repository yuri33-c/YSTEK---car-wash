<?php
namespace App\Service;

use PDO;

/**
 * Centraliza o ciclo de vida da assinatura de cada tenant.
 *
 * A tabela tenants continua armazenando o plano/status para compatibilidade,
 * enquanto subscriptions mantém o estado comercial, valores e vencimento.
 */
final class SubscriptionService
{
    private function __construct() {}

    /** Cria as tabelas comerciais idempotentemente em uma conexão PostgreSQL. */


    /** Devolve a assinatura atual; se ela não existir, usa os campos do tenant. */
    public static function current(PDO $pdo, string $tenantId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Mostra o nome comercial único sem gravar nem reescrever dados históricos.
            $row['plan'] = PlanCatalog::normalize($row['plan'] ?? null);
            return $row;
        }

        $stmt = $pdo->prepare('SELECT plan, status, start_date, due_date FROM tenants WHERE id = :tenant_id LIMIT 1');
        $stmt->execute([':tenant_id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'tenant_id' => $tenantId,
            'plan' => PlanCatalog::normalize($tenant['plan'] ?? PlanCatalog::PREMIUM),
            'amount' => (string)PlanCatalog::price($tenant['plan'] ?? PlanCatalog::PREMIUM),
            'status' => strtolower((string)($tenant['status'] ?? 'suspenso')) === 'ativo' ? 'active' : 'suspended',
            'start_date' => $tenant['start_date'] ?? date('Y-m-d'),
            'due_date' => $tenant['due_date'] ?? date('Y-m-d'),
            'grace_until' => null,
        ];
    }

    /** Retorna o estado efetivo usado no acesso. */
    public static function state(array $subscription, ?string $tenantStatus = null): string
    {
        if ($tenantStatus !== null && strtolower($tenantStatus) !== 'ativo') {
            return 'suspended';
        }

        $status = strtolower((string)($subscription['status'] ?? 'active'));
        if (in_array($status, ['suspended', 'canceled'], true)) {
            return $status === 'canceled' ? 'canceled' : 'suspended';
        }

        $today = new \DateTimeImmutable('today');
        try {
            $due = new \DateTimeImmutable((string)($subscription['due_date'] ?? 'today'));
        } catch (\Throwable $e) {
            return 'expired';
        }

        if ($today <= $due) {
            return 'active';
        }

        if (!empty($subscription['grace_until'])) {
            try {
                $grace = new \DateTimeImmutable((string)$subscription['grace_until']);
                if ($today <= $grace) {
                    return 'grace';
                }
            } catch (\Throwable $e) {
                // Data inválida de carência: tratar como vencida.
            }
        }

        return 'expired';
    }

    /** Quantidade de dias até o vencimento; negativo significa vencido. */
    public static function daysToDue(array $subscription): int
    {
        try {
            $today = new \DateTimeImmutable('today');
            $due = new \DateTimeImmutable((string)($subscription['due_date'] ?? 'today'));
            return (int)$today->diff($due)->format('%r%a');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Cria a assinatura inicial e registra o evento de criação. */
    public static function create(PDO $pdo, string $tenantId, string $plan, ?string $startDate = null, ?string $dueDate = null): void
    {
        $plan = PlanCatalog::normalize($plan);
        $startDate = $startDate ?: date('Y-m-d');
        $dueDate = $dueDate ?: date('Y-m-d', strtotime($startDate . ' +30 days'));
        $graceUntil = date('Y-m-d', strtotime($dueDate . ' +3 days'));

        $existing = $pdo->prepare('SELECT id FROM subscriptions WHERE tenant_id = :tenant_id LIMIT 1');
        $existing->execute([':tenant_id' => $tenantId]);
        $subscriptionId = $existing->fetchColumn();
        if ($subscriptionId) {
            return;
        }

        $stmt = $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, amount, status, start_date, due_date, grace_until) VALUES (:tenant_id, :plan, :amount, :status, :start_date, :due_date, :grace_until) RETURNING id');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plan' => $plan,
            ':amount' => PlanCatalog::price($plan),
            ':status' => 'active',
            ':start_date' => $startDate,
            ':due_date' => $dueDate,
            ':grace_until' => $graceUntil,
        ]);
        $subscriptionId = $stmt->fetchColumn();

        self::event($pdo, $tenantId, $subscriptionId, 'CREATED', PlanCatalog::price($plan), 'Assinatura criada para o plano ' . $plan);
    }

    /** Sincroniza plano e datas quando o SUPER_ADMIN altera uma empresa. */
    public static function sync(PDO $pdo, string $tenantId, string $plan, string $startDate, string $dueDate, string $status): void
    {
        $plan = PlanCatalog::normalize($plan);
        $subscription = self::current($pdo, $tenantId);
        $subStatus = $status === 'ativo' ? 'active' : 'suspended';
        $graceUntil = date('Y-m-d', strtotime($dueDate . ' +3 days'));

        // Sincronizar cadastro não reprecifica uma assinatura existente; a renovação
        // é o ponto que aplica o preço atual, sem apagar eventos nem histórico anterior.
        $stmt = $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, amount, status, start_date, due_date, grace_until) VALUES (:tenant_id, :plan, :amount, :status, :start_date, :due_date, :grace_until) ON CONFLICT (tenant_id) DO UPDATE SET plan=EXCLUDED.plan, status=EXCLUDED.status, start_date=EXCLUDED.start_date, due_date=EXCLUDED.due_date, grace_until=EXCLUDED.grace_until, updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plan' => $plan,
            ':amount' => PlanCatalog::price($plan),
            ':status' => $subStatus,
            ':start_date' => $startDate,
            ':due_date' => $dueDate,
            ':grace_until' => $graceUntil,
        ]);

        if (PlanCatalog::normalize($subscription['plan'] ?? '') !== $plan) {
            $idStmt = $pdo->prepare('SELECT id FROM subscriptions WHERE tenant_id = :tenant_id LIMIT 1');
            $idStmt->execute([':tenant_id' => $tenantId]);
            $subscriptionId = $idStmt->fetchColumn();
            self::event($pdo, $tenantId, $subscriptionId ?: null, 'PLAN_CHANGED', PlanCatalog::price($plan), 'Plano alterado para ' . $plan);
        }
    }

    /** Registra uma renovação manual e prorroga o vencimento por N dias. */
    public static function renew(PDO $pdo, string $tenantId, int $days = 30, ?float $amount = null, string $method = 'Manual'): array
    {
        $sub = self::current($pdo, $tenantId);
        $baseDate = new \DateTimeImmutable(max(date('Y-m-d'), (string)($sub['due_date'] ?? date('Y-m-d'))));
        $newDue = $baseDate->modify('+' . max(1, $days) . ' days')->format('Y-m-d');
        $grace = (new \DateTimeImmutable($newDue))->modify('+3 days')->format('Y-m-d');
        // O preço oficial vem do catálogo do servidor; nunca confiamos no valor enviado pelo navegador.
        $amount = PlanCatalog::price($sub['plan'] ?? PlanCatalog::PREMIUM);

        $stmt = $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, amount, status, start_date, due_date, grace_until) VALUES (:tenant_id, :plan, :amount, \'active\', CURRENT_DATE, :due_date, :grace) ON CONFLICT (tenant_id) DO UPDATE SET status=\'active\', plan=:plan, amount=:amount, due_date=:due_date, grace_until=:grace, updated_at=CURRENT_TIMESTAMP');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plan' => PlanCatalog::normalize($sub['plan'] ?? PlanCatalog::PREMIUM),
            ':amount' => $amount,
            ':due_date' => $newDue,
            ':grace' => $grace,
        ]);

        $idStmt = $pdo->prepare('SELECT id FROM subscriptions WHERE tenant_id = :tenant_id LIMIT 1');
        $idStmt->execute([':tenant_id' => $tenantId]);
        $subscriptionId = $idStmt->fetchColumn();
        self::event($pdo, $tenantId, $subscriptionId, 'RENEWED', $amount, 'Renovação de ' . $days . ' dias · cobrança ' . $method);

        return ['due_date' => $newDue, 'grace_until' => $grace, 'amount' => $amount];
    }

    /** Registra evento comercial para auditoria. */
    public static function event(PDO $pdo, string $tenantId, ?string $subscriptionId, string $eventType, float $amount, string $description): void
    {
        $stmt = $pdo->prepare('INSERT INTO subscription_events (tenant_id, subscription_id, event_type, amount, description) VALUES (:tenant_id, :subscription_id, :event_type, :amount, :description)');
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subscription_id' => $subscriptionId,
            ':event_type' => $eventType,
            ':amount' => $amount,
            ':description' => $description,
        ]);
    }
}
