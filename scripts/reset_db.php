<?php
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];
try {
    if (strtolower((string)(getenv('YSTEK_APP_ENV') ?: 'production')) === 'production') {
        throw new RuntimeException('reset_db.php é proibido em produção.');
    }
    $pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};", $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    // Drop all tables
    $pdo->exec("DROP TABLE IF EXISTS service_order_items CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS service_orders CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS financial_records CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS services CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS vehicles CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS clients CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS users CASCADE;");
    $pdo->exec("DROP TABLE IF EXISTS tenants CASCADE;");
    
    // Run schema
    $sql = file_get_contents(__DIR__ . '/../app/config/schema.sql');
    $pdo->exec($sql);
    echo "Database reset with UUIDs successfully.\n";
} catch (Exception $e) {
    echo "Error resetting DB: " . $e->getMessage() . "\n";
}
