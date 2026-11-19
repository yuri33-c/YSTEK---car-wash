<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\ServiceOrder;
use App\Model\Client;
use App\Model\Vehicle;
use App\Model\FinancialRecord;
use App\Service\PlanCatalog;
use App\Service\OrderStatus;
use App\Service\PremiumAnalyticsService;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class DashboardController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public function show()
    {
        $tenantId = $_SESSION['tenant_id'];
        $plan = PlanCatalog::normalize($_SESSION['tenant_plan'] ?? PlanCatalog::PREMIUM);

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $monthStart = date('Y-m-01');
        // O desktop mantém a janela móvel dos últimos sete dias.
        // O celular recebe uma visão fixa da semana, de segunda a domingo.
        $sevenDaysStart = date('Y-m-d', strtotime('-6 days'));
        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $nextWeekStart = date('Y-m-d', strtotime($weekStart . ' +7 days'));
        $agendamentosHoje = 0;
        $aguardando = 0;
        $emServico = 0;
        $finalizadosHoje = 0;
        $faturamentoHoje = 0.0;
        $faturamentoMes = 0.0;
        $daily = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime('-' . $i . ' days'));
            $daily[$day] = 0.0;
        }
        $weekDaily = [];
        for ($i = 0; $i < 7; $i++) {
            $day = date('Y-m-d', strtotime($weekStart . ' +' . $i . ' days'));
            $weekDaily[$day] = 0.0;
        }
        $orders = [];
        $clientsCount = 0;
        $vehiclesCount = 0;
        $monthlyGoal = 0.0;

        // O painel não precisa carregar todo o histórico para calcular indicadores.
        // O PostgreSQL agrega os valores e entrega somente as seis O.S. mais recentes.
        TTransaction::open();
        try {
            $pdo = TTransaction::get();
            $statsStmt = $pdo->prepare("
                SELECT
                    COUNT(*) FILTER (WHERE entry_time >= :today_start AND entry_time < :today_end) AS today_total,
                    COUNT(*) FILTER (WHERE entry_time >= :today_start_wait AND entry_time < :today_end_wait AND status IN ('Agendado', 'Pendente', 'Aguardando')) AS waiting_total,
                    COUNT(*) FILTER (WHERE entry_time >= :today_start_service AND entry_time < :today_end_service AND status IN ('Em serviço', 'Em Andamento')) AS service_total,
                    COUNT(*) FILTER (WHERE entry_time >= :today_start_finished AND entry_time < :today_end_finished AND status IN ('Finalizado', 'Concluído')) AS finished_today,
                    COALESCE(SUM(total_amount) FILTER (WHERE entry_time >= :today_start_revenue AND entry_time < :today_end_revenue AND status IN ('Finalizado', 'Concluído')), 0) AS revenue_today,
                    COALESCE(SUM(total_amount) FILTER (WHERE entry_time >= :month_start AND status IN ('Finalizado', 'Concluído')), 0) AS revenue_month
                FROM public.service_orders
                WHERE tenant_id = :tenant_id
                  AND entry_time >= :month_floor
            ");
            $statsStmt->execute([
                ':tenant_id' => $tenantId,
                ':today_start' => $today . ' 00:00:00', ':today_end' => $tomorrow . ' 00:00:00',
                ':today_start_wait' => $today . ' 00:00:00', ':today_end_wait' => $tomorrow . ' 00:00:00',
                ':today_start_service' => $today . ' 00:00:00', ':today_end_service' => $tomorrow . ' 00:00:00',
                ':today_start_finished' => $today . ' 00:00:00', ':today_end_finished' => $tomorrow . ' 00:00:00',
                ':today_start_revenue' => $today . ' 00:00:00', ':today_end_revenue' => $tomorrow . ' 00:00:00',
                ':month_start' => $monthStart . ' 00:00:00', ':month_floor' => $monthStart . ' 00:00:00',
            ]);
            $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $agendamentosHoje = (int)($stats['today_total'] ?? 0);
            $aguardando = (int)($stats['waiting_total'] ?? 0);
            $emServico = (int)($stats['service_total'] ?? 0);
            $finalizadosHoje = (int)($stats['finished_today'] ?? 0);
            $faturamentoHoje = (float)($stats['revenue_today'] ?? 0);
            $faturamentoMes = (float)($stats['revenue_month'] ?? 0);

            $dailyStmt = $pdo->prepare("
                SELECT entry_time::date AS day, COALESCE(SUM(total_amount), 0) AS revenue
                FROM public.service_orders
                WHERE tenant_id = :tenant_id
                  AND entry_time >= :from_date
                  AND entry_time < :to_date
                  AND status IN ('Finalizado', 'Concluído')
                GROUP BY entry_time::date
            ");
            $dailyStmt->execute([
                ':tenant_id' => $tenantId,
                ':from_date' => $sevenDaysStart . ' 00:00:00',
                ':to_date' => $tomorrow . ' 00:00:00',
            ]);
            foreach ($dailyStmt->fetchAll(\PDO::FETCH_ASSOC) as $dailyRow) {
                $dayKey = (string)$dailyRow['day'];
                if (array_key_exists($dayKey, $daily)) {
                    $daily[$dayKey] = (float)$dailyRow['revenue'];
                }
            }

            // Agregação independente para a visão mobile fixa de segunda a domingo.
            $weekStmt = $pdo->prepare("
                SELECT entry_time::date AS day, COALESCE(SUM(total_amount), 0) AS revenue
                FROM public.service_orders
                WHERE tenant_id = :tenant_id
                  AND entry_time >= :from_date
                  AND entry_time < :to_date
                  AND status IN ('Finalizado', 'Concluído')
                GROUP BY entry_time::date
            ");
            $weekStmt->execute([
                ':tenant_id' => $tenantId,
                ':from_date' => $weekStart . ' 00:00:00',
                ':to_date' => $nextWeekStart . ' 00:00:00',
            ]);
            foreach ($weekStmt->fetchAll(\PDO::FETCH_ASSOC) as $weekRow) {
                $weekKey = (string)$weekRow['day'];
                if (array_key_exists($weekKey, $weekDaily)) {
                    $weekDaily[$weekKey] = (float)$weekRow['revenue'];
                }
            }

            $countStmt = $pdo->prepare("
                SELECT
                    (SELECT COUNT(*) FROM public.clients WHERE tenant_id = :clients_tenant) AS clients_count,
                    (SELECT COUNT(*) FROM public.vehicles WHERE tenant_id = :vehicles_tenant) AS vehicles_count
            ");
            $countStmt->execute([':clients_tenant' => $tenantId, ':vehicles_tenant' => $tenantId]);
            $counts = $countStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $clientsCount = (int)($counts['clients_count'] ?? 0);
            $vehiclesCount = (int)($counts['vehicles_count'] ?? 0);

            // Registros financeiros são consultados apenas quando não há receita de O.S.
            if ($faturamentoMes <= 0.0) {
                $fallbackStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(amount), 0)
                    FROM public.financial_records
                    WHERE tenant_id = :tenant_id
                      AND type = 'Receita'
                      AND COALESCE(status, '') <> 'Cancelado'
                      AND created_at >= :month_start
            ");
                $fallbackStmt->execute([
                    ':tenant_id' => $tenantId,
                    ':month_start' => $monthStart . ' 00:00:00',
                ]);
                $faturamentoMes = (float)$fallbackStmt->fetchColumn();
            }

            $todayOrdersStmt = $pdo->prepare("
                SELECT * FROM public.service_orders
                WHERE tenant_id = :tenant_id
                  AND entry_time >= :today_start
                  AND entry_time < :today_end
                ORDER BY entry_time DESC, id DESC
                LIMIT 6
            ");
            $todayOrdersStmt->execute([
                ':tenant_id' => $tenantId,
                ':today_start' => $today . ' 00:00:00',
                ':today_end' => $tomorrow . ' 00:00:00',
            ]);
            foreach ($todayOrdersStmt->fetchAll(\PDO::FETCH_ASSOC) as $orderRow) {
                $order = new ServiceOrder();
                $order->fromArray($orderRow);
                $orders[] = $order;
            }

            if (PlanCatalog::allows($plan, 'revenue_goals')) {
                $goalStmt = $pdo->prepare('SELECT monthly_revenue_goal FROM public.tenant_settings WHERE tenant_id = :tenant_id LIMIT 1');
                $goalStmt->execute([':tenant_id' => $tenantId]);
                $monthlyGoal = (float)($goalStmt->fetchColumn() ?: 0);
            }
        } finally {
            TTransaction::close();
        }

        $maxDay = max(1.0, max($daily));
        $bars = '';
        foreach ($daily as $day => $value) {
            $height = max(3, min(100, ($value / $maxDay) * 100));
            $bars .= '<div class="chart-col"><div class="chart-value">R$ ' . number_format($value, 2, ',', '.') . '</div><div class="chart-bar-area"><div class="chart-bar" style="height:' . $height . '%"></div></div><div class="chart-label">' . date('D', strtotime($day)) . '<br>' . date('d/m', strtotime($day)) . '</div></div>';
        }

        $maxWeekDay = max(1.0, max($weekDaily));
        $mobileBars = '';
        $weekdayMobileLabels = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
        foreach ($weekDaily as $day => $value) {
            $height = max(3, min(100, ($value / $maxWeekDay) * 100));
            $weekdayNumber = (int)date('N', strtotime($day));
            $weekdayMobile = $weekdayMobileLabels[$weekdayNumber] ?? '';
            if ($value >= 1000000) {
                $mobileValue = number_format($value / 1000000, 1, ',', '.') . 'mi';
            } elseif ($value >= 1000) {
                $mobileValue = number_format($value / 1000, 1, ',', '.') . 'k';
            } else {
                $mobileValue = number_format($value, 0, ',', '.');
            }
            $mobileBars .= '<div class="chart-col"><div class="chart-value">R$ ' . $mobileValue . '</div><div class="chart-bar-area"><div class="chart-bar" style="height:' . $height . '%"></div></div><div class="chart-label"><span class="chart-weekday-mobile">' . $weekdayMobile . '</span><span class="chart-date">' . date('d/m', strtotime($day)) . '</span></div></div>';
        }

        $todayFmt = 'R$ ' . number_format($faturamentoHoje, 2, ',', '.');
        $monthFmt = 'R$ ' . number_format($faturamentoMes, 2, ',', '.');
        $advanced = PlanCatalog::allows($plan, 'advanced_dashboard');
        $executive = PlanCatalog::allows($plan, 'executive_dashboard');
        $advancedData = null;
        if ($advanced) {
            // O bloco gerencial usa dados reais e é liberado pelo plano.
            try {
                TTransaction::open();
                $advancedData = PremiumAnalyticsService::dashboard(TTransaction::get(), (string)$tenantId);
                TTransaction::close();
            } catch (\Throwable $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Advanced dashboard warning: ' . $e->getMessage());
                $advancedData = null;
            }
        }

        $goalBlock = '';
        if ($monthlyGoal > 0) {
            $progress = min(100, round(($faturamentoMes / $monthlyGoal) * 100, 1));
            $remaining = max(0, $monthlyGoal - $faturamentoMes);
            $goalBlock = '<div class="panel goal-panel"><div class="panel-header"><div><div class="panel-title">Meta de faturamento</div><div class="panel-subtitle">Acompanhamento mensal Premium</div></div><span class="manager-badge">PREMIUM SaaS</span></div><div class="goal-content"><div class="goal-copy"><strong>R$ ' . number_format($faturamentoMes, 2, ',', '.') . ' <span>/ R$ ' . number_format($monthlyGoal, 2, ',', '.') . '</span></strong><small>' . number_format($progress, 1, ',', '.') . '% da meta alcançada</small></div><div class="goal-track"><span style="width:' . $progress . '%"></span></div><div class="goal-foot"><span>Falta R$ ' . number_format($remaining, 2, ',', '.') . ' para alcançar a meta.</span><a href="index.php?class=SettingsController">Ajustar meta</a></div></div></div>';
        }

        $ordersRows = '';
        $count = 0;
        foreach ($orders as $o) {
            if ($count >= 6) break;
            $entry = (string)$o->entry_time;
            if (substr($entry, 0, 10) !== $today) continue;
            $count++;
            $status = OrderStatus::normalize((string)$o->status);
            $stClass = OrderStatus::badgeClass($status);
            $ordersRows .= '<tr><td><strong>' . $this->e(\App\Service\OrderIdentity::label($o)) . '</strong></td><td><span class="badge ' . $stClass . '">' . $this->e($status) . '</span></td><td><strong>R$ ' . number_format((float)$o->total_amount, 2, ',', '.') . '</strong></td><td>' . date('H:i', strtotime($entry)) . '</td></tr>';
        }
        if ($ordersRows === '') $ordersRows = '<tr><td colspan="4" class="empty-state"><strong>Nenhum atendimento para hoje</strong><span>Os dados aparecerão aqui assim que houver movimentação.</span></td></tr>';

        $managerBlock = '';
        if ($advancedData !== null) {
            $managerBlock = '<section class="manager-section">'
                . '<div class="manager-section-head"><div><div class="eyebrow">Gestão gerencial</div><h2>' . ($executive ? 'Painel executivo Premium' : 'Indicadores gerenciais') . '</h2><p>Leitura consolidada dos últimos 30 dias, baseada nas O.S. reais desta empresa.</p></div><span class="manager-badge">' . ($executive ? 'PREMIUM SaaS' : 'PROFISSIONAL') . '</span></div>'
                . '<div class="executive-kpis">'
                . '<div class="exec-kpi"><span>Faturamento · 30 dias</span><strong>R$ ' . number_format((float)$advancedData['revenue'], 2, ',', '.') . '</strong><small>O.S. concluídas</small></div>'
                . '<div class="exec-kpi"><span>Ticket médio</span><strong>R$ ' . number_format((float)$advancedData['avg_ticket'], 2, ',', '.') . '</strong><small>por atendimento</small></div>'
                . '<div class="exec-kpi"><span>Taxa de conclusão</span><strong>' . number_format((float)$advancedData['completion_rate'], 1, ',', '.') . '%</strong><small>' . (int)$advancedData['completed_orders'] . ' concluídas</small></div>'
                . '<div class="exec-kpi"><span>Cancelamentos</span><strong>' . number_format((float)$advancedData['cancellation_rate'], 1, ',', '.') . '%</strong><small>' . (int)$advancedData['canceled_orders'] . ' canceladas</small></div>'
                . '</div>';

            if ($executive) {
                $serviceRows = '';
                foreach ($advancedData['top_services'] as $row) {
                    $serviceRows .= '<tr><td><strong>' . $this->e($row['name']) . '</strong></td><td>' . (int)$row['quantity'] . '</td><td><strong>R$ ' . number_format((float)$row['revenue'], 2, ',', '.') . '</strong></td></tr>';
                }
                if ($serviceRows === '') $serviceRows = '<tr><td colspan="3" class="empty-state">Sem serviços concluídos no período.</td></tr>';

                $washerRows = '';
                foreach ($advancedData['top_washers'] as $row) {
                    $washerRows .= '<tr><td><strong>' . $this->e($row['name']) . '</strong></td><td>' . (int)$row['completed_orders'] . '</td><td>R$ ' . number_format((float)$row['commission'], 2, ',', '.') . '</td></tr>';
                }
                if ($washerRows === '') $washerRows = '<tr><td colspan="3" class="empty-state">Nenhum lavador com O.S. concluída no período.</td></tr>';

                $paymentRows = '';
                foreach ($advancedData['payments'] as $row) {
                    $paymentRows .= '<tr><td><strong>' . $this->e($row['method']) . '</strong></td><td>' . (int)$row['quantity'] . '</td><td>R$ ' . number_format((float)$row['revenue'], 2, ',', '.') . '</td></tr>';
                }
                if ($paymentRows === '') $paymentRows = '<tr><td colspan="3" class="empty-state">Sem pagamentos concluídos no período.</td></tr>';

                $managerBlock .= '<div class="executive-grid">'
                    . '<div class="panel executive-panel"><div class="panel-header"><div><div class="panel-title">Serviços com maior faturamento</div><div class="panel-subtitle">Últimos 30 dias</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Serviço</th><th>O.S.</th><th>Faturamento</th></tr></thead><tbody>' . $serviceRows . '</tbody></table></div></div></div>'
                    . '<div class="panel executive-panel"><div class="panel-header"><div><div class="panel-title">Desempenho por lavador</div><div class="panel-subtitle">Atendimentos e comissão</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Lavador</th><th>Concluídas</th><th>Comissão</th></tr></thead><tbody>' . $washerRows . '</tbody></table></div></div></div>'
                    . '<div class="panel executive-panel"><div class="panel-header"><div><div class="panel-title">Recebimentos por método</div><div class="panel-subtitle">O.S. concluídas</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Método</th><th>O.S.</th><th>Total</th></tr></thead><tbody>' . $paymentRows . '</tbody></table></div></div></div>'
                    . '</div>';
            }
            $managerBlock .= '</section>';
        }

        echo '<div class="page-heading">
            <div><div class="eyebrow">Visão geral do dia</div><h1>Dashboard</h1><p>Operação do lava-jato em tempo real, com gestão por indicadores.</p></div>
            <div class="heading-actions"><a class="btn btn-secondary" href="index.php?class=AgendaController">Ver agenda</a><a class="btn btn-primary btn-lg" href="index.php?class=OrdersController&new=1">+ Novo Atendimento</a></div>
        </div>
        <div class="metrics-grid metrics-6">
            <div class="metric-card"><div class="metric-title">Agendamentos hoje</div><div class="metric-value">' . $agendamentosHoje . '</div><div class="metric-sub">Atendimentos do dia</div></div>
            <div class="metric-card"><div class="metric-title">Aguardando</div><div class="metric-value metric-warning">' . $aguardando . '</div><div class="metric-sub">Na fila</div></div>
            <div class="metric-card"><div class="metric-title">Em serviço</div><div class="metric-value metric-accent">' . $emServico . '</div><div class="metric-sub">Operação ativa</div></div>
            <div class="metric-card"><div class="metric-title">Finalizados hoje</div><div class="metric-value metric-success">' . $finalizadosHoje . '</div><div class="metric-sub">Lavagens concluídas</div></div>
            <div class="metric-card"><div class="metric-title">Faturamento hoje</div><div class="metric-value">' . $todayFmt . '</div><div class="metric-sub">Vendas concluídas</div></div>
            <div class="metric-card"><div class="metric-title">Faturamento do mês</div><div class="metric-value">' . $monthFmt . '</div><div class="metric-sub">Até o momento</div></div>
        </div>
        <div class="panel"><div class="panel-header"><div><div class="panel-title"><span class="chart-heading-desktop">Faturamento — últimos 7 dias</span><span class="chart-heading-mobile">Faturamento — semana atual</span></div><div class="panel-subtitle"><span class="chart-heading-desktop">Valores reais de atendimentos concluídos.</span><span class="chart-heading-mobile">De segunda a domingo · valores reais concluídos.</span></div></div></div><div class="dashboard-chart"><div class="chart-grid chart-grid-desktop">' . $bars . '</div><div class="chart-grid chart-grid-mobile">' . $mobileBars . '</div></div></div>
        ' . $managerBlock . $goalBlock . '
        <div class="dashboard-bottom">
            <div class="panel"><div class="panel-header"><div><div class="panel-title">Status dos atendimentos de hoje</div><div class="panel-subtitle">Acompanhe a operação em andamento.</div></div><a class="btn btn-secondary btn-sm" href="index.php?class=OrdersController">Ver O.S.</a></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>O.S. #</th><th>Status</th><th>Valor</th><th>Entrada</th></tr></thead><tbody>' . $ordersRows . '</tbody></table></div></div></div>
            <div class="panel"><div class="panel-header"><div class="panel-title">Resumo da base</div></div><div class="fin-summary"><div class="fin-line"><span>Clientes cadastrados</span><strong>' . $clientsCount . '</strong></div><div class="fin-line"><span>Veículos cadastrados</span><strong>' . $vehiclesCount . '</strong></div><div class="fin-line"><span>Plano atual</span><strong>' . $this->e($plan) . '</strong></div><div class="fin-line"><span>Recursos avançados</span><strong>' . ($advanced ? 'Ativos' : 'Não incluídos') . '</strong></div></div></div>
        </div>';
    }
}
