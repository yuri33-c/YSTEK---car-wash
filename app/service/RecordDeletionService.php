<?php
namespace App\Service;

use PDO;
use RuntimeException;

final class RecordDeletionService
{
    private function __construct() {}

    public static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $allowedTables = ['clients', 'service_orders', 'service_order_items'];
        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException('Tabela inválida para verificação de schema.');
        }

        $stmt = $pdo->prepare("SELECT 1 FROM information_schema.columns
            WHERE table_schema = 'public' AND table_name = :table AND column_name = :column
            LIMIT 1");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (bool)$stmt->fetchColumn();
    }

    public static function deleteClient(PDO $pdo, string $tenantId, string $clientId): void
    {
        $stmt = $pdo->prepare('SELECT id, name, phone FROM public.clients
            WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE');
        $stmt->execute([':id' => $clientId, ':tenant_id' => $tenantId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            throw new RuntimeException('Cliente não encontrado para esta empresa.');
        }

        $crossTenant = $pdo->prepare('SELECT CASE WHEN
            EXISTS (SELECT 1 FROM public.vehicles WHERE client_id = :vehicle_id AND tenant_id IS DISTINCT FROM :vehicle_tenant)
            OR EXISTS (SELECT 1 FROM public.service_orders WHERE client_id = :order_id AND tenant_id IS DISTINCT FROM :order_tenant)
            THEN 1 ELSE 0 END');
        $crossTenant->execute([
            ':vehicle_id' => $clientId,
            ':vehicle_tenant' => $tenantId,
            ':order_id' => $clientId,
            ':order_tenant' => $tenantId,
        ]);
        if ((int)$crossTenant->fetchColumn() === 1) {
            throw new RuntimeException('Há referências de outra empresa para este cliente; a exclusão foi cancelada para preservar o isolamento entre empresas.');
        }

        $hasOrderHistory = $pdo->prepare('SELECT CASE WHEN EXISTS (
            SELECT 1 FROM public.service_orders orders
            WHERE orders.tenant_id = :tenant_id
              AND (orders.client_id = :client_id OR orders.vehicle_id IN (
                  SELECT id FROM public.vehicles WHERE tenant_id = :vehicle_tenant AND client_id = :vehicle_client
              ))
        ) THEN 1 ELSE 0 END');
        $hasOrderHistory->execute([
            ':tenant_id' => $tenantId,
            ':client_id' => $clientId,
            ':vehicle_tenant' => $tenantId,
            ':vehicle_client' => $clientId,
        ]);
        if ((int)$hasOrderHistory->fetchColumn() === 1
            && (!self::hasColumn($pdo, 'service_orders', 'client_name_snapshot')
                || !self::hasColumn($pdo, 'service_orders', 'client_phone_snapshot'))) {
            throw new RuntimeException('As ordens deste cliente não possuem campos para preservar sua identificação histórica. O proprietário do banco precisa aplicar database/migrations/20261113_order_history_snapshots.sql antes da exclusão.');
        }

        $orderColumns = [];
        foreach (['client_name_snapshot', 'client_phone_snapshot'] as $column) {
            if (self::hasColumn($pdo, 'service_orders', $column)) {
                $orderColumns[] = $column;
            }
        }
        if ($orderColumns) {
            $sets = [];
            $params = [':tenant_id' => $tenantId, ':client_id' => $clientId];
            if (in_array('client_name_snapshot', $orderColumns, true)) {
                $sets[] = 'client_name_snapshot = COALESCE(client_name_snapshot, :client_name)';
                $params[':client_name'] = $client['name'];
            }
            if (in_array('client_phone_snapshot', $orderColumns, true)) {
                $sets[] = 'client_phone_snapshot = COALESCE(client_phone_snapshot, :client_phone)';
                $params[':client_phone'] = $client['phone'];
            }
            $snapshot = $pdo->prepare('UPDATE public.service_orders SET ' . implode(', ', $sets) . '
                WHERE tenant_id = :tenant_id
                  AND (client_id = :client_id OR vehicle_id IN (
                      SELECT id FROM public.vehicles WHERE tenant_id = :vehicle_tenant AND client_id = :vehicle_client
                  ))');
            $params[':vehicle_tenant'] = $tenantId;
            $params[':vehicle_client'] = $clientId;
            $snapshot->execute($params);
        }

        $pdo->prepare('UPDATE public.service_orders SET client_id = NULL
            WHERE tenant_id = :tenant_id AND client_id = :client_id')
            ->execute([':tenant_id' => $tenantId, ':client_id' => $clientId]);
        $pdo->prepare('UPDATE public.vehicles SET client_id = NULL
            WHERE tenant_id = :tenant_id AND client_id = :client_id')
            ->execute([':tenant_id' => $tenantId, ':client_id' => $clientId]);

        $delete = $pdo->prepare('DELETE FROM public.clients WHERE id = :id AND tenant_id = :tenant_id');
        $delete->execute([':id' => $clientId, ':tenant_id' => $tenantId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('O cliente não pôde ser excluído.');
        }
    }

    public static function deleteService(PDO $pdo, string $tenantId, string $serviceId): void
    {
        $stmt = $pdo->prepare('SELECT id, name, description FROM public.services
            WHERE id = :id AND tenant_id = :tenant_id FOR UPDATE');
        $stmt->execute([':id' => $serviceId, ':tenant_id' => $tenantId]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$service) {
            throw new RuntimeException('Serviço não encontrado para esta empresa.');
        }

        $crossTenant = $pdo->prepare('SELECT CASE WHEN EXISTS (
            SELECT 1 FROM public.service_order_items item
            LEFT JOIN public.service_orders orders ON orders.id = item.service_order_id
            WHERE item.service_id = :service_id
              AND (orders.id IS NULL OR orders.tenant_id IS DISTINCT FROM :tenant_id)
        ) THEN 1 ELSE 0 END');
        $crossTenant->execute([':service_id' => $serviceId, ':tenant_id' => $tenantId]);
        if ((int)$crossTenant->fetchColumn() === 1) {
            throw new RuntimeException('Há referências de outra empresa para este serviço; a exclusão foi cancelada para preservar o isolamento entre empresas.');
        }

        $hasOrderHistory = $pdo->prepare('SELECT CASE WHEN EXISTS (
            SELECT 1 FROM public.service_order_items item
            JOIN public.service_orders orders ON orders.id = item.service_order_id
            WHERE orders.tenant_id = :tenant_id AND item.service_id = :service_id
        ) THEN 1 ELSE 0 END');
        $hasOrderHistory->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);
        if ((int)$hasOrderHistory->fetchColumn() === 1
            && (!self::hasColumn($pdo, 'service_order_items', 'service_name_snapshot')
                || !self::hasColumn($pdo, 'service_order_items', 'service_description_snapshot'))) {
            throw new RuntimeException('As ordens vinculadas não possuem campos para preservar o nome e a descrição histórica do serviço. O proprietário do banco precisa aplicar database/migrations/20261113_order_history_snapshots.sql antes da exclusão.');
        }

        $itemColumns = [];
        foreach (['service_name_snapshot', 'service_description_snapshot'] as $column) {
            if (self::hasColumn($pdo, 'service_order_items', $column)) {
                $itemColumns[] = $column;
            }
        }
        if ($itemColumns) {
            $sets = [];
            $params = [':service_id' => $serviceId, ':tenant_id' => $tenantId];
            if (in_array('service_name_snapshot', $itemColumns, true)) {
                $sets[] = 'service_name_snapshot = COALESCE(item.service_name_snapshot, :service_name)';
                $params[':service_name'] = $service['name'];
            }
            if (in_array('service_description_snapshot', $itemColumns, true)) {
                $sets[] = 'service_description_snapshot = COALESCE(item.service_description_snapshot, :service_description)';
                $params[':service_description'] = $service['description'];
            }
            $snapshot = $pdo->prepare('UPDATE public.service_order_items AS item SET ' . implode(', ', $sets) . '
                FROM public.service_orders AS orders
                WHERE orders.id = item.service_order_id
                  AND orders.tenant_id = :tenant_id
                  AND item.service_id = :service_id');
            $snapshot->execute($params);
        }

        $pdo->prepare('UPDATE public.service_order_items AS item SET service_id = NULL
            FROM public.service_orders AS orders
            WHERE orders.id = item.service_order_id
              AND orders.tenant_id = :tenant_id
              AND item.service_id = :service_id')
            ->execute([':tenant_id' => $tenantId, ':service_id' => $serviceId]);

        $delete = $pdo->prepare('DELETE FROM public.services WHERE id = :id AND tenant_id = :tenant_id');
        $delete->execute([':id' => $serviceId, ':tenant_id' => $tenantId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('O serviço não pôde ser excluído.');
        }
    }

    public static function deleteTenant(PDO $pdo, string $tenantId): void
    {
        $lock = $pdo->prepare('SELECT id FROM public.tenants WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $tenantId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('Empresa não encontrada.');
        }

        $crossTenant = $pdo->prepare('SELECT CASE WHEN
            EXISTS (SELECT 1 FROM public.service_orders other_orders
                JOIN public.clients target_clients ON target_clients.id = other_orders.client_id
                WHERE target_clients.tenant_id = :client_tenant AND other_orders.tenant_id IS DISTINCT FROM :client_other)
            OR EXISTS (SELECT 1 FROM public.service_orders other_orders
                JOIN public.vehicles target_vehicles ON target_vehicles.id = other_orders.vehicle_id
                WHERE target_vehicles.tenant_id = :vehicle_tenant AND other_orders.tenant_id IS DISTINCT FROM :vehicle_other)
            OR EXISTS (SELECT 1 FROM public.service_order_items other_items
                JOIN public.services target_services ON target_services.id = other_items.service_id
                LEFT JOIN public.service_orders owning_order ON owning_order.id = other_items.service_order_id
                WHERE target_services.tenant_id = :service_tenant
                  AND (owning_order.id IS NULL OR owning_order.tenant_id IS DISTINCT FROM :service_other))
            OR EXISTS (SELECT 1 FROM public.service_orders other_orders
                JOIN public.users target_users ON target_users.id = other_orders.washer_id
                WHERE target_users.tenant_id = :washer_tenant AND other_orders.tenant_id IS DISTINCT FROM :washer_other)
            OR EXISTS (SELECT 1 FROM public.vehicle_checkins other_checkins
                JOIN public.users target_users ON target_users.id = other_checkins.created_by
                WHERE target_users.tenant_id = :checkin_user_tenant AND other_checkins.tenant_id IS DISTINCT FROM :checkin_other)
            OR EXISTS (SELECT 1 FROM public.vehicle_damages other_damages
                JOIN public.users target_users ON target_users.id = other_damages.created_by
                WHERE target_users.tenant_id = :damage_user_tenant AND other_damages.tenant_id IS DISTINCT FROM :damage_other)
            THEN 1 ELSE 0 END');
        $crossTenant->execute([
            ':client_tenant' => $tenantId,
            ':client_other' => $tenantId,
            ':vehicle_tenant' => $tenantId,
            ':vehicle_other' => $tenantId,
            ':service_tenant' => $tenantId,
            ':service_other' => $tenantId,
            ':washer_tenant' => $tenantId,
            ':washer_other' => $tenantId,
            ':checkin_user_tenant' => $tenantId,
            ':checkin_other' => $tenantId,
            ':damage_user_tenant' => $tenantId,
            ':damage_other' => $tenantId,
        ]);
        if ((int)$crossTenant->fetchColumn() === 1) {
            throw new RuntimeException('Há registros de outra empresa vinculados aos dados desta empresa; a exclusão foi cancelada para preservar o isolamento.');
        }

        $pdo->prepare('DELETE FROM public.audit_logs WHERE tenant_id = :tenant_id')
            ->execute([':tenant_id' => $tenantId]);

        $pdo->prepare('DELETE FROM public.service_order_items
            WHERE service_order_id IN (SELECT id FROM public.service_orders WHERE tenant_id = :tenant_id)')
            ->execute([':tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM public.service_orders WHERE tenant_id = :tenant_id')
            ->execute([':tenant_id' => $tenantId]);

        $delete = $pdo->prepare('DELETE FROM public.tenants WHERE id = :id');
        $delete->execute([':id' => $tenantId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('A empresa não pôde ser excluída.');
        }
    }
}
