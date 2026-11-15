<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\Tenant;
use App\Service\PlanCatalog;
use App\Service\SubscriptionService;

/**
 * Dashboard comercial do SUPER_ADMIN.
 *
 * Os indicadores são derivados exclusivamente da carteira de clientes reais,
 * do MRR configurado e dos estados de assinatura; a conta interna é excluída.
 */
class SaaSDashboardController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public function show()
    {
        TTransaction::open();
        $pdo = TTransaction::get();
        $tenants = Tenant::getCustomerObjects();
        $subByTenant = [];
        if ($tenants) {
            // Limita a leitura de assinaturas aos tenants clientes já filtrados.
            $tenantIds = array_map(fn($tenant) => (string)$tenant->id, $tenants);
            $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
            $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE tenant_id IN (' . $placeholders . ')');
            $stmt->execute($tenantIds);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $sub) $subByTenant[(string)$sub['tenant_id']] = $sub;
        }
        TTransaction::close();

        $total = count($tenants);
        $active = $grace = $expired = $suspended = $expiring = 0;
        $mrr = 0.0;
        $today = new \DateTimeImmutable('today');

        foreach ($tenants as $tenant) {
            $sub = $subByTenant[(string)$tenant->id] ?? [
                'plan' => $tenant->plan,
                'amount' => PlanCatalog::price($tenant->plan),
                'status' => strtolower((string)$tenant->status) === 'ativo' ? 'active' : 'suspended',
                'due_date' => $tenant->due_date,
            ];
            $state = SubscriptionService::state($sub, (string)$tenant->status);
            if ($state === 'active') $active++; elseif ($state === 'grace') $grace++; elseif ($state === 'expired') $expired++; else $suspended++;
            $plan = PlanCatalog::normalize($sub['plan'] ?? $tenant->plan);
            if ($state === 'active') $mrr += (float)($sub['amount'] ?? PlanCatalog::price($plan));
            if (!empty($sub['due_date'])) {
                try {
                    $days = (int)$today->diff(new \DateTimeImmutable((string)$sub['due_date']))->format('%r%a');
                    if ($days >= 0 && $days <= 7) $expiring++;
                } catch (\Throwable $ignore) {}
            }
        }

        usort($tenants, fn($a, $b) => strcmp((string)$b->created_at, (string)$a->created_at));
        $recent = array_slice($tenants, 0, 8);
        $rows = '';
        foreach ($recent as $tenant) {
            $sub = $subByTenant[(string)$tenant->id] ?? [
                'plan' => $tenant->plan,
                'amount' => PlanCatalog::price($tenant->plan),
                'status' => $tenant->status,
                'due_date' => $tenant->due_date,
            ];
            $state = SubscriptionService::state($sub, (string)$tenant->status);
            $labels = ['active'=>'Ativa','grace'=>'Carência','expired'=>'Vencida','suspended'=>'Suspensa','canceled'=>'Cancelada'];
            $class = $state === 'active' ? 'badge-concluido' : ($state === 'grace' ? 'badge-pendente' : 'badge-cancelado');
            $due = !empty($sub['due_date']) ? date('d/m/Y', strtotime((string)$sub['due_date'])) : '—';
            $days = SubscriptionService::daysToDue($sub);
            $timing = $days >= 0 ? 'faltam ' . $days . ' dia(s)' : 'há ' . abs($days) . ' dia(s)';
            $rows .= '<tr><td><strong>' . $this->e($tenant->name) . '</strong><div class="table-secondary">' . $this->e($tenant->email) . '</div></td><td><span class="plan-pill">' . $this->e(PlanCatalog::normalize($sub['plan'] ?? $tenant->plan)) . '</span></td><td><span class="badge ' . $class . '">' . $this->e($labels[$state] ?? $state) . '</span></td><td>' . $this->e($due) . '<div class="table-secondary">' . $timing . '</div></td><td><strong>R$ ' . number_format((float)($sub['amount'] ?? 0), 2, ',', '.') . '</strong></td><td><a class="btn btn-secondary btn-sm" href="index.php?class=TenantController&action=edit&id=' . rawurlencode((string)$tenant->id) . '">Abrir</a></td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="6" class="empty-state"><strong>Nenhuma empresa cadastrada</strong><span>Cadastre a primeira unidade para iniciar sua carteira.</span></td></tr>';

        echo '<div class="page-heading"><div><div class="eyebrow">Administração YSTEK</div><h1>Dashboard SaaS</h1><p>Acompanhe sua carteira de clientes, receita recorrente e vencimentos.</p></div><div class="heading-actions"><a class="btn btn-secondary" href="index.php?class=PlansController">Ver plano</a><a class="btn btn-primary" href="index.php?class=TenantController&action=new">+ Nova empresa</a></div></div>
        <div class="metrics-grid saas-metrics"><div class="metric-card"><div class="metric-title">Empresas</div><div class="metric-value">' . $total . '</div><div class="metric-sub">Carteira total</div></div><div class="metric-card"><div class="metric-title">Ativas</div><div class="metric-value metric-success">' . $active . '</div><div class="metric-sub">Acesso liberado</div></div><div class="metric-card"><div class="metric-title">MRR configurado</div><div class="metric-value metric-accent">R$ ' . number_format($mrr, 2, ',', '.') . '</div><div class="metric-sub">Soma mensal dos planos ativos</div></div><div class="metric-card"><div class="metric-title">Vencem em 7 dias</div><div class="metric-value metric-warning">' . $expiring . '</div><div class="metric-sub">Ação de renovação</div></div><div class="metric-card"><div class="metric-title">Em carência</div><div class="metric-value metric-warning">' . $grace . '</div><div class="metric-sub">3 dias após o vencimento</div></div><div class="metric-card"><div class="metric-title">Vencidas</div><div class="metric-value metric-danger">' . $expired . '</div><div class="metric-sub">Acesso bloqueado</div></div></div>
        <div class="dashboard-columns"><div class="panel"><div class="panel-header"><div><div class="panel-title">Carteira recente</div><div class="panel-subtitle">Últimas unidades cadastradas na plataforma.</div></div><a class="btn btn-secondary btn-sm" href="index.php?class=TenantController">Ver todas</a></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Empresa</th><th>Plano</th><th>Situação</th><th>Vencimento</th><th>Mensalidade</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div><div class="panel quick-panel"><div class="panel-header"><div class="panel-title">Resumo da carteira</div></div><div class="fin-summary"><div class="fin-line"><span>Empresas no Premium</span><strong>' . $total . '</strong></div><div class="fin-line"><span>Suspensas</span><strong>' . $suspended . '</strong></div><div class="fin-line"><span>Vencidas</span><strong>' . $expired . '</strong></div></div><div class="panel-body"><a class="quick-link" href="index.php?class=SubscriptionsController"><strong>Gerenciar assinaturas</strong><span>Renove clientes, veja vencimentos e acompanhe o status comercial.</span></a></div></div></div>';
    }
}
