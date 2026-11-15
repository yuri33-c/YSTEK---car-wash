<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Model\Tenant;
use App\Service\AuditService;
use App\Service\PlanCatalog;
use App\Service\SubscriptionService;

/**
 * Gestão comercial das assinaturas pelo SUPER_ADMIN.
 *
 * A tela não processa pagamentos com gateway; ela organiza o ciclo comercial
 * já disponível no banco e permite registrar renovações manuais enquanto uma
 * integração de cobrança externa não estiver configurada.
 */
class SubscriptionsController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function redirect(): void
    {
        header('Location: index.php?class=SubscriptionsController');
        exit;
    }

    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
        $action = (string)($_POST['action'] ?? '');
        if ($action !== 'renew_subscription') return;

        $tenantId = trim((string)($_POST['tenant_id'] ?? ''));
        $days = max(1, min(366, (int)($_POST['days'] ?? 30)));
        $method = trim((string)($_POST['method'] ?? 'Manual / Pix'));
        $allowedMethods = ['Manual / Pix', 'Cartão', 'Dinheiro'];
        if (!in_array($method, $allowedMethods, true)) {
            $method = 'Manual / Pix';
        }

        try {
            if ($tenantId === '') throw new \Exception('Empresa inválida.');
            TTransaction::open();
            $pdo = TTransaction::get();
            $tenant = new Tenant($tenantId);
            if (!$tenant->id) throw new \Exception('Empresa não encontrada.');
            // A conta interna nunca participa de renovações comerciais de clientes.
            \App\Service\TenantAccessService::ensureNotSystemTenant($tenantId);
            $result = SubscriptionService::renew($pdo, $tenantId, $days, null, $method);
            $amount = (float)$result['amount'];
            $tenant->status = 'ativo';
            $tenant->plan = PlanCatalog::PREMIUM;
            $tenant->due_date = $result['due_date'];
            $tenant->start_date = $tenant->start_date ?: date('Y-m-d');
            $tenant->store();
            AuditService::log('SUBSCRIPTION_RENEWED', 'tenants', $tenantId, [
                'days' => $days,
                'amount' => $amount,
                'method' => $method,
                'due_date' => $result['due_date'],
            ], $tenantId);
            TTransaction::close();
            TMessage::add('success', 'Assinatura renovada até ' . date('d/m/Y', strtotime($result['due_date'])) . '.');
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Subscription renewal error: ' . $e->getMessage());
            TMessage::add('error', 'Não foi possível renovar a assinatura. ' . $e->getMessage());
        }
        $this->redirect();
    }

    public function show()
    {
        TTransaction::open();
        $tenants = Tenant::getCustomerObjects();
        $pdo = TTransaction::get();
        TTransaction::close();

        $rows = '';
        $metrics = ['active' => 0, 'grace' => 0, 'expired' => 0, 'suspended' => 0];
        foreach ($tenants as $tenant) {
            TTransaction::open();
            $sub = SubscriptionService::current(TTransaction::get(), (string)$tenant->id);
            TTransaction::close();
            $state = SubscriptionService::state($sub, (string)$tenant->status);
            $metrics[$state] = ($metrics[$state] ?? 0) + 1;
            $days = SubscriptionService::daysToDue($sub);
            $stateLabels = ['active' => 'Ativa', 'grace' => 'Em carência', 'expired' => 'Vencida', 'suspended' => 'Suspensa', 'canceled' => 'Cancelada'];
            $stateClass = $state === 'active' ? 'badge-concluido' : ($state === 'grace' ? 'badge-pendente' : 'badge-cancelado');
            $amount = 'R$ ' . number_format((float)($sub['amount'] ?? PlanCatalog::price($tenant->plan)), 2, ',', '.');
            $due = !empty($sub['due_date']) ? date('d/m/Y', strtotime($sub['due_date'])) : '—';
            $renewForm = '<form method="POST" action="index.php?class=SubscriptionsController" class="inline-renew-form">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="renew_subscription"><input type="hidden" name="tenant_id" value="' . $this->e($tenant->id) . '"><select name="days" class="form-control form-control-sm"><option value="30">30 dias</option><option value="90">90 dias</option><option value="365">1 ano</option></select><select name="method" class="form-control form-control-sm"><option>Manual / Pix</option><option>Cartão</option><option>Dinheiro</option></select><button class="btn btn-primary btn-sm" type="submit">Renovar</button></form>';
            $rows .= '<tr><td><strong>' . $this->e($tenant->name) . '</strong><div class="table-secondary">' . $this->e($tenant->email) . '</div></td><td><span class="plan-pill">' . $this->e(PlanCatalog::normalize($sub['plan'] ?? $tenant->plan)) . '</span></td><td><span class="badge ' . $stateClass . '">' . $this->e($stateLabels[$state] ?? ucfirst($state)) . '</span></td><td>' . $this->e($due) . '<div class="table-secondary">' . ($days >= 0 ? $days . ' dia(s)' : abs($days) . ' dia(s) vencido') . '</div></td><td>' . $this->e($amount) . '</td><td>' . $renewForm . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="6" class="empty-state"><strong>Nenhuma empresa cadastrada</strong><span>Cadastre uma empresa para iniciar a cobrança.</span></td></tr>';

        echo '<div class="page-heading"><div><div class="eyebrow">Gestão comercial</div><h1>Assinaturas</h1><p>Acompanhe vencimentos e registre renovações do plano Premium.</p></div><div class="heading-actions"><a class="btn btn-primary" href="index.php?class=TenantController&action=new">+ Nova empresa</a></div></div>
        <div class="metrics-grid"><div class="metric-card"><div class="metric-title">Ativas</div><div class="metric-value metric-success">' . (int)$metrics['active'] . '</div><div class="metric-sub">Acesso liberado</div></div><div class="metric-card"><div class="metric-title">Em carência</div><div class="metric-value metric-warning">' . (int)$metrics['grace'] . '</div><div class="metric-sub">Até 3 dias após o vencimento</div></div><div class="metric-card"><div class="metric-title">Vencidas</div><div class="metric-value metric-danger">' . (int)$metrics['expired'] . '</div><div class="metric-sub">Acesso bloqueado</div></div><div class="metric-card"><div class="metric-title">Suspensas</div><div class="metric-value">' . (int)$metrics['suspended'] . '</div><div class="metric-sub">Bloqueio manual</div></div></div>
        <div class="panel"><div class="panel-header"><div><div class="panel-title">Carteira de assinaturas</div><div class="panel-subtitle">Todos os clientes utilizam Premium; renovações aplicam o preço atual de ' . $this->e(PlanCatalog::priceFormatted(PlanCatalog::PREMIUM)) . '.</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system subscription-table"><thead><tr><th>Empresa</th><th>Plano</th><th>Situação</th><th>Vencimento</th><th>Valor</th><th>Ações</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>
        <div class="info-banner"><strong>Cobrança automática:</strong> esta versão já controla plano, vencimento, carência e renovação no banco. Para cobrança recorrente automática (Pix/cartão/webhook), será necessário configurar um provedor de pagamentos e suas credenciais de produção.</div>';
    }
}
