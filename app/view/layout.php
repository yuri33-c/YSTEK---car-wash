<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> · YSTEK</title>
    <link rel="stylesheet" href="public/css/style.css?v=<?= filemtime(__DIR__ . '/../../public/css/style.css') ?>">
</head>
<body class="<?= $isSuper ? 'theme-superadmin' : 'theme-tenant' ?>" data-theme="<?= $isSuper ? 'superadmin' : 'tenant' ?>" data-tenant-revision="<?= htmlspecialchars((string)($tenantRealtimeVersion ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="sidebar-scrim" aria-label="Fechar navegação" tabindex="-1"></button>
    <div class="navigation-progress" aria-hidden="true"></div>
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-header">
            <img src="public/img/logo.png" alt="YSTEK">
            <strong><?= htmlspecialchars($isSuper ? 'YSTEK' : ($_SESSION['tenant_name'] ?? 'Lava Jato'), ENT_QUOTES, 'UTF-8') ?></strong>
            <small><?= $isSuper ? 'Plataforma' : 'Lava-Jato' ?></small>
        </div>

        <nav class="sidebar-nav">
            <?php if ($isSuper): ?>
                <div class="nav-section-title">PLATAFORMA</div>
                <a class="nav-item <?= $classParam === 'SaaSDashboardController' ? 'active' : '' ?>" href="index.php?class=SaaSDashboardController"><span class="nav-icon"><?= ystekIcon('dashboard') ?></span><span>Dashboard</span></a>
                <a class="nav-item <?= $classParam === 'TenantController' ? 'active' : '' ?>" href="index.php?class=TenantController"><span class="nav-icon"><?= ystekIcon('building') ?></span><span>Empresas</span></a>
                <a class="nav-item <?= $classParam === 'SubscriptionsController' ? 'active' : '' ?>" href="index.php?class=SubscriptionsController"><span class="nav-icon"><?= ystekIcon('credit') ?></span><span>Assinaturas</span></a>
                <a class="nav-item <?= $classParam === 'PlansController' ? 'active' : '' ?>" href="index.php?class=PlansController"><span class="nav-icon"><?= ystekIcon('layers') ?></span><span>Planos & Recursos</span></a>
            <?php else: ?>
                <div class="nav-section-title">OPERAÇÃO</div>
                <a class="nav-item <?= $classParam === 'DashboardController' ? 'active' : '' ?>" href="index.php?class=DashboardController"><span class="nav-icon"><?= ystekIcon('dashboard') ?></span><span>Dashboard</span></a>
                <a class="nav-item <?= $classParam === 'AgendaController' ? 'active' : '' ?>" href="index.php?class=AgendaController"><span class="nav-icon"><?= ystekIcon('calendar') ?></span><span>Agenda</span></a>
                <?php if (\App\Service\PlanCatalog::allows($plan, 'checkin_checklist')): ?>
                    <a class="nav-item <?= $classParam === 'OperationalController' ? 'active' : '' ?>" href="index.php?class=OperationalController"><span class="nav-icon"><?= ystekIcon('clipboard') ?></span><span>Check-in & Checklist</span></a>
                <?php endif; ?>
                <a class="nav-item <?= $classParam === 'OrdersController' ? 'active' : '' ?>" href="index.php?class=OrdersController"><span class="nav-icon"><?= ystekIcon('orders') ?></span><span>Ordens de Serviço</span></a>
                <div class="nav-section-title">CADASTROS</div>
                <a class="nav-item <?= $classParam === 'ClientsController' ? 'active' : '' ?>" href="index.php?class=ClientsController"><span class="nav-icon"><?= ystekIcon('clients') ?></span><span>Clientes</span></a>
                <a class="nav-item <?= $classParam === 'VehiclesController' ? 'active' : '' ?>" href="index.php?class=VehiclesController"><span class="nav-icon"><?= ystekIcon('vehicle') ?></span><span>Veículos</span></a>
                <a class="nav-item <?= $classParam === 'ServicesController' ? 'active' : '' ?>" href="index.php?class=ServicesController"><span class="nav-icon"><?= ystekIcon('service') ?></span><span>Serviços & Preços</span></a>
                <div class="nav-section-title">FINANCEIRO</div>
                <a class="nav-item <?= $classParam === 'FinancialController' ? 'active' : '' ?>" href="index.php?class=FinancialController"><span class="nav-icon"><?= ystekIcon('finance') ?></span><span>Caixa & Lançamentos</span></a>
                <?php if (\App\Service\PlanCatalog::allows($plan, 'reports') || \App\Service\PlanCatalog::allows($plan, 'team')): ?>
                    <div class="nav-section-title">GESTÃO</div>
                    <?php if (\App\Service\PlanCatalog::allows($plan, 'reports')): ?><a class="nav-item <?= $classParam === 'ReportsController' ? 'active' : '' ?>" href="index.php?class=ReportsController"><span class="nav-icon"><?= ystekIcon('report') ?></span><span>Relatórios</span></a><?php endif; ?>
                    <?php if (\App\Service\PlanCatalog::allows($plan, 'team')): ?><a class="nav-item <?= $classParam === 'UsersController' ? 'active' : '' ?>" href="index.php?class=UsersController"><span class="nav-icon"><?= ystekIcon('user') ?></span><span>Equipe</span></a><?php endif; ?>
                <?php endif; ?>
                <?php if (($_SESSION['role'] ?? '') === 'ADMIN_TENANT'): ?>
                    <div class="nav-section-title">CONFIGURAÇÕES</div>
                    <a class="nav-item <?= $classParam === 'SettingsController' ? 'active' : '' ?>" href="index.php?class=SettingsController"><span class="nav-icon"><?= ystekIcon('settings') ?></span><span>Configurações</span></a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-bottom">
            <?php if (!$isSuper): ?>
                <a class="sidebar-quick-action" href="index.php?class=OrdersController&new=1" aria-label="Novo atendimento"><span class="quick-plus">+</span><span>Novo<br>Atendimento</span></a>
            <?php endif; ?>
            <div class="sidebar-account">
                <div class="account-plan"><span>PLANO</span><strong><?= htmlspecialchars($isSuper ? 'ADMIN YSTEK' : $plan, ENT_QUOTES, 'UTF-8') ?></strong></div>
                <a href="index.php?class=LoginController&action=logout" class="logout-link" data-logout-link="1">Sair do sistema</a>
            </div>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-menu-toggle" aria-label="Abrir navegação" aria-controls="appSidebar" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="topbar-module"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="topbar-right">
                <?php if (!$isSuper): $sub = \App\Core\Auth::subscription(); ?>
                    <span class="subscription-mini <?= ($sub['state'] ?? 'active') !== 'active' ? 'attention' : '' ?>">
                        <strong><?= htmlspecialchars($sub['plan'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if (!empty($sub['due_date'])): ?><span>vence <?= htmlspecialchars(date('d/m/Y', strtotime($sub['due_date'])), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </span>
                <?php endif; ?>
                <span class="topbar-tenant"><?= htmlspecialchars($_SESSION['tenant_name'] ?? 'YSTEK Plataforma', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="topbar-user"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuário', ENT_QUOTES, 'UTF-8') ?><?php if (!$isSuper): ?><small class="topbar-role"><?= htmlspecialchars((string)($_SESSION['role'] ?? 'ADMIN_TENANT'), ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></span>
            </div>
        </header>

        <main class="content-container <?= $classParam === 'ServicesController' ? 'services-page' : '' ?>">
            <?php if (!$isSuper): $subscription = \App\Core\Auth::subscription(); ?>
                <?php if (($subscription['state'] ?? 'active') === 'grace'): ?>
                    <div class="subscription-alert warning"><strong>Assinatura em carência.</strong> O vencimento foi ultrapassado. Renove o plano para evitar o bloqueio do acesso.</div>
                <?php elseif (($subscription['state'] ?? 'active') === 'active' && (int)($subscription['days_to_due'] ?? 999) >= 0 && (int)($subscription['days_to_due'] ?? 999) <= 7): ?>
                    <div class="subscription-alert info"><strong>Assinatura próxima do vencimento.</strong> Faltam <?= (int)$subscription['days_to_due'] ?> dia(s) para <?= htmlspecialchars(date('d/m/Y', strtotime((string)$subscription['due_date'])), ENT_QUOTES, 'UTF-8') ?>.</div>
                <?php endif; ?>
            <?php endif; ?>
            <?= $pageContent ?>
        </main>
    </div>
    <?php if (!$isSuper): ?>
        <?php
            $mobileFinanceAllowed = in_array((string)($_SESSION['role'] ?? ''), ['ADMIN_TENANT', 'MANAGER', 'CASHIER'], true);
            $mobileLastClass = $mobileFinanceAllowed ? 'FinancialController' : 'ClientsController';
            $mobileLastLabel = $mobileFinanceAllowed ? 'Caixa' : 'Clientes';
            $mobileLastIcon = $mobileFinanceAllowed ? 'finance' : 'clients';
            $mobileNavItems = [
                ['class' => 'DashboardController', 'label' => 'Início', 'icon' => 'dashboard'],
                ['class' => 'AgendaController', 'label' => 'Agenda', 'icon' => 'calendar'],
                ['class' => 'OrdersController', 'label' => 'Ordens', 'icon' => 'orders'],
                ['class' => $mobileLastClass, 'label' => $mobileLastLabel, 'icon' => $mobileLastIcon],
            ];
        ?>
        <nav class="mobile-bottom-nav" aria-label="Navegação rápida">
            <?php foreach ($mobileNavItems as $mobileItem): ?>
                <a class="mobile-bottom-item <?= $classParam === $mobileItem['class'] ? 'active' : '' ?>" href="index.php?class=<?= rawurlencode($mobileItem['class']) ?>" <?= $classParam === $mobileItem['class'] ? 'aria-current="page"' : '' ?>>
                    <span class="mobile-bottom-icon"><?= ystekIcon($mobileItem['icon']) ?></span>
                    <span><?= htmlspecialchars($mobileItem['label'], ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endforeach; ?>
            <button class="mobile-bottom-item mobile-bottom-more" type="button" data-mobile-nav-more aria-controls="appSidebar" aria-expanded="false">
                <span class="mobile-bottom-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg></span>
                <span>Mais</span>
            </button>
        </nav>
    <?php endif; ?>
    <script src="public/js/app-shell.js?v=<?= filemtime(__DIR__ . '/../../public/js/app-shell.js') ?>" defer></script>
</body>
</html>
