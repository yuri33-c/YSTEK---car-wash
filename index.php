<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/app/error.log');

set_exception_handler(function ($e) {
    error_log("Global Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if (class_exists('Adianti\\Widget\\TMessage')) {
        \Adianti\Widget\TMessage::add('error', 'Ocorreu um erro interno. A operação não pôde ser concluída.');
    }
});

spl_autoload_register(function ($class) {
    $class = str_replace('\\', '/', $class);
    if (strpos($class, 'Adianti/') === 0) {
        $file = __DIR__ . '/lib/' . strtolower(substr($class, 0, 7)) . substr($class, 7) . '.php';
        if (file_exists($file)) return require_once $file;
        $fileAlt = __DIR__ . '/lib/' . $class . '.php';
        if (file_exists($fileAlt)) return require_once $fileAlt;
    }
    if (strpos($class, 'App/') === 0) {
        $slash = strpos($class, '/', 4);
        if ($slash !== false) {
            $folder = strtolower(substr($class, 4, $slash - 4));
            $file = __DIR__ . '/app/' . $folder . '/' . substr($class, strrpos($class, '/') + 1) . '.php';
            if (file_exists($file)) return require_once $file;
        }
        $fileDirect = __DIR__ . '/' . strtolower($class) . '.php';
        if (file_exists($fileDirect)) return require_once $fileDirect;
    }
});

require_once __DIR__ . '/lib/adianti/database/TTransaction.php';
require_once __DIR__ . '/lib/adianti/database/TRecord.php';
require_once __DIR__ . '/lib/adianti/control/TPage.php';
require_once __DIR__ . '/lib/adianti/widget/TMessage.php';
require_once __DIR__ . '/app/model/Client.php';
require_once __DIR__ . '/app/model/Vehicle.php';
require_once __DIR__ . '/app/model/Service.php';
require_once __DIR__ . '/app/model/ServiceOrder.php';
require_once __DIR__ . '/app/model/ServiceOrderItem.php';
require_once __DIR__ . '/app/model/FinancialRecord.php';
require_once __DIR__ . '/app/model/Tenant.php';
require_once __DIR__ . '/app/model/User.php';
require_once __DIR__ . '/app/service/PlanCatalog.php';
require_once __DIR__ . '/app/service/PlanGuard.php';
require_once __DIR__ . '/app/core/Auth.php';
require_once __DIR__ . '/app/core/View.php';
require_once __DIR__ . '/app/core/Security.php';
require_once __DIR__ . '/app/service/SubscriptionService.php';
require_once __DIR__ . '/app/service/AuditService.php';
require_once __DIR__ . '/app/service/TenantAccessService.php';
require_once __DIR__ . '/app/service/LoginProtectionService.php';
require_once __DIR__ . '/app/service/WhatsAppService.php';
require_once __DIR__ . '/app/service/PremiumAnalyticsService.php';

\App\Core\Security::startSession();
\App\Core\Security::noCacheHeaders();

// Alterações de schema, criação do banco e criação do primeiro SUPER_ADMIN são tarefas de instalação/CLI.
// A requisição HTTP não executa DDL nem cria contas automaticamente.

$classParam = preg_replace('/[^A-Za-z0-9_]/', '', (string)($_GET['class'] ?? 'LoginController')) ?: 'LoginController';

// Logout dedicado: permite sair de forma determinística antes de qualquer redirecionamento
// de rota, validação de tenant ou renderização de página. A sessão e o cookie são
// invalidados e o navegador recebe um redirecionamento direto para o login.
if ($classParam === 'LoginController' && (($_GET['action'] ?? '') === 'logout')) {
    \App\Core\Auth::logout();
    header('Location: index.php?class=LoginController&reason=logout', true, 303);
    exit;
}

$role = \App\Core\Auth::role();

// Sem sessão, a única página permitida é o login.
if (!\App\Core\Auth::check() && $classParam !== 'LoginController') {
    header('Location: index.php?class=LoginController');
    exit;
}

if (\App\Core\Auth::check() && !\App\Core\Auth::validateCurrentUser()) {
    \App\Core\Auth::logout();
    header('Location: index.php?class=LoginController&reason=logout');
    exit;
}

// Revalida a empresa em toda requisição. Assim, uma sessão já aberta também é bloqueada
// imediatamente depois que o SUPER_ADMIN suspende o respectivo lava-jato.
if (\App\Core\Auth::check() && \App\Core\Auth::isTenantRole() && !\App\Core\Auth::validateCurrentTenant()) {
    $reason = (string)(\App\Core\Auth::subscription()['state'] ?? 'suspended');
    \App\Core\Auth::logout();
    header('Location: index.php?class=LoginController&reason=' . rawurlencode($reason));
    exit;
}

