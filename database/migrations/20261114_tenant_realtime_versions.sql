-- Revisão transacional por empresa para polling das telas operacionais.
CREATE TABLE IF NOT EXISTS public.tenant_realtime_versions (
    tenant_id UUID PRIMARY KEY REFERENCES public.tenants(id) ON DELETE CASCADE,
    version BIGINT NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO public.tenant_realtime_versions (tenant_id, version)
SELECT id, 0 FROM public.tenants
ON CONFLICT (tenant_id) DO NOTHING;

CREATE OR REPLACE FUNCTION public.bump_tenant_realtime_version()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    v_tenant_id UUID;
    v_order_id UUID;
BEGIN
    IF TG_TABLE_NAME = 'service_order_items' THEN
        v_order_id := COALESCE(
            (to_jsonb(NEW)->>'service_order_id')::UUID,
            (to_jsonb(OLD)->>'service_order_id')::UUID
        );
        SELECT tenant_id INTO v_tenant_id
        FROM public.service_orders
        WHERE id = v_order_id;
    ELSIF TG_TABLE_NAME = 'tenants' THEN
        v_tenant_id := COALESCE(
            (to_jsonb(NEW)->>'id')::UUID,
            (to_jsonb(OLD)->>'id')::UUID
        );
    ELSE
        v_tenant_id := COALESCE(
            (to_jsonb(NEW)->>'tenant_id')::UUID,
            (to_jsonb(OLD)->>'tenant_id')::UUID
        );
    END IF;

    IF v_tenant_id IS NOT NULL AND EXISTS (SELECT 1 FROM public.tenants WHERE id = v_tenant_id) THEN
        INSERT INTO public.tenant_realtime_versions (tenant_id, version, updated_at)
        VALUES (v_tenant_id, 1, CURRENT_TIMESTAMP)
        ON CONFLICT (tenant_id) DO UPDATE
        SET version = public.tenant_realtime_versions.version + 1,
            updated_at = CURRENT_TIMESTAMP;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS ystek_realtime_version ON public.tenants;
CREATE TRIGGER ystek_realtime_version
AFTER INSERT OR UPDATE ON public.tenants
FOR EACH ROW EXECUTE FUNCTION public.bump_tenant_realtime_version();

DO $$
DECLARE
    v_table TEXT;
    v_tables TEXT[] := ARRAY[
        'users', 'clients', 'vehicles', 'services', 'service_orders',
        'service_order_items', 'financial_records', 'vehicle_checkins',
        'vehicle_damages', 'tenant_settings', 'subscriptions'
    ];
BEGIN
    FOREACH v_table IN ARRAY v_tables LOOP
        IF to_regclass('public.' || v_table) IS NOT NULL THEN
            EXECUTE format('DROP TRIGGER IF EXISTS ystek_realtime_version ON public.%I', v_table);
            EXECUTE format(
                'CREATE TRIGGER ystek_realtime_version AFTER INSERT OR UPDATE OR DELETE ON public.%I FOR EACH ROW EXECUTE FUNCTION public.bump_tenant_realtime_version()',
                v_table
            );
        END IF;
    END LOOP;
END;
$$;
