<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Model\ServiceOrder;
use App\Model\Client;
use App\Model\Vehicle;
use App\Model\Service;
use App\Service\OrderStatus;
use App\Service\OrderIdentity;
use App\Service\PlanGuard;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class ReportsController extends TPage
{
    private function redirectWithError(string $message): void
    {
        TMessage::add('error', $message);
        header('Location: index.php?class=DashboardController');
        exit;
    }

    public function process()
    {
        if (!PlanGuard::ensure('reports')) {
            header('Location: index.php?class=DashboardController');
            exit;
        }

        if (($_GET['action'] ?? '') === 'export') {
            $this->exportCsv();
        }
    }

    private function exportCsv(): void
    {
        $from = trim((string)($_GET['from'] ?? date('Y-m-01')));
        $to = trim((string)($_GET['to'] ?? date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $this->redirectWithError('Informe um período válido para exportação.');
        }

        TTransaction::open();
        $orders = ServiceOrder::getObjects(
            'tenant_id = :t AND entry_time::date BETWEEN :from AND :to',
            [':t' => $_SESSION['tenant_id'], ':from' => $from, ':to' => $to]
        );
        $maps = OrderIdentity::maps(TTransaction::get(), (string)$_SESSION['tenant_id']);
        TTransaction::close();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="ystek-relatorio-' . $from . '-a-' . $to . '.csv"');
        echo "sep=;\n";
        echo "OS;Cliente;Veículo;Placa;Status;Valor;Pagamento;Entrada;Conclusão\n";
        foreach ($orders as $order) {
            $who = OrderIdentity::resolve($order, $maps);
            $row = [OrderIdentity::label($order), $who['client'], $who['vehicle'], $who['plate'], OrderStatus::normalize((string)$order->status), number_format((float)$order->total_amount, 2, ',', '.'), $order->payment_status, $order->entry_time, $order->completion_time];
            echo implode(';', array_map(function ($v) { return '"' . str_replace('"', '""', (string)$v) . '"'; }, $row)) . "\n";
        }
        exit;
    }

    public function show()
    {
        if (!PlanGuard::allows('reports')) {
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Relatórios indisponíveis</strong><span>É necessário manter uma assinatura válida e ativa para consultar os relatórios.</span></div></div>';
            return;
        }

        $plan = PlanGuard::currentPlan();
        $isAdvanced = \App\Service\PlanCatalog::allows($plan, 'advanced_reports');
        $fromDefault = $isAdvanced ? date('Y-m-d', strtotime('-29 days')) : date('Y-m-01');
        $toDefault = date('Y-m-d');
        $from = trim((string)($_GET['from'] ?? $fromDefault));
        $to = trim((string)($_GET['to'] ?? $toDefault));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $fromDefault;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $toDefault;

        TTransaction::open();
        $tenantId = $_SESSION['tenant_id'];
        $orders = ServiceOrder::getObjects('tenant_id = :t AND entry_time::date BETWEEN :from AND :to', [':t' => $tenantId, ':from' => $from, ':to' => $to]);
        $clients = count(Client::getObjects('tenant_id = :t', [':t' => $tenantId]));
        $vehicles = count(Vehicle::getObjects('tenant_id = :t', [':t' => $tenantId]));
        $services = count(Service::getObjects('tenant_id = :t', [':t' => $tenantId]));
        $maps = OrderIdentity::maps(TTransaction::get(), (string)$tenantId);
        TTransaction::close();

        $total = 0.0; $completed = 0; $pending = 0; $inProgress = 0;
        foreach ($orders as $o) {
            $status = OrderStatus::normalize((string)$o->status);
            if ($status !== OrderStatus::CANCELED) $total += (float)$o->total_amount;
            if ($status === OrderStatus::FINISHED) $completed++;
            elseif ($status === OrderStatus::IN_SERVICE) $inProgress++;
            elseif ($status !== OrderStatus::CANCELED) $pending++;
        }

        $fmt = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
        $rows = '';
        foreach ($orders as $o) {
            $status = OrderStatus::normalize((string)$o->status);
            $class = OrderStatus::badgeClass($status);
            $who = OrderIdentity::resolve($o, $maps);
            $whoClient = htmlspecialchars($who['client'] !== '' ? $who['client'] : '—', ENT_QUOTES, 'UTF-8') . ($who['client_removed'] ? '<div class="table-secondary">Cadastro excluído</div>' : '');
            $whoVehicle = htmlspecialchars(trim($who['plate'] . ' · ' . $who['vehicle'], ' ·') ?: '—', ENT_QUOTES, 'UTF-8');
            $rows .= '<tr><td><strong>' . htmlspecialchars(OrderIdentity::label($o), ENT_QUOTES, 'UTF-8') . '</strong></td><td><strong>' . $whoClient . '</strong></td><td>' . $whoVehicle . '</td><td><span class="badge ' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span></td><td><strong>' . $fmt($o->total_amount) . '</strong></td><td>' . htmlspecialchars((string)$o->payment_status, ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(date('d/m/Y H:i', strtotime($o->entry_time)), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="7" class="empty-state"><strong>Nenhuma O.S. encontrada no período</strong><span>Escolha outra data ou volte para a operação.</span></td></tr>';

        $dateForm = $isAdvanced ? '<form method="GET" class="report-filter"><input type="hidden" name="class" value="ReportsController"><div><label>De</label><input type="date" name="from" class="form-control" value="' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . '"></div><div><label>Até</label><input type="date" name="to" class="form-control" value="' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '"></div><div class="report-filter-actions"><button class="btn btn-primary">Atualizar</button><a class="btn btn-secondary" href="index.php?class=ReportsController&action=export&from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . '">Exportar CSV</a></div></form>' : '<div class="report-period-note">Período do mês atual · ' . date('01/m/Y') . ' a ' . date('d/m/Y') . ' <a class="btn btn-secondary btn-sm" href="index.php?class=ReportsController&action=export&from=' . rawurlencode($from) . '&to=' . rawurlencode($to) . '">Exportar CSV</a></div>';

        echo '<div class="page-heading"><div><div class="eyebrow">Gestão & análise</div><h1>Relatórios</h1><p>Indicadores reais da operação, calculados a partir das ordens do seu lava-jato.</p></div><div class="heading-actions">' . $dateForm . '</div></div>
        <div class="metrics-grid">
            <div class="metric-card"><div class="metric-title">Faturamento do período</div><div class="metric-value metric-accent">' . $fmt($total) . '</div><div class="metric-sub">Somatório das O.S.</div></div>
            <div class="metric-card"><div class="metric-title">O.S. concluídas</div><div class="metric-value metric-success">' . $completed . '</div><div class="metric-sub">Serviços finalizados</div></div>
            <div class="metric-card"><div class="metric-title">Em andamento</div><div class="metric-value">' . $inProgress . '</div><div class="metric-sub">Operação atual</div></div>
            <div class="metric-card"><div class="metric-title">Clientes / Veículos</div><div class="metric-value">' . $clients . ' / ' . $vehicles . '</div><div class="metric-sub">Base cadastrada · ' . $services . ' serviços</div></div>
        </div>
        <div class="panel"><div class="panel-header"><div><div class="panel-title">Desempenho das Ordens de Serviço</div><div class="panel-subtitle">Plano ' . htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') . '</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>O.S.</th><th>Cliente</th><th>Veículo</th><th>Status</th><th>Valor</th><th>Pagamento</th><th>Entrada</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>
        <div class="report-footnote">O Premium permite consulta por período personalizado.</div>';
    }
}
