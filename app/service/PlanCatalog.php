<?php
namespace App\Service;

/**
 * Catálogo comercial central do YSTEK.
 *
 * PREMIUM é o único plano para clientes; planos históricos são normalizados
 * para manter compatibilidade sem conservar níveis ou bloqueios antigos.
 */
final class PlanCatalog
{
    public const PREMIUM = 'Premium';

    private const PLAN = [
        'price' => 199.90,
        'description' => 'Acesso completo à plataforma YSTEK para a operação do seu lava-jato.',
        'limits' => ['clients' => null, 'vehicles' => null, 'services' => null, 'users' => null],
        'features' => [
            'dashboard', 'agenda', 'orders', 'clients', 'vehicles', 'services', 'finance_basic',
            'settings', 'reports', 'export', 'advanced_dashboard', 'advanced_reports',
            'finance_advanced', 'team', 'checkin_checklist', 'vehicle_history',
            'executive_dashboard', 'revenue_goals', 'audit',
        ],
    ];

    private const FEATURE_LABELS = [
        'dashboard' => 'Dashboard operacional',
        'agenda' => 'Agenda de atendimentos',
        'orders' => 'Ordens de Serviço',
        'clients' => 'Clientes',
        'vehicles' => 'Veículos',
        'services' => 'Serviços & Preços',
        'finance_basic' => 'Financeiro básico',
        'reports' => 'Relatórios gerenciais',
        'export' => 'Exportação CSV',
        'advanced_dashboard' => 'Indicadores avançados',
        'advanced_reports' => 'Relatórios com período personalizado',
        'finance_advanced' => 'Análise financeira avançada',
        'team' => 'Gestão de equipe e usuários',
        'checkin_checklist' => 'Check-in e checklist de avarias',
        'vehicle_history' => 'Histórico de inspeções do veículo',
        'settings' => 'Configurações da empresa',
        'executive_dashboard' => 'Painel executivo Premium',
        'revenue_goals' => 'Metas de faturamento e acompanhamento',
        'audit' => 'Auditoria da empresa',
    ];

    public static function all(): array
    {
        return [self::PREMIUM => self::PLAN];
    }

    public static function normalize(?string $plan): string
    {
        // Todos os valores armazenados antes do plano único, inclusive entradas
        // desconhecidas, passam a identificar o único plano comercial disponível.
        return self::PREMIUM;
    }

    public static function definition(?string $plan): array { return self::PLAN; }
    public static function price(?string $plan): float { return (float)self::PLAN['price']; }
    public static function priceFormatted(?string $plan): string { return 'R$ ' . number_format(self::price($plan), 2, ',', '.'); }
    public static function allows(?string $plan, string $feature): bool { return true; }
    public static function featureLabel(string $feature): string { return self::FEATURE_LABELS[$feature] ?? 'Este recurso'; }
    public static function limit(?string $plan, string $resource): ?int { return self::PLAN['limits'][$resource] ?? null; }
    public static function limitLabel(?string $plan, string $resource): string
    {
        $limit = self::limit($plan, $resource);
        return $limit === null ? 'Ilimitado' : number_format($limit, 0, ',', '.') . ' registros';
    }
}
