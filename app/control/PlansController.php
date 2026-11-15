<?php
namespace App\Control;

use Adianti\Control\TPage;
use App\Service\PlanCatalog;

/**
 * Catálogo comercial apresentado ao SUPER_ADMIN.
 * Os preços e recursos vêm diretamente de PlanCatalog para evitar divergência
 * entre a tela comercial e as regras efetivas do backend.
 */
class PlansController extends TPage
{
    public function show()
    {
        $plans = PlanCatalog::all();
        $cards = '';
        foreach ($plans as $name => $def) {
            $items = '';
            foreach ($def['features'] as $feature) {
                $items .= '<li><span class="plan-check">✓</span>' . htmlspecialchars(PlanCatalog::featureLabel($feature), ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $cards .= '<article class="plan-card premium-plan"><div class="plan-ribbon premium-ribbon">Plano único</div><div class="plan-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div><div class="plan-price"><strong>' . PlanCatalog::priceFormatted($name) . '</strong><span>/ mês</span></div><div class="plan-description">' . htmlspecialchars($def['description'], ENT_QUOTES, 'UTF-8') . '</div><div class="plan-limits"><div><strong>Ilimitado</strong><span>clientes</span></div><div><strong>Ilimitado</strong><span>veículos</span></div><div><strong>Ilimitado</strong><span>usuários</span></div></div><ul class="plan-feature-list">' . $items . '</ul></article>';
        }

        // A administração apresenta o catálogo único, sem comparativos ou contratação de níveis.
        echo '<div class="page-heading"><div><div class="eyebrow">Catálogo comercial</div><h1>Plano & Recursos</h1><p>Premium é o único plano comercial; a validade da assinatura continua controlando o acesso.</p></div></div>
        <div class="plan-grid">' . $cards . '</div>
        <div class="premium-callout"><div><span class="premium-callout-kicker">PREMIUM</span><strong>Todos os recursos da plataforma em um único plano.</strong><p>Acesso completo sujeito à situação da assinatura, às regras de vencimento e à suspensão da empresa.</p></div><div class="premium-callout-points"><span>✓ Recursos operacionais e gerenciais</span><span>✓ Sem limites por categoria de plano</span><span>✓ ' . PlanCatalog::priceFormatted(PlanCatalog::PREMIUM) . ' por mês</span></div></div>';
    }
}
