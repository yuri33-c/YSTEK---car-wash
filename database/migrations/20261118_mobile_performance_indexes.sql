-- Índices adicionais para reduzir o tempo das consultas mais frequentes.
-- Migration aditiva: não apaga nem reescreve registros existentes.

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
