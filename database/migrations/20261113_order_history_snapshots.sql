-- Safe, additive history snapshots required before permanently deleting clients or services.
-- Run manually as the owner of the affected PostgreSQL tables; the application does not run migrations at request time.
BEGIN;

ALTER TABLE public.service_orders
    ADD COLUMN IF NOT EXISTS order_number INTEGER,
    ADD COLUMN IF NOT EXISTS client_name_snapshot VARCHAR(255),
    ADD COLUMN IF NOT EXISTS client_phone_snapshot VARCHAR(40),
    ADD COLUMN IF NOT EXISTS vehicle_plate_snapshot VARCHAR(20),
    ADD COLUMN IF NOT EXISTS vehicle_desc_snapshot VARCHAR(255);

ALTER TABLE public.service_order_items
    ADD COLUMN IF NOT EXISTS service_name_snapshot VARCHAR(100),
    ADD COLUMN IF NOT EXISTS service_description_snapshot TEXT;

UPDATE public.service_orders AS so
SET order_number = numbered.next_number
FROM (
    WITH tenant_max AS (
        SELECT tenant_id, COALESCE(MAX(order_number), 0) AS max_number
        FROM public.service_orders
        GROUP BY tenant_id
    ),
    missing_numbers AS (
        SELECT id, tenant_id,
               ROW_NUMBER() OVER (PARTITION BY tenant_id ORDER BY entry_time NULLS LAST, id) AS rn
        FROM public.service_orders
        WHERE order_number IS NULL
    )
    SELECT missing_numbers.id, tenant_max.max_number + missing_numbers.rn AS next_number
    FROM missing_numbers
    JOIN tenant_max ON tenant_max.tenant_id = missing_numbers.tenant_id
) AS numbered
WHERE so.id = numbered.id
  AND so.order_number IS NULL;

CREATE UNIQUE INDEX IF NOT EXISTS ux_service_orders_tenant_number
    ON public.service_orders (tenant_id, order_number)
    WHERE order_number IS NOT NULL;

UPDATE public.service_orders AS so
SET client_name_snapshot = COALESCE(so.client_name_snapshot, clients.name),
    client_phone_snapshot = COALESCE(so.client_phone_snapshot, clients.phone)
FROM public.clients AS clients
WHERE clients.id = so.client_id
  AND clients.tenant_id = so.tenant_id
  AND (so.client_name_snapshot IS NULL OR so.client_phone_snapshot IS NULL);

UPDATE public.service_orders AS so
SET vehicle_plate_snapshot = COALESCE(so.vehicle_plate_snapshot, vehicles.plate),
    vehicle_desc_snapshot = COALESCE(
        so.vehicle_desc_snapshot,
        NULLIF(TRIM(CONCAT_WS(' ', vehicles.brand, vehicles.model, vehicles.color)), '')
    )
FROM public.vehicles AS vehicles
WHERE vehicles.id = so.vehicle_id
  AND vehicles.tenant_id = so.tenant_id
  AND (so.vehicle_plate_snapshot IS NULL OR so.vehicle_desc_snapshot IS NULL);

UPDATE public.service_order_items AS item
SET service_name_snapshot = COALESCE(item.service_name_snapshot, services.name),
    service_description_snapshot = COALESCE(item.service_description_snapshot, services.description)
FROM public.services AS services
JOIN public.service_orders AS orders ON orders.tenant_id = services.tenant_id
WHERE item.service_id = services.id
  AND orders.id = item.service_order_id
  AND (item.service_name_snapshot IS NULL OR item.service_description_snapshot IS NULL);

COMMIT;
