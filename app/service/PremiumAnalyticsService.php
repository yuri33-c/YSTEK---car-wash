<?php
namespace App\Service;

use PDO;

/**
 * Consolida os indicadores de gestão avançada incluídos no Premium.
 *
 * A classe trabalha somente com dados do tenant informado e não inventa
 * métricas: todos os números são calculados diretamente no PostgreSQL.
 */
final class PremiumAnalyticsService
{
    private function __construct() {}

    public static function dashboard(PDO $pdo, string $tenantId): array
    {
        $baseParams = [':tenant_id' => $tenantId];

        $ordersStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status IN ('Finalizado', 'Concluído')) AS completed,
                    COUNT(*) FILTER (WHERE status = 'Cancelado') AS canceled,
                    COALESCE(SUM(total_amount) FILTER (WHERE status IN ('Finalizado', 'Concluído')), 0) AS revenue
             FROM service_orders
             WHERE tenant_id = :tenant_id
               AND entry_time >= CURRENT_DATE - INTERVAL '29 days'"
        );
        $ordersStmt->execute($baseParams);
        $orders = $ordersStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $completed = (int)($orders['completed'] ?? 0);
        $revenue = (float)($orders['revenue'] ?? 0);
        $totalOrders = (int)($orders['total'] ?? 0);
        $canceled = (int)($orders['canceled'] ?? 0);

        $serviceSnapshot = RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_name_snapshot')
            ? 'soi.service_name_snapshot'
            : 'NULL::TEXT';
        $serviceName = "COALESCE(NULLIF(s.name, ''), NULLIF({$serviceSnapshot}, ''), 'Serviço removido')";
        $serviceStmt = $pdo->prepare(
            "SELECT {$serviceName} AS name,
                    COUNT(*) AS quantity,
                    COALESCE(SUM(soi.price), 0) AS revenue
             FROM service_order_items soi
             INNER JOIN service_orders so ON so.id = soi.service_order_id
             LEFT JOIN services s ON s.id = soi.service_id
             WHERE so.tenant_id = :tenant_id
               AND so.status IN ('Finalizado', 'Concluído')
               AND so.entry_time >= CURRENT_DATE - INTERVAL '29 days'
             GROUP BY {$serviceName}
             ORDER BY revenue DESC, quantity DESC
             LIMIT 5"
        );
        $serviceStmt->execute($baseParams);
        $topServices = $serviceStmt->fetchAll(PDO::FETCH_ASSOC);

        $washerStmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(so.washer_name_snapshot, ''), NULLIF(u.name, ''), 'Sem lavador') AS name,
                    COUNT(*) AS completed_orders,
                    COALESCE(SUM(so.washer_commission_amount), 0) AS commission
             FROM service_orders so
             LEFT JOIN users u ON u.id = so.washer_id
             WHERE so.tenant_id = :tenant_id
               AND so.status IN ('Finalizado', 'Concluído')
               AND so.entry_time >= CURRENT_DATE - INTERVAL '29 days'
             GROUP BY COALESCE(NULLIF(so.washer_name_snapshot, ''), NULLIF(u.name, ''), 'Sem lavador')
             ORDER BY completed_orders DESC, commission DESC
             LIMIT 5"
        );
        $washerStmt->execute($baseParams);
        $topWashers = $washerStmt->fetchAll(PDO::FETCH_ASSOC);

        $paymentStmt = $pdo->prepare(
            "SELECT COALESCE(NULLIF(TRIM(payment_method), ''), 'Não informado') AS method,
                    COUNT(*) AS quantity,
                    COALESCE(SUM(total_amount), 0) AS revenue
             FROM service_orders
             WHERE tenant_id = :tenant_id
               AND status IN ('Finalizado', 'Concluído')
               AND entry_time >= CURRENT_DATE - INTERVAL '29 days'
             GROUP BY COALESCE(NULLIF(TRIM(payment_method), ''), 'Não informado')
             ORDER BY revenue DESC"
        );
        $paymentStmt->execute($baseParams);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);

        $completionRate = $totalOrders > 0 ? round(($completed / $totalOrders) * 100, 1) : 0.0;
        $avgTicket = $completed > 0 ? $revenue / $completed : 0.0;
        $cancellationRate = $totalOrders > 0 ? round(($canceled / $totalOrders) * 100, 1) : 0.0;

        return [
            'period_days' => 30,
            'revenue' => $revenue,
            'completed_orders' => $completed,
            'total_orders' => $totalOrders,
            'canceled_orders' => $canceled,
            'completion_rate' => $completionRate,
            'cancellation_rate' => $cancellationRate,
            'avg_ticket' => $avgTicket,
            'top_services' => $topServices,
            'top_washers' => $topWashers,
            'payments' => $payments,
        ];
    }
}
