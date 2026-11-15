<?php
/** Migração idempotente dos campos de lavador/comissão usados pela O.S. */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];
$pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS commission_percent NUMERIC(5,2) NOT NULL DEFAULT 0.00");
$pdo->exec("ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_id UUID");
$pdo->exec("ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_name_snapshot VARCHAR(100)");
$pdo->exec("ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_commission_percent NUMERIC(5,2) NOT NULL DEFAULT 0.00");
$pdo->exec("ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_commission_amount NUMERIC(10,2) NOT NULL DEFAULT 0.00");
try { $pdo->exec("ALTER TABLE service_orders ADD CONSTRAINT fk_service_orders_washer FOREIGN KEY (washer_id) REFERENCES users(id) ON DELETE SET NULL"); } catch (Throwable $ignore) {}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_service_orders_washer ON service_orders(tenant_id, washer_id, entry_time DESC)");
echo "ORDER_OPERATIONS_MIGRATION_OK\n";
