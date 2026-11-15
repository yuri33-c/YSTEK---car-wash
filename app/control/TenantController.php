<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\Tenant;
use Adianti\Widget\TMessage;
use App\Service\PlanCatalog;
use App\Service\SubscriptionService;
use App\Service\AuditService;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class TenantController extends TPage
{
    private function redirect(string $query = ''): void
    {
        header('Location: index.php?class=TenantController' . ($query ? '&' . $query : ''));
        exit;
    }

    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function validateEmail(string $email, string $label = 'E-mail'): void
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception('Informe um ' . strtolower($label) . ' válido.');
        }
    }

    private function ensureUniqueAdminEmail($pdo, string $email, ?string $ignoreUserId = null): void
    {
        $sql = 'SELECT id FROM users WHERE LOWER(email) = LOWER(:email)';
        $params = [':email' => strtolower($email)];
        if ($ignoreUserId) {
            $sql .= ' AND id <> :ignore_id';
            $params[':ignore_id'] = $ignoreUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new \Exception('Este e-mail já está vinculado a outro acesso do sistema.');
        }
    }

    private function normalizeDate(string $value, ?string $fallback = null): ?string
    {
        $value = trim($value);
        if ($value === '') return $fallback;
        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \Exception('Informe uma data válida.');
        }
        return $value;
    }

    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_tenant') {
            $isEdit = !empty($_POST['id']);
            $tenantIdParam = trim((string)($_POST['id'] ?? ''));

            $name = trim((string)($_POST['name'] ?? ''));
            $legalName = trim((string)($_POST['legal_name'] ?? ''));
            $document = trim((string)($_POST['document'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
            $companyEmail = trim((string)($_POST['company_email'] ?? ''));
            $cep = trim((string)($_POST['cep'] ?? ''));
            $street = trim((string)($_POST['street'] ?? ''));
            $number = trim((string)($_POST['number'] ?? ''));
            $neighborhood = trim((string)($_POST['neighborhood'] ?? ''));
            $city = trim((string)($_POST['city'] ?? ''));
            $state = strtoupper(trim((string)($_POST['state'] ?? '')));

            $adminName = trim((string)($_POST['admin_name'] ?? ''));
            $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
            $adminPhone = trim((string)($_POST['admin_phone'] ?? ''));
            $adminPassword = (string)($_POST['admin_password'] ?? '');
            $adminPasswordConfirm = (string)($_POST['admin_password_confirmation'] ?? '');

            // O catálogo é único: a escolha de plano é definida pelo servidor,
            // independentemente de campos adulterados ou enviados pelo navegador.
            $plan = PlanCatalog::PREMIUM;
            $status = trim((string)($_POST['status'] ?? 'ativo'));
            $startDate = $this->normalizeDate((string)($_POST['start_date'] ?? ''), date('Y-m-d'));
            $dueDate = $this->normalizeDate((string)($_POST['due_date'] ?? ''), date('Y-m-d', strtotime('+30 days')));

            try {
                if ($name === '') throw new \Exception('Informe o nome fantasia do lava-jato.');
                if ($companyEmail !== '') $this->validateEmail($companyEmail, 'e-mail da empresa');
                if ($document !== '') {
                    $normalizedDocument = preg_replace('/\D+/', '', $document);
                    if ($normalizedDocument !== '' && strlen($normalizedDocument) < 11) {
                        throw new \Exception('Informe um CPF/CNPJ válido ou deixe o campo em branco.');
                    }
                }
                if ($state !== '' && !preg_match('/^[A-Z]{2}$/', $state)) throw new \Exception('Informe a UF com duas letras.');
                if ($dueDate !== null && $startDate !== null && $dueDate < $startDate) throw new \Exception('A data de vencimento não pode ser anterior à data de início.');

                if (!$isEdit) {
                    $this->validateEmail($adminEmail, 'e-mail de acesso');
                    if ($adminPassword === '' || strlen($adminPassword) < 6) throw new \Exception('A senha de acesso deve ter pelo menos 6 caracteres.');
                    if (!hash_equals($adminPassword, $adminPasswordConfirm)) throw new \Exception('A confirmação da senha não confere.');
                } else {
                    if ($adminEmail !== '') $this->validateEmail($adminEmail, 'e-mail de acesso');
                    if ($adminPassword !== '' || $adminPasswordConfirm !== '') {
                        if (strlen($adminPassword) < 6) throw new \Exception('A nova senha deve ter pelo menos 6 caracteres.');
                        if (!hash_equals($adminPassword, $adminPasswordConfirm)) throw new \Exception('A confirmação da nova senha não confere.');
                    }
                }

                TTransaction::open();
                $pdo = TTransaction::get();
                $pdo->beginTransaction();

                $t = $isEdit ? new Tenant($tenantIdParam) : new Tenant();
                if ($isEdit && !$t->id) throw new \Exception('Empresa não encontrada.');
                if ($isEdit) {
                    \App\Service\TenantAccessService::ensureNotSystemTenant((string)$t->id);
                }

                $t->name = $name;
                $t->legal_name = $legalName;
                $t->document = $document;
                $t->phone = $phone;
                $t->whatsapp = $whatsapp;
                $t->email = $companyEmail;
                $t->cep = $cep;
                $t->street = $street;
                $t->number = $number;
                $t->neighborhood = $neighborhood;
                $t->city = $city;
                $t->state = $state;
                $t->plan = $plan;
                $t->status = in_array($status, ['ativo', 'suspenso'], true) ? $status : 'ativo';
                $t->start_date = $startDate;
                $t->due_date = $dueDate;
                $t->store();
                $tenantId = (string)$t->id;

                // A assinatura comercial acompanha o tenant e mantém plano, valor,
                // vencimento e carência separados do cadastro institucional.
                if ($isEdit) {
                    SubscriptionService::sync($pdo, $tenantId, $plan, $startDate, $dueDate, $t->status);
                } else {
                    SubscriptionService::create($pdo, $tenantId, $plan, $startDate, $dueDate);
                }

                $stmt = $pdo->prepare("SELECT id, email FROM users WHERE tenant_id = :tenant_id AND role = 'ADMIN_TENANT' ORDER BY created_at ASC NULLS LAST, id ASC LIMIT 1");
                $stmt->execute([':tenant_id' => $tenantId]);
                $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$isEdit) {
                    $this->ensureUniqueAdminEmail($pdo, $adminEmail);
                    $stmt = $pdo->prepare('INSERT INTO users (tenant_id, name, email, phone, password, role) VALUES (:tenant_id, :name, :email, :phone, :password, :role)');
                    $stmt->execute([
                        ':tenant_id' => $tenantId,
                        ':name' => $adminName !== '' ? $adminName : 'Administrador ' . $name,
                        ':email' => strtolower($adminEmail),
                        ':phone' => $adminPhone,
                        ':password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                        ':role' => 'ADMIN_TENANT',
                    ]);
                } elseif ($admin) {
                    $fields = [];
                    $params = [':id' => $admin['id']];
                    if ($adminEmail !== '') {
                        $this->ensureUniqueAdminEmail($pdo, $adminEmail, (string)$admin['id']);
                        $fields[] = 'email = :email';
                        $params[':email'] = strtolower($adminEmail);
                    }
                    if ($adminName !== '') { $fields[] = 'name = :name'; $params[':name'] = $adminName; }
                    if ($adminPhone !== '') { $fields[] = 'phone = :phone'; $params[':phone'] = $adminPhone; }
                    if ($adminPassword !== '') { $fields[] = 'password = :password'; $params[':password'] = password_hash($adminPassword, PASSWORD_DEFAULT); }
                    if ($fields) {
                        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
                        $stmt->execute($params);
                    }
                } elseif ($adminEmail !== '') {
                    $this->ensureUniqueAdminEmail($pdo, $adminEmail);
                    if ($adminPassword === '') throw new \Exception('Informe uma senha para criar o primeiro acesso desta empresa.');
                    $stmt = $pdo->prepare('INSERT INTO users (tenant_id, name, email, phone, password, role) VALUES (:tenant_id, :name, :email, :phone, :password, :role)');
                    $stmt->execute([
                        ':tenant_id' => $tenantId,
                        ':name' => $adminName !== '' ? $adminName : 'Administrador ' . $name,
                        ':email' => strtolower($adminEmail),
                        ':phone' => $adminPhone,
                        ':password' => password_hash($adminPassword, PASSWORD_DEFAULT),
                        ':role' => 'ADMIN_TENANT',
                    ]);
                }

                AuditService::log($isEdit ? 'TENANT_UPDATED' : 'TENANT_CREATED', 'tenants', $tenantId, [
                    'plan' => $plan,
                    'status' => $t->status,
                    'due_date' => $dueDate,
                ], $tenantId);

                $pdo->commit();
                TTransaction::close();

                TMessage::add('success', $isEdit ? 'Empresa atualizada com sucesso.' : 'Empresa cadastrada. O acesso do administrador já está disponível.');
                $this->redirect();
            } catch (\Throwable $e) {
                try {
                    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                } catch (\Throwable $ignore) {}
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Tenant save error: ' . $e->getMessage());
                TMessage::add('error', 'Não foi possível salvar a empresa. ' . $e->getMessage());
                $this->redirect($isEdit ? 'action=edit&id=' . rawurlencode($tenantIdParam) : 'action=new');
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'toggle_status' && !empty($_POST['id'])) {
            try {
                TTransaction::open();
                $t = new Tenant((string)$_POST['id']);
                if (!$t->id) throw new \Exception('Empresa não encontrada.');
                \App\Service\TenantAccessService::ensureNotSystemTenant((string)$t->id);
                $t->status = strtolower((string)$t->status) === 'ativo' ? 'suspenso' : 'ativo';
                $t->store();
                SubscriptionService::sync(TTransaction::get(), (string)$t->id, PlanCatalog::normalize($t->plan), (string)$t->start_date, (string)$t->due_date, (string)$t->status);
                AuditService::log('TENANT_STATUS_CHANGED', 'tenants', (string)$t->id, ['status' => $t->status], (string)$t->id);
                TTransaction::close();
                TMessage::add('success', 'Status da empresa atualizado.');
            } catch (\Throwable $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Tenant status error: ' . $e->getMessage());
                TMessage::add('error', 'Não foi possível alterar o status da empresa.');
            }
            $this->redirect();
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_tenant' && !empty($_POST['id'])) {
            $pdo = null;
            try {
                TTransaction::open();
                $pdo = TTransaction::get();
                $pdo->beginTransaction();
                $t = new Tenant((string)$_POST['id']);
                if (!$t->id) throw new \Exception('Empresa não encontrada.');
                \App\Service\TenantAccessService::ensureNotSystemTenant((string)$t->id);
                \App\Service\RecordDeletionService::deleteTenant($pdo, (string)$t->id);
                $pdo->commit();
                TTransaction::close();
                TMessage::add('success', 'Empresa e seus dados dependentes foram excluídos definitivamente.');
            } catch (\Throwable $e) {
                if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Tenant delete error: ' . $e->getMessage());
                TMessage::add('error', $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Não foi possível excluir a empresa. O erro foi registrado no log da aplicação.');
            }
            $this->redirect();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'export') {
            try {
                TTransaction::open();
                $tenants = Tenant::getCustomerObjects();
                TTransaction::close();
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: attachment; filename="ystek-empresas.csv"');
                echo "sep=;\n";
                echo "ID;Nome fantasia;Razão social;CNPJ/CPF;Telefone;WhatsApp;E-mail;Plano;Status;Início;Vencimento;Cadastro\n";
                foreach ($tenants as $t) {
                    $row = [$t->id, $t->name, $t->legal_name, $t->document, $t->phone, $t->whatsapp, $t->email, PlanCatalog::normalize($t->plan), $t->status, $t->start_date, $t->due_date, $t->created_at];
                    echo implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', $row)) . "\n";
                }
                exit;
            } catch (\Throwable $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                TMessage::add('error', 'Não foi possível exportar as empresas.');
                $this->redirect();
            }
        }
    }

    public function show()
    {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'new' || $action === 'edit') {
            $this->showForm($action === 'edit' ? (string)($_GET['id'] ?? '') : '');
            return;
        }

        TTransaction::open();
        $allTenants = Tenant::getCustomerObjects();
        $pdo = TTransaction::get();
        $adminByTenant = [];
        $stmt = $pdo->query("SELECT DISTINCT ON (tenant_id) tenant_id, name, email, phone FROM users WHERE role = 'ADMIN_TENANT' ORDER BY tenant_id, created_at ASC NULLS LAST, id ASC");
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) $adminByTenant[(string)$row['tenant_id']] = $row;
        TTransaction::close();

        $q = trim((string)($_GET['q'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));

        $total = count($allTenants); $active = 0; $suspended = 0; $expiring = 0;
        foreach ($allTenants as $tn) {
            if ($tn->status === 'ativo') $active++; else $suspended++;
            if (!empty($tn->due_date)) {
                try {
                    $days = (int)(new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable((string)$tn->due_date))->format('%r%a');
                    if ($days >= 0 && $days <= 7) $expiring++;
                } catch (\Throwable $ignore) {}
            }
        }

        $tenants = array_values(array_filter($allTenants, function ($tn) use ($q, $status) {
            $haystack = implode(' ', [(string)$tn->name, (string)$tn->legal_name, (string)$tn->document, (string)$tn->phone, (string)$tn->whatsapp, (string)$tn->email]);
            return ($q === '' || stripos($haystack, $q) !== false)
                && ($status === '' || (string)$tn->status === $status);
        }));

        usort($tenants, fn($a, $b) => strcmp((string)$b->created_at, (string)$a->created_at));
        $csrf = \App\Core\Security::csrfInput();
        $rows = '';
        foreach ($tenants as $tn) {
            $id = $this->e($tn->id);
            $name = $this->e($tn->name);
            $email = $this->e($tn->email ?: ($adminByTenant[(string)$tn->id]['email'] ?? ''));
            $doc = $this->e($tn->document);
            $phone = $this->e($tn->phone ?: $tn->whatsapp);
            $planName = PlanCatalog::normalize($tn->plan);
            $admin = $adminByTenant[(string)$tn->id] ?? null;
            $adminName = $this->e($admin['name'] ?? '—');
            $due = !empty($tn->due_date) ? date('d/m/Y', strtotime((string)$tn->due_date)) : '—';
            $stClass = $tn->status === 'ativo' ? 'badge-concluido' : 'badge-cancelado';
            $toggleLabel = $tn->status === 'ativo' ? 'Suspender' : 'Ativar';
            $toggleClass = $tn->status === 'ativo' ? 'btn-warning-soft' : 'btn-success-soft';
            $rows .= '<tr>
                <td><div class="company-cell"><strong>' . $name . '</strong><small>' . $adminName . ' · ' . $email . '</small></div></td>
                <td>' . $doc . '</td><td>' . $phone . '</td>
                <td><span class="plan-pill">' . $this->e($planName) . '</span></td>
                <td><span class="badge ' . $stClass . '">' . strtoupper($this->e($tn->status)) . '</span></td>
                <td>' . $due . '</td>
                <td class="actions-cell"><a class="btn btn-secondary btn-sm" href="index.php?class=TenantController&action=edit&id=' . rawurlencode((string)$tn->id) . '">Editar</a><form method="POST" action="index.php?class=TenantController" class="inline-action-form">' . $csrf . '<input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="' . $this->e($tn->id) . '"><button class="btn ' . $toggleClass . ' btn-sm" type="submit">' . $toggleLabel . '</button></form><form method="POST" action="index.php?class=TenantController" class="inline-action-form" onsubmit="return confirm(\'Excluir definitivamente esta empresa? Todos os dados associados, incluindo usuários, clientes, veículos, serviços, ordens, check-ins, danos, registros financeiros, assinaturas e logs da empresa, serão removidos. Esta ação não pode ser desfeita.\')">' . $csrf . '<input type="hidden" name="action" value="delete_tenant"><input type="hidden" name="id" value="' . $this->e($tn->id) . '"><button class="btn btn-danger btn-sm" type="submit">Excluir</button></form></td>
            </tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="7" class="empty-state"><strong>Nenhuma empresa encontrada</strong><span>Ajuste os filtros ou cadastre uma nova unidade.</span></td></tr>';

        echo '<div class="page-heading"><div><div class="eyebrow">Administração da plataforma</div><h1>Empresas & Assinaturas</h1><p>Controle das unidades, acessos e períodos de assinatura.</p></div><div class="heading-actions"><a class="btn btn-secondary" href="index.php?class=PlansController">Plano & Recursos</a><a class="btn btn-secondary" href="index.php?class=TenantController&action=export">Exportar CSV</a><a class="btn btn-primary" href="index.php?class=TenantController&action=new">+ Nova Empresa / Lava-Jato</a></div></div>
        <div class="metrics-grid saas-metrics">
            <div class="metric-card"><div class="metric-title">Empresas cadastradas</div><div class="metric-value">' . $total . '</div><div class="metric-sub">Todas as unidades</div></div>
            <div class="metric-card"><div class="metric-title">Ativas</div><div class="metric-value metric-success">' . $active . '</div><div class="metric-sub">Acesso liberado</div></div>
            <div class="metric-card"><div class="metric-title">Vencendo em 7 dias</div><div class="metric-value metric-warning">' . $expiring . '</div><div class="metric-sub">Exigem acompanhamento</div></div>
            <div class="metric-card"><div class="metric-title">Plano</div><div class="metric-value small-metric">Premium</div><div class="metric-sub">' . $total . ' empresa(s)</div></div>
        </div>
        <div class="panel saas-panel"><div class="panel-header"><div><div class="panel-title">Unidades da plataforma</div><div class="panel-subtitle">' . $total . ' empresas · ' . $active . ' ativas · ' . $suspended . ' suspensas</div></div></div>
        <div class="filter-bar"><form method="GET" class="filter-form"><input type="hidden" name="class" value="TenantController"><div class="filter-search"><label>Buscar empresa</label><input class="form-control" name="q" value="' . $this->e($q) . '" placeholder="Nome, CNPJ, telefone ou e-mail"></div><div><label>Status</label><select class="form-control" name="status"><option value="">Todos</option><option value="ativo" ' . ($status === 'ativo' ? 'selected' : '') . '>Ativo</option><option value="suspenso" ' . ($status === 'suspenso' ? 'selected' : '') . '>Suspenso</option></select></div><div class="filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn btn-secondary" href="index.php?class=TenantController">Limpar</a></div></form></div>
        <div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Empresa</th><th>CNPJ / CPF</th><th>Telefone</th><th>Plano</th><th>Status</th><th>Vencimento</th><th>Ações</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>';
    }

    private function showForm(string $editingId = ''): void
    {
        $csrf = \App\Core\Security::csrfInput();
        $editing = null; $admin = null;
        if ($editingId !== '') {
            TTransaction::open();
            $editing = new Tenant($editingId);
            if (!$editing->id) { TTransaction::close(); $this->redirect(); }
            $pdo = TTransaction::get();
            $stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE tenant_id = :tenant_id AND role = 'ADMIN_TENANT' ORDER BY created_at ASC NULLS LAST, id ASC LIMIT 1");
            $stmt->execute([':tenant_id' => $editingId]);
            $admin = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            TTransaction::close();
        }

        $isEdit = $editing !== null;
        $selectedPlan = PlanCatalog::PREMIUM;
        $startDate = $editing ? ($editing->start_date ?: date('Y-m-d')) : date('Y-m-d');
        $dueDate = $editing ? ($editing->due_date ?: date('Y-m-d', strtotime('+30 days'))) : date('Y-m-d', strtotime('+30 days'));

        $v = fn($prop, $default = '') => $this->e($editing ? ($editing->{$prop} ?? $default) : $default);
        $adminV = fn($prop, $default = '') => $this->e($admin[$prop] ?? $default);
        $title = $isEdit ? 'Editar empresa' : 'Nova Empresa / Lava-Jato';
        $subtitle = $isEdit ? 'Atualize cadastro, assinatura e acesso do administrador em uma única tela.' : 'Cadastre todos os dados da unidade e entregue o acesso já pronto para o responsável.';

        echo '<div class="page-heading"><div><a class="back-link" href="index.php?class=TenantController">← Voltar para empresas</a><div class="eyebrow">Cadastro da unidade</div><h1>' . $title . '</h1><p>' . $subtitle . '</p></div></div>
        <form method="POST" action="index.php?class=TenantController" class="tenant-form-page" autocomplete="off">' . $csrf . '
            <input type="hidden" name="action" value="save_tenant"><input type="hidden" name="id" value="' . $this->e($editingId) . '">
            <div class="form-layout">
                <div class="form-main">
                    <section class="panel form-section"><div class="section-heading"><div><div class="section-number">01</div><div><h2>Dados da empresa</h2><p>Informações oficiais do lava-jato.</p></div></div></div><div class="form-grid cols-2">
                        <div class="form-group span-2"><label>Nome fantasia *</label><input class="form-control" name="name" required maxlength="150" value="' . $v('name') . '" placeholder="Ex.: LP Lava Jato"></div>
                        <div class="form-group span-2"><label>Razão social</label><input class="form-control" name="legal_name" maxlength="150" value="' . $v('legal_name') . '" placeholder="Razão social da empresa"></div>
                        <div class="form-group"><label>CNPJ / CPF</label><input class="form-control" name="document" maxlength="20" value="' . $v('document') . '" placeholder="00.000.000/0000-00"></div>
                        <div class="form-group"><label>E-mail da empresa</label><input class="form-control" name="company_email" maxlength="100" value="' . $v('email') . '" placeholder="E-mail da empresa"></div>
                        <div class="form-group"><label>Telefone</label><input class="form-control" name="phone" maxlength="20" value="' . $v('phone') . '" placeholder="(94) 00000-0000"></div>
                        <div class="form-group"><label>WhatsApp</label><input class="form-control" name="whatsapp" maxlength="20" value="' . $v('whatsapp') . '" placeholder="(94) 00000-0000"></div>
                    </div></section>
                    <section class="panel form-section"><div class="section-heading"><div><div class="section-number">02</div><div><h2>Endereço</h2><p>Localização da unidade.</p></div></div></div><div class="form-grid cols-4">
                        <div class="form-group"><label>CEP</label><input class="form-control" name="cep" maxlength="10" value="' . $v('cep') . '" placeholder="68515-000"></div>
                        <div class="form-group span-2"><label>Rua / Avenida</label><input class="form-control" name="street" maxlength="150" value="' . $v('street') . '" placeholder="Rua, avenida ou rodovia"></div>
                        <div class="form-group"><label>Número</label><input class="form-control" name="number" maxlength="20" value="' . $v('number') . '" placeholder="617"></div>
                        <div class="form-group span-2"><label>Bairro</label><input class="form-control" name="neighborhood" maxlength="100" value="' . $v('neighborhood') . '" placeholder="Centro"></div>
                        <div class="form-group"><label>Cidade</label><input class="form-control" name="city" maxlength="100" value="' . $v('city') . '" placeholder="Parauapebas"></div>
                        <div class="form-group"><label>UF</label><input class="form-control" name="state" maxlength="2" value="' . $v('state') . '" placeholder="PA" style="text-transform:uppercase"></div>
                    </div></section>
                    <section class="panel form-section"><div class="section-heading"><div><div class="section-number">03</div><div><h2>Administrador de acesso</h2><p>Você define as credenciais que o responsável pelo lava-jato usará para entrar.</p></div></div></div><div class="form-grid cols-2">
                        <div class="form-group"><label>Nome do administrador</label><input class="form-control" name="admin_name" maxlength="100" value="' . $adminV('name') . '" placeholder="Nome completo"></div>
                        <div class="form-group"><label>Telefone do administrador</label><input class="form-control" name="admin_phone" maxlength="20" value="' . $adminV('phone') . '" placeholder="(94) 00000-0000"></div>
                        <div class="form-group span-2"><label>E-mail de acesso *</label><input class="form-control" name="admin_email" maxlength="100" value="' . $adminV('email') . '" placeholder="E-mail de acesso" autocomplete="off" required></div>
                        <div class="form-group"><label>Senha ' . ($isEdit ? '' : '*') . '</label><div class="password-field"><input id="admin_password" class="form-control" type="password" name="admin_password" maxlength="100" ' . ($isEdit ? '' : 'required') . ' placeholder="Defina uma senha" autocomplete="new-password"><button type="button" class="password-visibility" onclick="toggleTenantPassword(\'admin_password\')">Mostrar</button></div><div class="field-help">Mínimo de 6 caracteres.' . ($isEdit ? ' Deixe em branco para manter a atual.' : '') . '</div></div>
                        <div class="form-group"><label>Confirmar senha ' . ($isEdit ? '' : '*') . '</label><input class="form-control" type="password" name="admin_password_confirmation" maxlength="100" ' . ($isEdit ? '' : 'required') . ' placeholder="Repita a senha" autocomplete="new-password"></div>
                    </div></section>
                </div>
                <aside class="form-side">
                    <section class="panel form-section sticky-card"><div class="section-heading compact"><div><div class="section-number">04</div><div><h2>Plano & assinatura</h2><p>Todas as empresas clientes utilizam o plano Premium.</p></div></div></div><div class="plan-stack"><div class="plan-select plan-select-premium"><span class="plan-select-body"><strong>Premium<i>Plano único</i></strong><div class="plan-select-price">' . $this->e(PlanCatalog::priceFormatted($selectedPlan)) . ' <small>/ mês</small></div><small>Acesso a todos os recursos da plataforma, sujeito à validade da assinatura.</small><div class="plan-feature-count">Todos os recursos incluídos · sem limites por plano</div></span></div></div><div class="form-grid cols-2 plan-meta"><div class="form-group"><label>Status</label><select class="form-control" name="status"><option value="ativo" ' . (($editing->status ?? 'ativo') === 'ativo' ? 'selected' : '') . '>Ativo</option><option value="suspenso" ' . (($editing->status ?? '') === 'suspenso' ? 'selected' : '') . '>Suspenso</option></select></div><div></div><div class="form-group"><label>Início</label><input type="date" class="form-control" name="start_date" value="' . $this->e($startDate) . '"></div><div class="form-group"><label>Vencimento</label><input type="date" class="form-control" name="due_date" value="' . $this->e($dueDate) . '"></div></div></section>
                    <section class="panel summary-card"><div class="summary-label">ANTES DE SALVAR</div><h3>Cadastro completo</h3><p>A empresa, o administrador, as credenciais e a assinatura serão gravados em uma única operação.</p><div class="summary-row"><span>Plano</span><strong>' . $this->e($selectedPlan) . '</strong></div><div class="summary-row"><span>Acesso</span><strong>ADMIN_TENANT</strong></div><div class="summary-row"><span>Status</span><strong id="statusSummary">' . $this->e(ucfirst($editing->status ?? 'ativo')) . '</strong></div></section>
                </aside>
            </div>
            <div class="form-actions-bar"><a class="btn btn-secondary" href="index.php?class=TenantController">Cancelar</a><button class="btn btn-primary btn-lg" type="submit">' . ($isEdit ? 'Salvar alterações' : 'Criar empresa e acesso') . '</button></div>
        </form>
        <script>
        function toggleTenantPassword(id){var i=document.getElementById(id),b=i?i.parentElement.querySelector(".password-visibility"):null;if(!i)return;var show=i.type==="password";i.type=show?"text":"password";if(b)b.textContent=show?"Ocultar":"Mostrar";}
        var statusSelect=document.querySelector("select[name=status]"); if(statusSelect){statusSelect.addEventListener("change",function(){document.getElementById("statusSummary").textContent=this.value==="ativo"?"Ativo":"Suspenso";});}
        </script>';
    }
}
