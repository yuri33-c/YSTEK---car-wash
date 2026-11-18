-- Schema YSTEK Lava Jato - PostgreSQL
CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE TABLE IF NOT EXISTS tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(150) NOT NULL,
    legal_name VARCHAR(150),
    document VARCHAR(20),
    phone VARCHAR(20),
    whatsapp VARCHAR(20),
    email VARCHAR(100),
    cep VARCHAR(10),
    street VARCHAR(150),
    number VARCHAR(20),
    neighborhood VARCHAR(100),
    city VARCHAR(100),
    state VARCHAR(2),
    -- O único plano comercial para novas empresas é Premium.
    plan VARCHAR(50) DEFAULT 'Premium',
    status VARCHAR(20) DEFAULT 'ativo',
    start_date DATE DEFAULT CURRENT_DATE,
    due_date DATE DEFAULT (CURRENT_DATE + INTERVAL '30 days'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20),
    password VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'ADMIN_TENANT',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clients (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(100),
    document VARCHAR(20),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicles (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    client_id UUID REFERENCES clients(id) ON DELETE CASCADE,
    plate VARCHAR(10) NOT NULL,
    brand VARCHAR(50),
    model VARCHAR(50),
    color VARCHAR(30),
    category VARCHAR(30) NOT NULL DEFAULT 'Passeio',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS services (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    duration_minutes INT DEFAULT 45,
    status VARCHAR(20) DEFAULT 'ativo'
);

CREATE TABLE IF NOT EXISTS service_orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    client_id UUID REFERENCES clients(id),
    vehicle_id UUID REFERENCES vehicles(id),
    status VARCHAR(30) DEFAULT 'Pendente',
    total_amount NUMERIC(10,2) DEFAULT 0.00,
    payment_status VARCHAR(30) DEFAULT 'Pendente',
    payment_method VARCHAR(50),
    entry_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completion_time TIMESTAMP,
    notes TEXT,
    order_number INTEGER,
    client_name_snapshot VARCHAR(255),
    client_phone_snapshot VARCHAR(40),
    vehicle_plate_snapshot VARCHAR(20),
    vehicle_desc_snapshot VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS service_order_items (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    service_order_id UUID REFERENCES service_orders(id) ON DELETE CASCADE,
    service_id UUID REFERENCES services(id),
    price NUMERIC(10,2) NOT NULL,
    service_name_snapshot VARCHAR(100),
    service_description_snapshot TEXT
);
CREATE TABLE IF NOT EXISTS financial_records (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE CASCADE,
    description VARCHAR(200) NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount NUMERIC(10,2) NOT NULL,
    category VARCHAR(50),
    due_date DATE DEFAULT CURRENT_DATE,
    status VARCHAR(20) DEFAULT 'Pago',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Nenhum dado de demonstração é inserido pelo schema. O primeiro SUPER_ADMIN é criado por CLI.


-- Compatibilidade com instalações anteriores: a categoria 'SUV / Picape' possui mais de 10 caracteres.
ALTER TABLE vehicles ALTER COLUMN category TYPE VARCHAR(30);

-- Compatibilidade com bancos já existentes
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS legal_name VARCHAR(150);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS whatsapp VARCHAR(20);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS cep VARCHAR(10);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS street VARCHAR(150);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS number VARCHAR(20);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS neighborhood VARCHAR(100);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS city VARCHAR(100);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS state VARCHAR(2);
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT CURRENT_DATE;
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS due_date DATE DEFAULT (CURRENT_DATE + INTERVAL '30 days');
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20);
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS order_number INTEGER;
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS client_name_snapshot VARCHAR(255);
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS client_phone_snapshot VARCHAR(40);
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS vehicle_plate_snapshot VARCHAR(20);
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS vehicle_desc_snapshot VARCHAR(255);
ALTER TABLE service_order_items ADD COLUMN IF NOT EXISTS service_name_snapshot VARCHAR(100);
ALTER TABLE service_order_items ADD COLUMN IF NOT EXISTS service_description_snapshot TEXT;
CREATE UNIQUE INDEX IF NOT EXISTS ux_service_orders_tenant_number
    ON service_orders (tenant_id, order_number)
    WHERE order_number IS NOT NULL;

-- Configurações por empresa (disponível em todos os planos)
CREATE TABLE IF NOT EXISTS tenant_settings (
    tenant_id UUID PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
    whatsapp_number VARCHAR(20),
    whatsapp_message TEXT,
    default_commission_percent NUMERIC(5,2) NOT NULL DEFAULT 20.00,
    monthly_revenue_goal NUMERIC(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- =========================
-- Camada comercial / produção
-- =========================
CREATE TABLE IF NOT EXISTS subscriptions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL UNIQUE REFERENCES tenants(id) ON DELETE CASCADE,
    plan VARCHAR(50) NOT NULL DEFAULT 'Premium',
    amount NUMERIC(10,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    start_date DATE NOT NULL DEFAULT CURRENT_DATE,
    due_date DATE NOT NULL DEFAULT (CURRENT_DATE + INTERVAL '30 days'),
    grace_until DATE,
    canceled_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subscription_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    subscription_id UUID REFERENCES subscriptions(id) ON DELETE CASCADE,
    event_type VARCHAR(40) NOT NULL,
    amount NUMERIC(10,2) DEFAULT 0.00,
    description VARCHAR(255),
    metadata JSONB,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID REFERENCES tenants(id) ON DELETE SET NULL,
    user_id UUID REFERENCES users(id) ON DELETE SET NULL,
    action VARCHAR(80) NOT NULL,
    entity VARCHAR(80),
    entity_id UUID,
    details JSONB,
    ip VARCHAR(64),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP;
CREATE INDEX IF NOT EXISTS idx_subscriptions_due_date ON subscriptions(due_date);
CREATE INDEX IF NOT EXISTS idx_subscription_events_tenant ON subscription_events(tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_tenant_date ON audit_logs(tenant_id, created_at DESC);


CREATE TABLE IF NOT EXISTS login_attempts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    email VARCHAR(120) NOT NULL,
    ip VARCHAR(64) NOT NULL,
    success BOOLEAN NOT NULL DEFAULT FALSE,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_email_time ON login_attempts(email, attempted_at DESC);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, attempted_at DESC);


-- Operação profissional: lavador e comissão vinculados diretamente à equipe e à O.S.
ALTER TABLE users ADD COLUMN IF NOT EXISTS commission_percent NUMERIC(5,2) NOT NULL DEFAULT 0.00;
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_id UUID REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_name_snapshot VARCHAR(100);
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_commission_percent NUMERIC(5,2) NOT NULL DEFAULT 0.00;
ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS washer_commission_amount NUMERIC(10,2) NOT NULL DEFAULT 0.00;
CREATE INDEX IF NOT EXISTS idx_service_orders_washer ON service_orders(tenant_id, washer_id, entry_time DESC);

-- =========================
-- Check-in e checklist de entrada do veículo
-- =========================
CREATE TABLE IF NOT EXISTS vehicle_checkins (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    service_order_id UUID NOT NULL UNIQUE REFERENCES service_orders(id) ON DELETE CASCADE,
    odometer INTEGER,
    fuel_level SMALLINT,
    key_received BOOLEAN NOT NULL DEFAULT TRUE,
    spare_tire BOOLEAN NOT NULL DEFAULT FALSE,
    jack BOOLEAN NOT NULL DEFAULT FALSE,
    wheel_wrench BOOLEAN NOT NULL DEFAULT FALSE,
    documents_received BOOLEAN NOT NULL DEFAULT FALSE,
    accessories TEXT DEFAULT '',
    observations TEXT DEFAULT '',
    confirmed_at TIMESTAMP,
    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT vehicle_checkins_fuel_level_ck CHECK (fuel_level IS NULL OR (fuel_level >= 0 AND fuel_level <= 100)),
    CONSTRAINT vehicle_checkins_odometer_ck CHECK (odometer IS NULL OR odometer >= 0)
);

CREATE TABLE IF NOT EXISTS vehicle_damages (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    service_order_id UUID NOT NULL REFERENCES service_orders(id) ON DELETE CASCADE,
    area VARCHAR(50) NOT NULL,
    damage_type VARCHAR(40) NOT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'Leve',
    description TEXT DEFAULT '',
    created_by UUID REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT vehicle_damages_severity_ck CHECK (severity IN ('Leve', 'Média', 'Grave'))
);

CREATE INDEX IF NOT EXISTS idx_vehicle_checkins_tenant_order ON vehicle_checkins(tenant_id, service_order_id);
CREATE INDEX IF NOT EXISTS idx_vehicle_damages_tenant_order ON vehicle_damages(tenant_id, service_order_id, created_at DESC);

CREATE TABLE IF NOT EXISTS ystek_sessions (
    session_id VARCHAR(128) PRIMARY KEY,
    payload TEXT NOT NULL,
    updated_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS ystek_sessions_updated_at_idx
    ON ystek_sessions (updated_at);

-- Índices de desempenho para instalações novas.
CREATE INDEX IF NOT EXISTS idx_service_orders_tenant_entry_id_desc
    ON public.service_orders (tenant_id, entry_time DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_clients_tenant_created_desc
    ON public.clients (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_vehicles_tenant_created_desc
    ON public.vehicles (tenant_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_service_order_items_order_id
    ON public.service_order_items (service_order_id);
CREATE INDEX IF NOT EXISTS idx_services_tenant_status
    ON public.services (tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_users_tenant_role_active
    ON public.users (tenant_id, role, active);
CREATE INDEX IF NOT EXISTS idx_financial_records_tenant_created_desc
    ON public.financial_records (tenant_id, created_at DESC);
