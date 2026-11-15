<?php
/**
 * YSTEK — verificação de saúde antes de publicar.
 * Execute via CLI: php scripts/health_check.php
 * Não altera dados; apenas valida configuração, schema e pontos críticos.
 */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];
$errors = 0;
$warnings = 0;

printf("YSTEK Health Check
===================
");
printf("Ambiente: %s
", $config['general']['environment'] ?? 'local');

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "[OK] PostgreSQL conectado.
";

    $tables = ['tenants','users','clients','vehicles','services','service_orders','service_order_items','financial_records','subscriptions','subscription_events','audit_logs','login_attempts','tenant_realtime_versions'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT to_regclass(:table) IS NOT NULL AS exists");
        $stmt->execute([':table' => 'public.' . $table]);
        if ($stmt->fetchColumn()) {
            echo "[OK] Tabela {$table}.
";
        } else {
            echo "[ERRO] Tabela {$table} não existe.
";
            $errors++;
        }
    }

    $realtimeTables = ['tenants','users','clients','vehicles','services','service_orders','service_order_items','financial_records','vehicle_checkins','vehicle_damages','tenant_settings','subscriptions'];
    foreach ($realtimeTables as $table) {
        $regclass = $pdo->prepare('SELECT to_regclass(:table)');
        $regclass->execute([':table' => 'public.' . $table]);
        if ($regclass->fetchColumn()) {
            $trigger = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(:table) AND tgname = 'ystek_realtime_version' AND NOT tgisinternal)");
            $trigger->execute([':table' => 'public.' . $table]);
            if ($trigger->fetchColumn()) echo "[OK] Trigger de sincronização em {$table}.\n";
            else { echo "[ERRO] Trigger de sincronização ausente em {$table}.\n"; $errors++; }
        }
    }

    $columns = [
        ['users','role'], ['users','active'], ['users','last_login_at'],
        ['tenants','plan'], ['tenants','status'], ['tenants','due_date'],
    ];
    foreach ($columns as [$table,$column]) {
        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name=:table AND column_name=:column");
        $stmt->execute([':table'=>$table, ':column'=>$column]);
        if ($stmt->fetchColumn()) echo "[OK] {$table}.{$column}.
";
        else { echo "[ERRO] {$table}.{$column} ausente.
"; $errors++; }
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('SUPER_ADMIN','ADMIN_TENANT','MANAGER','CASHIER','WASHER') AND active = TRUE");
    echo "[INFO] Usuários ativos: " . $stmt->fetchColumn() . "
";
    $stmt = $pdo->query("SELECT COUNT(*) FROM tenants");
    echo "[INFO] Empresas: " . $stmt->fetchColumn() . "
";

    echo "
Resultado: " . ($errors ? "{$errors} erro(s)" : 'sem erros estruturais') . ($warnings ? ", {$warnings} aviso(s)" : '') . ".
";
    exit($errors ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] Falha no health check: {$e->getMessage()}
");
    exit(1);
}
