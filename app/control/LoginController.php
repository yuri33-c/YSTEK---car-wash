<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\User;
use App\Model\Tenant;
use App\Core\Security;
use App\Service\AuditService;
use App\Service\SubscriptionService;
use App\Service\LoginProtectionService;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class LoginController extends TPage
{
    public function process()
    {
        if ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && ($_POST['action'] ?? '') === 'logout') {
            // Logout é uma alteração de sessão e, por isso, usa exclusivamente POST + CSRF.
            Security::destroySession();
            header('Location: index.php?class=LoginController&reason=logout');
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        // O campo visível usa um nome próprio para evitar o preenchimento indevido
        // do domínio pelo gerenciador de credenciais do navegador.
        $email = trim($_POST['login_email'] ?? ($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \Adianti\Widget\TMessage::add('error', 'Informe um e-mail corporativo válido.');
            return;
        }

        TTransaction::open();
        try {
            $tenant = null;
            $pdo = TTransaction::get();
            $ip = Security::clientIp();
            LoginProtectionService::assertAllowed($pdo, $email, $ip);
            $users = User::getObjects("LOWER(email) = LOWER(:email)", [':email' => $email]);

            if (!$users) {
                LoginProtectionService::record($pdo, $email, $ip, false);
                TTransaction::close();
                \Adianti\Widget\TMessage::add('error', 'E-mail ou senha incorretos.');
                return;
            }

            $user = $users[0];
            $storedPassword = (string)($user->password ?? '');

            if (!password_verify($password, $storedPassword)) {
                LoginProtectionService::record($pdo, $email, $ip, false);
                TTransaction::close();
                \Adianti\Widget\TMessage::add('error', 'E-mail ou senha incorretos.');
                return;
            }

            if (isset($user->active) && in_array(strtolower(trim((string)$user->active)), ['0', 'false', 'f', 'no'], true)) {
                TTransaction::close();
                \Adianti\Widget\TMessage::add('error', 'Este acesso está desativado. Procure o administrador da plataforma.');
                return;
            }

            $role = strtoupper(trim((string)$user->role));
            $allowedRoles = ['SUPER_ADMIN', 'ADMIN_TENANT', 'MANAGER', 'CASHIER', 'WASHER'];
            if (!in_array($role, $allowedRoles, true)) {
                TTransaction::close();
                \Adianti\Widget\TMessage::add('error', 'Este usuário não possui um perfil de acesso válido.');
                return;
            }

            $subscription = null;
            $subscriptionState = null;
            if (\App\Core\Auth::isTenantRole($role) && !empty($user->tenant_id)) {
                $tenant = new Tenant($user->tenant_id);
                if (!$tenant->id) {
                    TTransaction::close();
                    \Adianti\Widget\TMessage::add('error', 'A empresa deste acesso não foi encontrada.');
                    return;
                }
                if (strtolower((string)$tenant->status) !== 'ativo') {
                    TTransaction::close();
                    \Adianti\Widget\TMessage::add('error', 'O acesso deste lava-jato está suspenso. Entre em contato com a administração YSTEK.');
                    return;
                }
                $subscription = SubscriptionService::current(TTransaction::get(), (string)$tenant->id);
                $subscriptionState = SubscriptionService::state($subscription, (string)$tenant->status);
                if (!in_array($subscriptionState, ['active', 'grace'], true)) {
                    TTransaction::close();
                    \Adianti\Widget\TMessage::add('error', $subscriptionState === 'expired' ? 'A assinatura deste lava-jato está vencida. Renove o plano para continuar.' : 'O acesso deste lava-jato não está liberado no momento.');
                    return;
                }
            }

            // Evita session fixation após autenticação bem-sucedida.
            session_regenerate_id(true);
            Security::rotateCsrf();
            $_SESSION['user_id'] = $user->id;
            $_SESSION['user_name'] = $user->name;
            $_SESSION['role'] = $role;
            $_SESSION['tenant_id'] = $user->tenant_id;
            // O catálogo legado é apresentado como Premium; o estado comercial
            // da assinatura continua sendo validado ao iniciar a sessão.
            $_SESSION['tenant_plan'] = ($role === 'SUPER_ADMIN') ? \App\Service\PlanCatalog::PREMIUM : \App\Service\PlanCatalog::normalize($tenant->plan ?? \App\Service\PlanCatalog::PREMIUM);
            $_SESSION['subscription_state'] = ($role === 'SUPER_ADMIN') ? 'active' : ($subscriptionState ?? 'active');
            $_SESSION['subscription_due_date'] = ($role === 'SUPER_ADMIN') ? null : ($subscription['due_date'] ?? ($tenant->due_date ?? null));
            $_SESSION['subscription_days_to_due'] = ($role === 'SUPER_ADMIN') ? 0 : ($subscription ? SubscriptionService::daysToDue($subscription) : 0);

            if (!empty($user->tenant_id)) {
                $tenant = $tenant ?? new Tenant($user->tenant_id);
                $_SESSION['tenant_name'] = $tenant->name ?? 'Lava Jato';
            } else {
                $_SESSION['tenant_name'] = 'YSTEK Plataforma';
            }

            try {
                LoginProtectionService::clearFailures($pdo, $email, $ip);
                LoginProtectionService::record($pdo, $email, $ip, true);
                $stmtLogin = $pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id');
                $stmtLogin->execute([':id' => (string)$user->id]);
                AuditService::log('LOGIN', 'users', (string)$user->id, ['role' => $role]);
            } catch (\Throwable $auditError) {
                error_log('Login audit warning: ' . $auditError->getMessage());
            }

            TTransaction::close();

            $destination = $role === 'SUPER_ADMIN'
                ? 'index.php?class=SaaSDashboardController'
                : 'index.php?class=DashboardController';

            header('Location: ' . $destination);
            exit;
        } catch (\PDOException $e) {
            // Erro de banco nunca é exibido ao usuário (só as mensagens de bloqueio, abaixo).
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Login database error: ' . $e->getMessage());
            \Adianti\Widget\TMessage::add('error', 'Não foi possível concluir o acesso. Verifique os dados e tente novamente.');
        } catch (\RuntimeException $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Login protection error: ' . $e->getMessage());
            \Adianti\Widget\TMessage::add('error', $e->getMessage());
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Login error: ' . $e->getMessage());
            \Adianti\Widget\TMessage::add('error', 'Não foi possível concluir o acesso. Verifique os dados e tente novamente.');
        }
    }

    public function show()
    {
        $messageHtml = '';
        ob_start();
        \Adianti\Widget\TMessage::show();
        $messageHtml = ob_get_clean();

        if (PHP_SAPI !== 'cli') {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $reason = trim((string)($_GET['reason'] ?? ''));
        $reasonMessage = match ($reason) {
            'suspended' => ['type' => 'error', 'message' => 'Esta empresa está suspensa pela administração YSTEK.'],
            'expired' => ['type' => 'error', 'message' => 'A assinatura deste lava-jato está vencida. Renove o plano para continuar.'],
            'canceled' => ['type' => 'error', 'message' => 'A assinatura deste lava-jato foi cancelada.'],
            'logout' => ['type' => 'success', 'message' => 'Você saiu do sistema com segurança.'],
            default => null,
        };

        // View MVC: somente a apresentação do login fica no arquivo de view.
        echo \App\Core\View::render('login', ['serverMessage' => $messageHtml, 'reasonMessage' => $reasonMessage]);

    }
}
