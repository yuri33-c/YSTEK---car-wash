<?php
/** Migração idempotente do catálogo de serviços. */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];
$pdo = new PDO("pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE EXTENSION IF NOT EXISTS pgcrypto");
$pdo->exec("CREATE TABLE IF NOT EXISTS public.services (id UUID PRIMARY KEY DEFAULT gen_random_uuid(), tenant_id UUID REFERENCES public.tenants(id) ON DELETE CASCADE, name VARCHAR(100) NOT NULL, description TEXT, price NUMERIC(12,2) NOT NULL DEFAULT 0.00, duration_minutes INT NOT NULL DEFAULT 45, status VARCHAR(20) NOT NULL DEFAULT 'ativo')");
$pdo->exec("ALTER TABLE public.services ADD COLUMN IF NOT EXISTS tenant_id UUID");
$pdo->exec("ALTER TABLE public.services ADD COLUMN IF NOT EXISTS name VARCHAR(100)");
$pdo->exec("ALTER TABLE public.services ADD COLUMN IF NOT EXISTS description TEXT");
$pdo->exec("ALTER TABLE public.services ADD COLUMN IF NOT EXISTS price NUMERIC(12,2) DEFAULT 0.00");
$pdo->exec("ALTER TABLE public.services ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT 45");
$pdo->exec("ALTER TABLE public.services ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'ativo'");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_services_tenant ON public.services(tenant_id)");
echo "SERVICE_CATALOG_MIGRATION_OK\n";