// Com sessão, Login sem logout devolve o usuário ao ambiente correspondente.
if ($classParam === 'LoginController' && \App\Core\Auth::check() && (($_GET['action'] ?? '') !== 'logout')) {
    $classParam = $role === 'SUPER_ADMIN' ? 'SaaSDashboardController' : 'DashboardController';
}

$allowedSuper = ['SaaSDashboardController', 'TenantController', 'PlansController', 'SubscriptionsController', 'LoginController'];
$allowedTenant = ['DashboardController', 'AgendaController', 'OperationalController', 'OrdersController', 'ClientsController', 'VehiclesController', 'ServicesController', 'FinancialController', 'ReportsController', 'UsersController', 'SettingsController', 'RealtimeController', 'LoginController'];

// Perfis de equipe têm acesso apenas à área operacional compatível com sua função.
$roleRouteMatrix = [
    'MANAGER' => array_values(array_diff($allowedTenant, ['SettingsController'])),
    'CASHIER' => ['DashboardController', 'AgendaController', 'OperationalController', 'OrdersController', 'ClientsController', 'VehiclesController', 'ServicesController', 'FinancialController', 'RealtimeController', 'LoginController'],
    'WASHER' => ['DashboardController', 'AgendaController', 'OperationalController', 'OrdersController', 'ClientsController', 'VehiclesController', 'RealtimeController', 'LoginController'],
];

if (\App\Core\Auth::isTenantRole() && in_array($classParam, ['TenantController','SaaSDashboardController','PlansController','SubscriptionsController'], true)) {
    \Adianti\Widget\TMessage::add('error', 'Esta área é exclusiva da administração YSTEK.');
    $classParam = 'DashboardController';
}

if ($role === 'SUPER_ADMIN' && !in_array($classParam, $allowedSuper, true)) {
    \Adianti\Widget\TMessage::add('error', 'A administração YSTEK não acessa a operação de um lava-jato.');
    $classParam = 'SaaSDashboardController';
}

if (isset($roleRouteMatrix[$role]) && !in_array($classParam, $roleRouteMatrix[$role], true)) {
    \Adianti\Widget\TMessage::add('error', 'Seu perfil não possui permissão para acessar esta área.');
    $classParam = 'DashboardController';
}

// Proteção CSRF: POSTs autenticados usam o token da sessão. O login usa um
// token separado em cookie para que uma página de login aberta antes de um
// logout continue válida quando a sessão anterior é encerrada.
$blockProcess = false;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfValid = $classParam === 'LoginController'
        ? \App\Core\Security::verifyLoginCsrf($_POST['_login_csrf'] ?? null)
        : \App\Core\Security::verifyCsrf($_POST['_csrf'] ?? null);

    if (!$csrfValid) {
        if ($classParam === 'LoginController') {
            \Adianti\Widget\TMessage::add('error', 'Sua sessão de acesso foi renovada. Confirme os dados e tente entrar novamente.');
            $blockProcess = true;
        } else {
            \Adianti\Widget\TMessage::add('error', 'Não foi possível validar a solicitação. Atualize a página e tente novamente.');
            header('Location: index.php?class=' . rawurlencode($classParam));
            exit;
        }
    }
}

if (\App\Core\Auth::isTenantRole() && !empty($_SESSION['tenant_id'])) {
    \App\Service\PlanGuard::refreshTenantPlan();
}

if ($classParam === 'AuditController') {
    \Adianti\Widget\TMessage::add('error', 'Esta área não está disponível como tela do sistema.');
    $classParam = $role === 'SUPER_ADMIN' ? 'SaaSDashboardController' : 'DashboardController';
}

$planRoutes = [
    'ReportsController' => 'reports',
    'UsersController' => 'team',
    'OperationalController' => 'checkin_checklist',
];
if (\App\Core\Auth::isTenantRole() && isset($planRoutes[$classParam]) && !\App\Service\PlanGuard::allows($planRoutes[$classParam])) {
    \Adianti\Widget\TMessage::add('error', \App\Service\PlanCatalog::featureLabel($planRoutes[$classParam]) . ' não está disponível no plano atual.');
    $classParam = 'DashboardController';
}

$fullClassName = "\\App\\Control\\{$classParam}";
if (!class_exists($fullClassName)) {
    $file = __DIR__ . "/app/control/{$classParam}.php";
    if (file_exists($file)) require_once $file;
}

$page = class_exists($fullClassName) ? new $fullClassName() : null;
if ($page && method_exists($page, 'process') && !$blockProcess) {
    try {
        $page->process();
    } catch (\Throwable $e) {
        error_log("Exception in process(): " . $e->getMessage());
        \Adianti\Widget\TMessage::add('error', 'Não foi possível concluir a operação.');
    }
}

