<?php
/**
 * Diagnóstico simples da conexão e das tabelas principais.
 * Compatível com UUIDs: não consulta sequências SERIAL que não existem mais.
 */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    echo "DB OK: {$db['name']} @ {$db['host']}:{$db['port']}\n";

    foreach (['tenants','users','clients','vehicles','services','service_orders','financial_records','subscriptions','audit_logs','vehicle_checkins','vehicle_damages'] as $table) {
        $stmt = $pdo->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
        $stmt->execute([':table_name' => 'public.' . $table]);
        echo str_pad($table, 22) . ': ' . ($stmt->fetchColumn() ? 'OK' : 'AUSENTE') . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "DB ERROR: {$e->getMessage()}\n");
    exit(1);
}
