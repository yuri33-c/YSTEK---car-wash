<?php
/**
 * Migração comercial do YSTEK.
 *
 * Execute no deploy/local: php scripts/migrate_commercial.php
 * A operação é idempotente: pode ser executada novamente sem apagar dados.
 */
require __DIR__ . '/../app/config/application.php';
require __DIR__ . '/../lib/adianti/database/TTransaction.php';
require __DIR__ . '/../app/service/PlanCatalog.php';
require __DIR__ . '/../app/service/SubscriptionService.php';
require __DIR__ . '/../app/service/AuditService.php';
require __DIR__ . '/../app/service/LoginProtectionService.php';

use Adianti\Database\TTransaction;
use App\Service\SubscriptionService;
use App\Service\AuditService;

try {
    TTransaction::open();
    $pdo = TTransaction::get();
    SubscriptionService::ensureSchema($pdo);
    AuditService::ensureSchema();
    App\Service\LoginProtectionService::ensureSchema($pdo);

    // Migra tenants antigos para a tabela comercial sem substituir dados existentes.
    $legacy = $pdo->query("SELECT id, plan, status, start_date, due_date FROM tenants WHERE id NOT IN (SELECT tenant_id FROM subscriptions)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($legacy as $tenant) {
        $stmt = $pdo->prepare('INSERT INTO subscriptions (tenant_id, plan, amount, status, start_date, due_date, grace_until) VALUES (:tenant_id, :plan, :amount, :status, :start_date, :due_date, :grace_until)');
        $plan = App\Service\PlanCatalog::normalize($tenant['plan'] ?? App\Service\PlanCatalog::PREMIUM);
        $start = $tenant['start_date'] ?? date('Y-m-d');
        $due = $tenant['due_date'] ?? date('Y-m-d', strtotime($start . ' +30 days'));
        $stmt->execute([
            ':tenant_id' => $tenant['id'],
            ':plan' => $plan,
            ':amount' => App\Service\PlanCatalog::price($plan),
            ':status' => strtolower((string)$tenant['status']) === 'ativo' ? 'active' : 'suspended',
            ':start_date' => $start,
            ':due_date' => $due,
            ':grace_until' => date('Y-m-d', strtotime($due . ' +3 days')),
        ]);
    }

    TTransaction::close();
    echo "[OK] Estrutura comercial e de auditoria atualizada.\n";
} catch (Throwable $e) {
    try { TTransaction::close(); } catch (Throwable $ignore) {}
    fwrite(STDERR, "[ERRO] {$e->getMessage()}\n");
    exit(1);
}
