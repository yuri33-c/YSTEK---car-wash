<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Service\PlanGuard;

/**
 * Exibe a trilha de auditoria do próprio tenant.
 * O filtro sempre contém tenant_id da sessão; nenhuma empresa visualiza logs de outra.
 */
class AuditController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public function show()
    {
        if (($_SESSION['role'] ?? '') !== 'ADMIN_TENANT') {
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Acesso restrito</strong><span>Somente o administrador da empresa pode consultar a auditoria.</span></div></div>';
            return;
        }
        if (!PlanGuard::allows('audit')) {
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Auditoria indisponível</strong><span>É necessário manter uma assinatura válida e ativa para consultar o histórico.</span></div></div>';
            return;
        }

        TTransaction::open();
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare('SELECT a.created_at, a.action, a.entity, a.details, a.ip, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.tenant_id = :tenant_id ORDER BY a.created_at DESC LIMIT 100');
        $stmt->execute([':tenant_id' => (string)$_SESSION['tenant_id']]);
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        TTransaction::close();

        $rows = '';
        foreach ($logs as $log) {
            $details = '';
            if (!empty($log['details'])) {
                $decoded = json_decode((string)$log['details'], true);
                if (is_array($decoded)) {
                    $parts = [];
                    foreach ($decoded as $k => $v) {
                        $parts[] = $k . ': ' . (is_bool($v) ? ($v ? 'sim' : 'não') : (string)$v);
                    }
                    $details = implode(' · ', $parts);
                } else {
                    $details = (string)$log['details'];
                }
            }
            $rows .= '<tr><td>' . ($log['created_at'] ? date('d/m/Y H:i', strtotime($log['created_at'])) : '—') . '</td><td><strong>' . $this->e($log['user_name'] ?? 'Usuário') . '</strong></td><td>' . $this->e($log['action']) . '</td><td>' . $this->e($log['entity']) . '</td><td>' . $this->e($details) . '</td><td>' . $this->e($log['ip']) . '</td></tr>';
        }
        if ($rows === '') $rows = '<tr><td colspan="6" class="empty-state"><strong>Nenhuma ação auditável registrada</strong><span>Novas alterações administrativas aparecerão aqui.</span></td></tr>';

        echo '<div class="page-heading"><div><div class="eyebrow">Governança</div><h1>Auditoria</h1><p>Histórico de ações administrativas da empresa.</p></div></div>
        <div class="panel"><div class="panel-header"><div><div class="panel-title">Últimas ações</div><div class="panel-subtitle">Até 100 registros do seu tenant.</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Entidade</th><th>Detalhes</th><th>IP</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>';
    }
}
