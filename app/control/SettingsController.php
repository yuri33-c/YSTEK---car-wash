<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Model\Tenant;
use App\Service\PlanCatalog;
use App\Service\PlanGuard;

/**
 * Configurações da unidade.
 *
 * Todo plano possui configurações básicas da empresa. O Premium acrescenta
 * meta mensal de faturamento, utilizada no painel executivo.
 */
class SettingsController extends TPage
{
    private function tenantId(): string
    {
        $id = trim((string)($_SESSION['tenant_id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('Sessão da empresa inválida. Faça login novamente.');
        }
        return $id;
    }

    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }



    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['action'] ?? '') !== 'save_settings') {
            return;
        }

        if (($_SESSION['role'] ?? '') !== 'ADMIN_TENANT') {
            TMessage::add('error', 'Somente o administrador da empresa pode alterar as configurações.');
            header('Location: index.php?class=SettingsController');
            exit;
        }

        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $tenantId = $this->tenantId();
            $plan = PlanGuard::currentPlan();

            $name = trim((string)($_POST['name'] ?? ''));
            $legalName = trim((string)($_POST['legal_name'] ?? ''));
            $document = trim((string)($_POST['document'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $cep = trim((string)($_POST['cep'] ?? ''));
            $street = trim((string)($_POST['street'] ?? ''));
            $number = trim((string)($_POST['number'] ?? ''));
            $neighborhood = trim((string)($_POST['neighborhood'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $state = strtoupper(trim((string)($_POST['state'] ?? '')));
            $message = trim((string)($_POST['whatsapp_message'] ?? ''));
            $commission = (float)str_replace(',', '.', (string)($_POST['default_commission_percent'] ?? '20'));
            $goal = (float)str_replace(',', '.', (string)($_POST['monthly_revenue_goal'] ?? '0'));

            if ($name === '') throw new \InvalidArgumentException('Informe o nome do lava-jato.');
            if (mb_strlen($message, 'UTF-8') > 2000) throw new \InvalidArgumentException('A mensagem do WhatsApp deve ter no máximo 2.000 caracteres.');
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Informe um e-mail válido.');
            if (strlen($state) > 2) throw new \InvalidArgumentException('UF inválida.');
            if ($commission < 0 || $commission > 100) throw new \InvalidArgumentException('A comissão padrão deve estar entre 0% e 100%.');
            if ($goal < 0 || $goal > 999999999.99) throw new \InvalidArgumentException('A meta mensal é inválida.');
            if (!PlanCatalog::allows($plan, 'revenue_goals')) $goal = 0.0;

            $tenant = new Tenant($tenantId);
            if (!$tenant->id) throw new \RuntimeException('Empresa não encontrada.');
            $tenant->name = $name;
            $tenant->legal_name = $legalName;
            $tenant->document = $document;
            $tenant->phone = $phone;
            $tenant->whatsapp = $whatsapp;
            $tenant->email = $email;
            $tenant->cep = $cep;
            $tenant->street = $street;
            $tenant->number = $number;
            $tenant->neighborhood = $neighborhood;
            $tenant->city = $city;
            $tenant->state = $state;
            $tenant->store();

            $stmt = $pdo->prepare('SELECT tenant_id FROM public.tenant_settings WHERE tenant_id = :tenant_id LIMIT 1');
            $stmt->execute([':tenant_id' => $tenantId]);
            if ($stmt->fetchColumn()) {
                $stmt = $pdo->prepare('UPDATE public.tenant_settings SET whatsapp_number=:whatsapp, whatsapp_message=:message, default_commission_percent=:commission, monthly_revenue_goal=:goal, updated_at=CURRENT_TIMESTAMP WHERE tenant_id=:tenant_id');
            } else {
                $stmt = $pdo->prepare('INSERT INTO public.tenant_settings (tenant_id, whatsapp_number, whatsapp_message, default_commission_percent, monthly_revenue_goal) VALUES (:tenant_id,:whatsapp,:message,:commission,:goal)');
            }
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':whatsapp' => $whatsapp,
                ':message' => $message,
                ':commission' => $commission,
                ':goal' => $goal,
            ]);

            TTransaction::close();
            TMessage::add('success', 'Configurações salvas com sucesso.');
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Settings error: ' . $e->getMessage());
            TMessage::add('error', 'Não foi possível salvar as configurações. ' . $e->getMessage());
        }
        header('Location: index.php?class=SettingsController');
        exit;
    }

    public function show()
    {
        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $tenantId = $this->tenantId();
            $tenant = new Tenant($tenantId);
            $stmt = $pdo->prepare('SELECT * FROM public.tenant_settings WHERE tenant_id = :tenant_id LIMIT 1');
            $stmt->execute([':tenant_id' => $tenantId]);
            $settings = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            TTransaction::close();
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Settings load error: ' . $e->getMessage());
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Não foi possível carregar as configurações.</strong><span>Atualize a página e tente novamente.</span></div></div>';
            return;
        }

        $plan = PlanGuard::currentPlan();
        $canEdit = (($_SESSION['role'] ?? '') === 'ADMIN_TENANT');
        $csrf = \App\Core\Security::csrfInput();
        $disabled = $canEdit ? '' : ' disabled';
        $goalBlock = '';
        // As metas são recursos do Premium; a validade da assinatura é checada pelo Auth.
        $goalBlock = '<div class="settings-section premium-setting"><div class="settings-section-title"><div><span class="settings-kicker">PREMIUM</span><h2>Metas de faturamento</h2><p>Acompanhe a meta mensal diretamente no painel executivo.</p></div><span class="settings-badge">RECURSO PREMIUM</span></div><div class="form-grid cols-2"><div class="form-group"><label>Meta mensal (R$)</label><input class="form-control" name="monthly_revenue_goal" value="' . $this->e(number_format((float)($settings['monthly_revenue_goal'] ?? 0), 2, ',', '.')) . '" inputmode="decimal"' . $disabled . '></div><div class="settings-note"><strong>Como funciona</strong><span>Ao definir uma meta, o Dashboard Premium apresenta o progresso do faturamento do mês e o valor ainda necessário para alcançar o objetivo.</span></div></div></div>';

        echo '<div class="page-heading"><div><div class="eyebrow">Configuração da unidade</div><h1>Configurações</h1><p>Centralize os dados do lava-jato, comunicação e regras operacionais.</p></div></div>
        <form method="POST" action="index.php?class=SettingsController" class="settings-form">' . $csrf . '<input type="hidden" name="action" value="save_settings">
        <div class="settings-grid"><div class="panel"><div class="panel-header"><div><div class="panel-title">Dados do lava-jato</div><div class="panel-subtitle">Essas informações identificam a sua empresa no sistema.</div></div></div><div class="panel-body"><div class="form-grid cols-2">
        <div class="form-group"><label>Nome do lava-jato<span class=req>&nbsp;*</span></label><input class="form-control" name="name" value="' . $this->e($tenant->name) . '" required' . $disabled . '></div>
        <div class="form-group"><label>Razão social</label><input class="form-control" name="legal_name" value="' . $this->e($tenant->legal_name) . '"' . $disabled . '></div>
        <div class="form-group"><label>CNPJ / CPF</label><input class="form-control" name="document" value="' . $this->e($tenant->document) . '"' . $disabled . '></div>
        <div class="form-group"><label>E-mail da empresa</label><input class="form-control" name="email" value="' . $this->e($tenant->email) . '"' . $disabled . '></div>
        <div class="form-group"><label>Telefone</label><input class="form-control" name="phone" value="' . $this->e($tenant->phone) . '"' . $disabled . '></div>
        <div class="form-group"><label>WhatsApp</label><input class="form-control" name="whatsapp" value="' . $this->e($settings['whatsapp_number'] ?? $tenant->whatsapp) . '" placeholder="(94) 99999-9999"' . $disabled . '></div>
        <div class="form-group"><label>CEP</label><input class="form-control" name="cep" value="' . $this->e($tenant->cep) . '"' . $disabled . '></div>
        <div class="form-group"><label>Rua</label><input class="form-control" name="street" value="' . $this->e($tenant->street) . '"' . $disabled . '></div>
        <div class="form-group"><label>Número</label><input class="form-control" name="number" value="' . $this->e($tenant->number) . '"' . $disabled . '></div>
        <div class="form-group"><label>Bairro</label><input class="form-control" name="neighborhood" value="' . $this->e($tenant->neighborhood) . '"' . $disabled . '></div>
        <div class="form-group"><label>Cidade</label><input class="form-control" name="city" value="' . $this->e($tenant->city) . '"' . $disabled . '></div>
        <div class="form-group"><label>Estado (UF)</label><input class="form-control" name="state" maxlength="2" value="' . $this->e($tenant->state) . '"' . $disabled . '></div>
        </div></div></div>
        <div class="panel"><div class="panel-header"><div><div class="panel-title">Comunicação e operação</div><div class="panel-subtitle">Defina o padrão usado nas mensagens e nas comissões.</div></div></div><div class="panel-body"><div class="form-group"><label>Mensagem padrão do WhatsApp</label><textarea class="form-control whatsapp-template-input" name="whatsapp_message" rows="8" maxlength="2000" placeholder="Olá, [NOME]! O serviço [SERVICO] do seu veículo [MODELO], placa [PLACA], foi concluído. Valor: [VALOR]."' . $disabled . '>' . $this->e($settings['whatsapp_message'] ?? '') . '</textarea><div class="field-help whatsapp-template-help"><strong>Campos automáticos:</strong> [NOME], [MARCA], [MODELO], [VEICULO], [PLACA], [SERVICO], [VALOR], [DATA], [HORA], [EMPRESA], [OS], [WHATSAPP_EMPRESA] e [ENDERECO]. Também aceita {{NOME}}. Se deixar em branco, o sistema usará uma mensagem padrão. A prévia será preenchida com os dados reais da ordem finalizada.</div></div><div class="form-group"><label>Comissão padrão do lavador (%)</label><input class="form-control" type="number" name="default_commission_percent" min="0" max="100" step="0.1" value="' . $this->e($settings['default_commission_percent'] ?? '20') . '"' . $disabled . '><div class="field-help">Usada como sugestão para novos lavadores; o percentual da O.S. é congelado no momento da conclusão.</div></div></div></div></div>' . $goalBlock . ($canEdit ? '<div class="settings-actions"><button type="submit" class="btn btn-primary btn-lg">Salvar configurações</button></div>' : '') . '</form>';
    }
}
