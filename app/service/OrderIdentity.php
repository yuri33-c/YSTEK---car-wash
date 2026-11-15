<?php
namespace App\Service;

use Adianti\Database\TTransaction;
use PDO;

/**
 * Identidade das ordens de serviço.
 *
 * 1) Número curto por empresa (#0001, #0002...) no lugar do UUID extenso.
 * 2) "Retrato" do cliente e do veículo gravado na própria O.S., para que o
 *    histórico continue legível mesmo depois que o cadastro for excluído.
 *
 * As colunas opcionais são instaladas por migração manual; as consultas continuam
 * compatíveis com bancos que ainda não receberam esses campos.
 */
class OrderIdentity
{
    private const COLUMNS = [
        'order_number' => 'INTEGER',
        'client_name_snapshot' => 'VARCHAR(255)',
        'client_phone_snapshot' => 'VARCHAR(40)',
        'vehicle_plate_snapshot' => 'VARCHAR(20)',
        'vehicle_desc_snapshot' => 'VARCHAR(255)',
    ];

    /** Compatibilidade para o utilitário de migração manual. */
    public static function ensureSchema(PDO $pdo): void
    {
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'service_orders'");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(array_keys(self::COLUMNS), $existing);
        if (!$missing) return;

        foreach ($missing as $column) {
            $pdo->exec('ALTER TABLE service_orders ADD COLUMN IF NOT EXISTS ' . $column . ' ' . self::COLUMNS[$column]);
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_service_orders_tenant_number ON service_orders (tenant_id, order_number) WHERE order_number IS NOT NULL');

        // Numeração das O.S. já existentes: ordem de entrada, separada por empresa.
        $pdo->exec('UPDATE service_orders so SET order_number = n.rn FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY tenant_id ORDER BY entry_time NULLS LAST, id) AS rn
                FROM service_orders
            ) n WHERE so.id = n.id AND so.order_number IS NULL');

        // Retrato das O.S. existentes cujos cadastros ainda existem.
        $pdo->exec("UPDATE service_orders so SET
                client_name_snapshot = (SELECT c.name FROM clients c WHERE c.id = so.client_id AND c.tenant_id = so.tenant_id),
                client_phone_snapshot = (SELECT c.phone FROM clients c WHERE c.id = so.client_id AND c.tenant_id = so.tenant_id),
                vehicle_plate_snapshot = (SELECT v.plate FROM vehicles v WHERE v.id = so.vehicle_id AND v.tenant_id = so.tenant_id),
                vehicle_desc_snapshot = (SELECT NULLIF(TRIM(CONCAT_WS(' ', v.brand, v.model, v.color)), '') FROM vehicles v WHERE v.id = so.vehicle_id AND v.tenant_id = so.tenant_id)
            WHERE so.client_name_snapshot IS NULL AND so.vehicle_desc_snapshot IS NULL");
    }

    /** Próximo número da empresa, sem colisão entre cadastros simultâneos. */
    public static function assignNumber(PDO $pdo, string $tenantId, string $orderId): void
    {
        if (!RecordDeletionService::hasColumn($pdo, 'service_orders', 'order_number')) {
            return;
        }
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:t))')->execute([':t' => 'os-number-' . $tenantId]);
        $pdo->prepare('UPDATE service_orders SET order_number = (SELECT COALESCE(MAX(order_number), 0) + 1 FROM service_orders WHERE tenant_id = :t)
                       WHERE id = :id AND tenant_id = :t AND order_number IS NULL')
            ->execute([':t' => $tenantId, ':id' => $orderId]);
    }

    /** Copia os dados atuais do cliente e do veículo para uma O.S. */
    public static function snapshotOrder(PDO $pdo, string $tenantId, string $orderId): void
    {
        $updates = [];
        foreach ([
            'client_name_snapshot' => "(SELECT c.name FROM clients c WHERE c.id = so.client_id AND c.tenant_id = so.tenant_id)",
            'client_phone_snapshot' => "(SELECT c.phone FROM clients c WHERE c.id = so.client_id AND c.tenant_id = so.tenant_id)",
            'vehicle_plate_snapshot' => "(SELECT v.plate FROM vehicles v WHERE v.id = so.vehicle_id AND v.tenant_id = so.tenant_id)",
            'vehicle_desc_snapshot' => "(SELECT NULLIF(TRIM(CONCAT_WS(' ', v.brand, v.model, v.color)), '') FROM vehicles v WHERE v.id = so.vehicle_id AND v.tenant_id = so.tenant_id)",
        ] as $column => $expression) {
            if (RecordDeletionService::hasColumn($pdo, 'service_orders', $column)) {
                $updates[] = $column . ' = COALESCE(' . $expression . ', so.' . $column . ')';
            }
        }
        if (!$updates) {
            return;
        }
        $pdo->prepare('UPDATE service_orders so SET ' . implode(', ', $updates) . '
            WHERE so.id = :id AND so.tenant_id = :t')
            ->execute([':id' => $orderId, ':t' => $tenantId]);
    }

    /** Antes de excluir um cliente: congela os dados em todas as O.S. dele e dos veículos dele. */
    public static function snapshotForClient(PDO $pdo, string $tenantId, string $clientId): void
    {
        $ids = $pdo->prepare('SELECT so.id FROM service_orders so
                WHERE so.tenant_id = :t AND (so.client_id = :c OR so.vehicle_id IN (SELECT id FROM vehicles WHERE client_id = :c AND tenant_id = :t))');
        $ids->execute([':t' => $tenantId, ':c' => $clientId]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            self::snapshotOrder($pdo, $tenantId, (string)$orderId);
        }
    }

    /** Antes de excluir um veículo: congela os dados nas O.S. dele. */
    public static function snapshotForVehicle(PDO $pdo, string $tenantId, string $vehicleId): void
    {
        $ids = $pdo->prepare('SELECT id FROM service_orders WHERE tenant_id = :t AND vehicle_id = :v');
        $ids->execute([':t' => $tenantId, ':v' => $vehicleId]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            self::snapshotOrder($pdo, $tenantId, (string)$orderId);
        }
    }


    /** Mapas id => dados dos cadastros atuais da empresa (uma consulta por tabela). */
    public static function maps(PDO $pdo, string $tenantId): array
    {
        $maps = ['clients' => [], 'vehicles' => []];
        $st = $pdo->prepare('SELECT id, name, phone FROM clients WHERE tenant_id = :t');
        $st->execute([':t' => $tenantId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $maps['clients'][(string)$r['id']] = $r;
        $st = $pdo->prepare('SELECT id, plate, brand, model, color FROM vehicles WHERE tenant_id = :t');
        $st->execute([':t' => $tenantId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $maps['vehicles'][(string)$r['id']] = $r;
        return $maps;
    }

    /** Cliente e veículo de uma O.S.: cadastro atual quando existe; senão o retrato gravado. */
    public static function resolve($order, array $maps): array
    {
        $get = static function ($k) use ($order) { return is_array($order) ? ($order[$k] ?? null) : ($order->$k ?? null); };
        $c = $maps['clients'][(string)$get('client_id')] ?? null;
        $v = $maps['vehicles'][(string)$get('vehicle_id')] ?? null;
        $snapName = trim((string)$get('client_name_snapshot'));
        $snapPlate = trim((string)$get('vehicle_plate_snapshot'));
        $snapDesc = trim((string)$get('vehicle_desc_snapshot'));
        return [
            'client' => $c ? (string)$c['name'] : $snapName,
            'phone' => $c ? (string)($c['phone'] ?? '') : trim((string)$get('client_phone_snapshot')),
            'plate' => $v ? (string)$v['plate'] : $snapPlate,
            'vehicle' => $v ? trim($v['brand'] . ' ' . $v['model']) : $snapDesc,
            'client_removed' => !$c && $snapName !== '',
            'vehicle_removed' => !$v && ($snapPlate !== '' || $snapDesc !== ''),
        ];
    }

    /** Rótulo curto da O.S.: #0007 (ou, se ainda sem número, os 8 primeiros caracteres do código). */
    public static function label($order): string
    {
        $number = is_array($order) ? ($order['order_number'] ?? null) : ($order->order_number ?? null);
        if ($number !== null && $number !== '') return '#' . str_pad((string)(int)$number, 4, '0', STR_PAD_LEFT);
        $id = is_array($order) ? ($order['id'] ?? '') : ($order->id ?? '');
        return '#' . substr((string)$id, 0, 8);
    }

    /** Cliente da O.S.: cadastro atual se existir; senão o retrato gravado. */
    public static function clientName($order, ?string $live): string
    {
        $snap = is_array($order) ? ($order['client_name_snapshot'] ?? '') : ($order->client_name_snapshot ?? '');
        $name = trim((string)($live ?? ''));
        return $name !== '' ? $name : (trim((string)$snap) !== '' ? trim((string)$snap) : '');
    }
}
