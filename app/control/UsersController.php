<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Model\User;
use App\Service\AuditService;
use App\Service\PlanGuard;
use App\Service\PlanCatalog;

/**
 * Controller de equipe do tenant.
 *
 * O ADMIN_TENANT cria e administra usuários operacionais do próprio lava-jato.
 * Todas as consultas usam tenant_id da sessão, mantendo o isolamento entre empresas.
 */
class UsersController extends TPage
{
    private const ROLES = [
        'MANAGER' => 'Gerente',
        'CASHIER' => 'Caixa / Atendimento',
        'WASHER' => 'Lavador',
    ];

    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function isActiveValue($value): bool
    {
        return !in_array(strtolower(trim((string)$value)), ['0', 'false', 'f', 'no'], true);
    }

    private function redirect(string $query = ''): void
    {
        header('Location: index.php?class=UsersController' . ($query ? '&' . $query : ''));
        exit;
    }

    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || ($_POST['action'] ?? '') !== 'save_user') {
            return;
        }

        if (($_SESSION['role'] ?? '') !== 'ADMIN_TENANT') {
            TMessage::add('error', 'Somente o administrador da empresa pode gerenciar a equipe.');
            $this->redirect();
        }

        $tenantId = (string)($_SESSION['tenant_id'] ?? '');
        $id = trim((string)($_POST['id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $role = strtoupper(trim((string)($_POST['role'] ?? 'WASHER')));
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
        $active = ($_POST['active'] ?? '1') === '1';
        $commissionPercent = (float)str_replace(',', '.', (string)($_POST['commission_percent'] ?? '20'));

        try {
            if ($name === '') throw new \Exception('Informe o nome do usuário.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \Exception('Informe um e-mail válido.');
            if (!isset(self::ROLES[$role])) throw new \Exception('Selecione um perfil válido.');
            if (!$id && $password === '') throw new \Exception('Defina uma senha para o novo usuário.');
            if ($password !== '' && strlen($password) < 6) throw new \Exception('A senha deve ter pelo menos 6 caracteres.');
            if ($password !== '' && !hash_equals($password, $passwordConfirmation)) throw new \Exception('A confirmação da senha não confere.');
            if ($commissionPercent < 0 || $commissionPercent > 100) throw new \Exception('A comissão deve estar entre 0% e 100%.');
            if ($role !== 'WASHER') $commissionPercent = 0.0;

            TTransaction::open();
            $pdo = TTransaction::get();

            if ($id !== '') {
                $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
                $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
                $user = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$user) throw new \Exception('Usuário não encontrado para esta empresa.');
            } else {
                PlanGuard::enforceLimit('users', (int)$pdo->query("SELECT COUNT(*) FROM users WHERE tenant_id = " . $pdo->quote($tenantId))->fetchColumn());
                $user = null;
            }

            if ($id !== '') {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND id <> :id LIMIT 1');
                $stmt->execute([':email' => $email, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1');
                $stmt->execute([':email' => $email]);
            }
            if ($stmt->fetchColumn()) throw new \Exception('Este e-mail já está em uso por outro usuário.');

            if ($user) {
                $fields = ['name = :name', 'email = :email', 'phone = :phone', 'role = :role', 'active = :active', 'commission_percent = :commission_percent'];
                $params = [':name' => $name, ':email' => $email, ':phone' => $phone, ':role' => $role, ':active' => $active, ':commission_percent' => $commissionPercent, ':id' => $id, ':tenant_id' => $tenantId];
                if ($password !== '') {
                    $fields[] = 'password = :password';
                    $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
                }
                $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id AND tenant_id = :tenant_id');
                $stmt->execute($params);
                AuditService::log('USER_UPDATED', 'users', $id, ['role' => $role, 'active' => $active, 'commission_percent' => $commissionPercent]);
                $message = 'Usuário atualizado com sucesso.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (tenant_id, name, email, phone, password, role, active, commission_percent) VALUES (:tenant_id, :name, :email, :phone, :password, :role, :active, :commission_percent) RETURNING id');
                $stmt->execute([
                    ':tenant_id' => $tenantId,
                    ':name' => $name,
                    ':email' => $email,
                    ':phone' => $phone,
                    ':password' => password_hash($password, PASSWORD_DEFAULT),
                    ':role' => $role,
                    ':active' => $active,
                    ':commission_percent' => $commissionPercent,
                ]);
                $newId = (string)$stmt->fetchColumn();
                AuditService::log('USER_CREATED', 'users', $newId, ['role' => $role]);
                $message = 'Usuário criado com sucesso.';
            }

            TTransaction::close();
            TMessage::add('success', $message);
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Users save error: ' . $e->getMessage());
            TMessage::add('error', 'Não foi possível salvar o usuário. ' . $e->getMessage());
        }
        $this->redirect();
    }

    public function show()
    {
        if (!PlanGuard::allows('team')) {
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Gestão de equipe indisponível</strong><span>É necessário manter uma assinatura válida e ativa para gerenciar a equipe.</span></div></div>';
            return;
        }

        $tenantId = (string)$_SESSION['tenant_id'];
        TTransaction::open();
        $users = User::getObjects("tenant_id = :tenant_id AND role <> 'ADMIN_TENANT'", [':tenant_id' => $tenantId]);
        TTransaction::close();

        $csrf = \App\Core\Security::csrfInput();
        $canManage = (($_SESSION['role'] ?? '') === 'ADMIN_TENANT');
        $rows = '';
        foreach ($users as $u) {
            $role = strtoupper((string)$u->role);
            $label = self::ROLES[$role] ?? 'Administrador';
            $status = ($u->active === false) ? 'Inativo' : 'Ativo';
            $statusClass = ($u->active === false) ? 'badge-cancelado' : 'badge-concluido';
            $actionCell = $canManage ? '<button class="btn btn-secondary btn-sm" onclick=\'editTeamUser(' . json_encode($u->toArray(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ')\'>Editar</button>' : '<span class="table-secondary">Somente leitura</span>';
            $rows .= '<tr><td><strong>' . $this->e($u->name) . '</strong><div class="table-secondary">' . $this->e($u->email) . '</div></td><td>' . $this->e($label) . '</td><td>' . $this->e($u->phone) . '</td><td>' . ((float)($u->commission_percent ?? 0) > 0 ? number_format((float)$u->commission_percent, 1, ',', '.') . '%' : '—') . '</td><td><span class="badge ' . $statusClass . '">' . $status . '</span></td><td class="actions-cell">' . $actionCell . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty-state"><strong>Nenhum usuário adicional cadastrado</strong><span>Crie a equipe do lava-jato para distribuir acessos.</span></td></tr>';
        }

        $newUserButton = $canManage ? '<button class="btn btn-primary btn-lg" onclick="openTeamModal()">+ Novo usuário</button>' : '';
        echo '<div class="page-heading"><div><div class="eyebrow">Administração da unidade</div><h1>Equipe</h1><p>Crie acessos separados para gerente, caixa e lavador.</p></div><div class="heading-actions">' . $newUserButton . '</div></div>' . '
        <div class="panel"><div class="panel-header"><div><div class="panel-title">Usuários da empresa</div><div class="panel-subtitle">' . count($users) . ' usuário(s) · limite do plano: ' . PlanCatalog::limitLabel(PlanGuard::currentPlan(), 'users') . ' (inclui o administrador)</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Usuário</th><th>Perfil</th><th>Telefone</th><th>Comissão</th><th>Status</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>
        <div id="modalTeam" class="modal-backdrop"><div class="modal-content"><form method="POST" action="index.php?class=UsersController" autocomplete="off">' . $csrf . '
            <input type="hidden" name="action" value="save_user"><input type="hidden" id="team_id" name="id" value="">
            <div class="modal-header"><div class="modal-title" id="teamModalTitle">Novo usuário</div><button type="button" class="modal-close" onclick="closeTeamModal()">&times;</button></div>
            <div class="modal-body"><div class="form-grid cols-2"><div class="form-group span-2"><label>Nome completo<span class=req>&nbsp;*</span></label><input class="form-control" id="team_name" name="name" required></div><div class="form-group"><label>E-mail<span class=req>&nbsp;*</span></label><input class="form-control" id="team_email" name="email" required></div><div class="form-group"><label>Telefone</label><input class="form-control" id="team_phone" name="phone"></div><div class="form-group"><label>Perfil<span class=req>&nbsp;*</span></label><select class="form-control" id="team_role" name="role" onchange="toggleCommissionField()"><option value="MANAGER">Gerente</option><option value="CASHIER">Caixa / Atendimento</option><option value="WASHER">Lavador</option></select></div><div class="form-group" id="commissionField"><label>Comissão do lavador (%)</label><input type="number" min="0" max="100" step="0.1" class="form-control" id="team_commission" name="commission_percent" value="0"></div><div class="form-group"><label>Status</label><select class="form-control" id="team_active" name="active"><option value="1">Ativo</option><option value="0">Inativo</option></select></div><div class="form-group"><label>Senha <span id="teamPasswordRequired">*</span></label><input type="password" class="form-control" id="team_password" name="password" autocomplete="new-password"></div><div class="form-group"><label>Confirmar senha</label><input type="password" class="form-control" id="team_password_confirmation" name="password_confirmation" autocomplete="new-password"></div></div><div class="field-help">Mínimo de 6 caracteres. Na edição, deixe vazio para manter a senha.</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeTeamModal()">Cancelar</button><button type="submit" class="btn btn-primary">Salvar usuário</button></div></form></div></div>
        <script>
        function toggleCommissionField(){var role=document.getElementById("team_role").value;document.getElementById("commissionField").style.display=role==="WASHER"?"block":"none";if(role!=="WASHER")document.getElementById("team_commission").value="0";} function openTeamModal(){document.getElementById("modalTeam").classList.add("show");document.getElementById("teamModalTitle").textContent="Novo usuário";document.getElementById("team_id").value="";["team_name","team_email","team_phone","team_password","team_password_confirmation"].forEach(function(id){document.getElementById(id).value="";});document.getElementById("team_role").value="WASHER";document.getElementById("team_commission").value="20";document.getElementById("team_active").value="1";document.getElementById("teamPasswordRequired").textContent="*";toggleCommissionField();}
        function closeTeamModal(){document.getElementById("modalTeam").classList.remove("show");}
        function editTeamUser(u){openTeamModal();document.getElementById("teamModalTitle").textContent="Editar usuário";document.getElementById("team_id").value=u.id||"";document.getElementById("team_name").value=u.name||"";document.getElementById("team_email").value=u.email||"";document.getElementById("team_phone").value=u.phone||"";document.getElementById("team_role").value=u.role||"WASHER";document.getElementById("team_commission").value=u.commission_percent||0;document.getElementById("team_active").value=(u.active===false||u.active===0||u.active==="0"||u.active==="f"||u.active==="false")?"0":"1";document.getElementById("teamPasswordRequired").textContent="";toggleCommissionField();}
        </script>';
    }
}