// A versão inicial é lida antes da renderização para que qualquer alteração
// concorrente durante o carregamento seja percebida pelo próximo polling.
$tenantRealtimeVersion = \App\Core\Auth::isTenantRole()
    ? \App\Service\RealtimeVersion::current((string)($_SESSION['tenant_id'] ?? ''))
    : null;

if ($classParam === 'LoginController') {
    if ($page) $page->show();
    exit;
}

function ystekIcon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'building' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/><path d="M16 9h2a2 2 0 0 1 2 2v10M8 7h4M8 11h4M8 15h4M8 19h4"/></svg>',
        'layers' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/></svg>',
        'orders' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
        'clients' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0M16 11a3 3 0 1 0 0-6M16 14a5 5 0 0 1 5 5"/></svg>',
        'vehicle' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m5 17-1-5 2-5h12l2 5-1 5"/><path d="M6 17v2M18 17v2M4 12h16M7 15h.01M17 15h.01"/></svg>',
        'service' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 13h16M6 8h12M8 18h8"/><path d="M6 5h12M9 21h6"/></svg>',
        'user' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>',
        'credit' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 20 6v5c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6l8-3Z"/><path d="m9 12 2 2 4-4"/></svg>',
        'finance' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18M8 14h3"/></svg>',
        'report' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5M4 19h17"/><path d="m7 15 4-4 3 2 5-6"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18M7 13h3M14 13h3M7 17h3"/></svg>',
        'clipboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2M8 9h8M8 13h8M8 17h5"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-1.8 1.8-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V22h-2.54v-.1a1.7 1.7 0 0 0-1.03-1.56 1.7 1.7 0 0 0-1.88.34l-.06.06-1.8-1.8.06-.06A1.7 1.7 0 0 0 8.14 17a1.7 1.7 0 0 0-1.56-1.03H6.5v-2.54h.08a1.7 1.7 0 0 0 1.56-1.03 1.7 1.7 0 0 0-.34-1.88l-.06-.06 1.8-1.8.06.06a1.7 1.7 0 0 0 1.88.34A1.7 1.7 0 0 0 12.5 7.5v-.08h2.54v.08a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.88-.34l.06-.06 1.8 1.8-.06.06a1.7 1.7 0 0 0-.34 1.88 1.7 1.7 0 0 0 1.56 1.03H21v2.54h-.08A1.7 1.7 0 0 0 19.4 15Z"/></svg>',
    ];
    return $icons[$name] ?? $icons['dashboard'];
}

$titleMap = [
    'SaaSDashboardController' => 'Dashboard SaaS',
    'TenantController' => 'Empresas & Assinaturas',
    'PlansController' => 'Planos & Recursos',
    'SubscriptionsController' => 'Assinaturas',
    'UsersController' => 'Equipe',
    'SettingsController' => 'Configurações',
    'DashboardController' => 'Dashboard',
    'AgendaController' => 'Agenda',
    'OrdersController' => 'Ordens de Serviço',
    'ClientsController' => 'Clientes',
    'VehiclesController' => 'Veículos',
    'ServicesController' => 'Serviços & Preços',
    'FinancialController' => 'Financeiro',
    'ReportsController' => 'Relatórios',
    'OperationalController' => 'Check-in & Checklist',
];
$pageTitle = $titleMap[$classParam] ?? 'YSTEK Lava Jato';
$isSuper = $role === 'SUPER_ADMIN';
$plan = \App\Service\PlanGuard::currentPlan();
?>
<?php
// Front Controller MVC: o index coordena a requisição e entrega a apresentação
// para a View de layout. Os controllers continuam responsáveis pelo fluxo da tela.
ob_start();
if ($page) {
    try {
        $page->show();
    } catch (\Throwable $e) {
        error_log('Exception in show(): ' . $e->getMessage());
        echo '<div class="alert alert-error"><strong>Não foi possível carregar esta página.</strong></div>';
    }
} else {
    echo '<div class="panel"><div class="panel-body empty-state"><strong>Página não encontrada.</strong></div></div>';
}
$pageBody = (string)ob_get_clean();
// As mensagens são exibidas depois de montar a página (assim, avisos gerados durante
// show() aparecem na própria tela e não na seguinte), mas ficam acima do conteúdo.
ob_start();
\Adianti\Widget\TMessage::show();
$pageContent = (string)ob_get_clean() . $pageBody;

echo \App\Core\View::render('layout', [
    'pageTitle' => $pageTitle,
    'classParam' => $classParam,
    'isSuper' => $isSuper,
    'plan' => $plan,
    'tenantRealtimeVersion' => $tenantRealtimeVersion,
    'pageContent' => $pageContent,
]);
