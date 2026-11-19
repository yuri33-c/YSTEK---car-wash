<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\FinancialRecord;
use Adianti\Widget\TMessage;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class FinancialController extends TPage
{
    /**
     * Mantém os status coerentes com o tipo do lançamento.
     * Valores antigos são normalizados para preservar os registros já salvos.
     */
    private function normalizeStatus(string $type, string $status): ?string
    {
        if ($type === 'Receita') {
            $legacy = ['Pendente' => 'A receber', 'Pago' => 'Recebido'];
            $status = $legacy[$status] ?? $status;
            return in_array($status, ['A receber', 'Recebido'], true) ? $status : null;
        }

        if ($type === 'Despesa') {
            $legacy = ['Pendente' => 'A pagar', 'Recebido' => 'Pago'];
            $status = $legacy[$status] ?? $status;
            return in_array($status, ['A pagar', 'Pago'], true) ? $status : null;
        }

        return null;
    }

    public function process()
    {
        // Alteração rápida do status diretamente na listagem do Caixa.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_financial_status') {
            try {
                if (!\App\Core\Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                    throw new \Exception('Solicitação inválida.');
                }

                $id = trim((string)($_POST['id'] ?? ''));
                $postedStatus = trim((string)($_POST['status'] ?? ''));
                if ($id === '') {
                    throw new \Exception('Lançamento não informado.');
                }

                TTransaction::open();
                $objects = FinancialRecord::getObjects(
                    'id = :id AND tenant_id = :tenant_id',
                    [':id' => $id, ':tenant_id' => $_SESSION['tenant_id']]
                );
                if (empty($objects)) {
                    throw new \Exception('Lançamento não encontrado.');
                }

                $record = $objects[0];
                $status = $this->normalizeStatus((string)$record->type, $postedStatus);
                if ($status === null) {
                    throw new \Exception('Status incompatível com o tipo do lançamento.');
                }
                $record->status = $status;
                $record->store();
                TTransaction::close();
                TMessage::add('success', 'Status do lançamento atualizado.');
            } catch (\Throwable $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Financial status error: ' . $e->getMessage());
                TMessage::add('error', 'Não foi possível atualizar o status do lançamento.');
            }

            header('Location: index.php?class=FinancialController', true, 303);
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_financial') {
            try {
                TTransaction::open();
                if (!empty($_POST['id'])) {
                    $objects = FinancialRecord::getObjects('id = :id AND tenant_id = :tenant_id', [
                        ':id' => $_POST['id'],
                        ':tenant_id' => $_SESSION['tenant_id']
                    ]);
                    if (empty($objects)) {
                        throw new \Exception("Acesso negado.");
                    }
                    $f = $objects[0];
                } else {
                    $f = new FinancialRecord();
                    $f->tenant_id = $_SESSION['tenant_id'];
                }
                
                $description = trim((string)($_POST['description'] ?? ''));
                $type = trim((string)($_POST['type'] ?? 'Receita'));
                $amountRaw = trim((string)($_POST['amount'] ?? '0'));
                $amountNormalized = str_replace('.', '', $amountRaw);
                $amountNormalized = str_replace(',', '.', $amountNormalized);
                $category = trim((string)($_POST['category'] ?? ''));
                $status = trim((string)($_POST['status'] ?? ($type === 'Despesa' ? 'A pagar' : 'A receber')));

                if ($description === '') throw new \Exception('Informe a descrição do lançamento.');
                if (strlen($description) > 200 || strlen($category) > 50) throw new \Exception('Um dos campos financeiros ultrapassou o limite permitido.');
                if (!in_array($type, ['Receita', 'Despesa'], true)) throw new \Exception('Tipo de lançamento inválido.');
                $status = $this->normalizeStatus($type, $status);
                if ($status === null) throw new \Exception('Status incompatível com o tipo do lançamento.');
                if (!is_numeric($amountNormalized)) throw new \Exception('Informe um valor financeiro válido.');
                $amount = (float)$amountNormalized;
                if ($amount < 0 || $amount > 99999999.99) throw new \Exception('Informe um valor entre R$ 0,00 e R$ 99.999.999,99.');

                $f->description = $description;
                $f->type = $type;
                $f->amount = $amount;
                $f->category = $category;
                $f->status = $status;
                $f->store();
                TTransaction::close();

                TMessage::add('success', 'Lançamento financeiro salvo.');
                header('Location: index.php?class=FinancialController');
                exit;
            } catch (\Exception $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                TMessage::add('error', 'Erro ao salvar lançamento: ' . $e->getMessage());
                header('Location: index.php?class=FinancialController');
                exit;
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_financial' && !empty($_POST['id'])) {
            try {
                TTransaction::open();
                $objects = FinancialRecord::getObjects('id = :id AND tenant_id = :tenant_id', [
                    ':id' => $_POST['id'],
                    ':tenant_id' => $_SESSION['tenant_id']
                ]);
                if (empty($objects)) {
                    throw new \Exception("Acesso negado.");
                }
                $f = $objects[0];

                $f->delete();
                TTransaction::close();

                TMessage::add('success', 'Lançamento excluído com sucesso.');
                header('Location: index.php?class=FinancialController');
                exit;
            } catch (\Exception $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                TMessage::add('error', 'Erro ao excluir lançamento: ' . $e->getMessage());
                header('Location: index.php?class=FinancialController');
                exit;
            }
        }
    }

    public function show()
    {
        $csrf = \App\Core\Security::csrfInput();
        $tenantId = (string)$_SESSION['tenant_id'];
        $perPage = 25;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        TTransaction::open();
        try {
            $pdo = TTransaction::get();
            $totalsStmt = $pdo->prepare("SELECT
                COALESCE(SUM(amount) FILTER (WHERE type = 'Receita' AND COALESCE(status, 'Pago') <> 'Cancelado'), 0) AS total_revenue,
                COALESCE(SUM(amount) FILTER (WHERE type = 'Despesa' AND COALESCE(status, 'Pago') <> 'Cancelado'), 0) AS total_expenses,
                COUNT(*) AS total_records
                FROM public.financial_records WHERE tenant_id = :tenant_id");
            $totalsStmt->execute([':tenant_id' => $tenantId]);
            $totals = $totalsStmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $totalReceitas = (float)($totals['total_revenue'] ?? 0);
            $totalDespesas = (float)($totals['total_expenses'] ?? 0);
            $totalRecords = (int)($totals['total_records'] ?? 0);
            $totalPages = max(1, (int)ceil($totalRecords / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $recordsStmt = $pdo->prepare('SELECT * FROM public.financial_records WHERE tenant_id = :tenant_id ORDER BY created_at DESC, id DESC LIMIT :limit_rows OFFSET :offset_rows');
            $recordsStmt->bindValue(':tenant_id', $tenantId);
            $recordsStmt->bindValue(':limit_rows', $perPage, \PDO::PARAM_INT);
            $recordsStmt->bindValue(':offset_rows', ($currentPage - 1) * $perPage, \PDO::PARAM_INT);
            $recordsStmt->execute();
            $records = [];
            foreach ($recordsStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $record = new FinancialRecord();
                $record->fromArray($row);
                $records[] = $record;
            }
        } finally {
            TTransaction::close();
        }

        $pagination = '';
        if ($totalPages > 1) {
            $pagination = '<nav class="pagination-bar" aria-label="Paginação financeira">';
            if ($currentPage > 1) $pagination .= '<a class="pagination-link" href="index.php?class=FinancialController&page=' . ($currentPage - 1) . '">Anterior</a>';
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++) {
                $active = $pageNumber === $currentPage ? ' is-active' : '';
                $pagination .= '<a class="pagination-link' . $active . '" href="index.php?class=FinancialController&page=' . $pageNumber . '">' . $pageNumber . '</a>';
            }
            if ($currentPage < $totalPages) $pagination .= '<a class="pagination-link" href="index.php?class=FinancialController&page=' . ($currentPage + 1) . '">Próxima</a>';
            $pagination .= '<span class="pagination-caption">' . $totalRecords . ' lançamentos · página ' . $currentPage . ' de ' . $totalPages . '</span></nav>';
        }

        $rows = '';
        foreach ($records as $r) {
            $rawFinancialStatus = trim((string)($r->status ?? ''));
            $financialStatus = $this->normalizeStatus((string)$r->type, $rawFinancialStatus) ?? $rawFinancialStatus;

            $valFmt = 'R$ ' . number_format($r->amount, 2, ',', '.');
            $typeClass = $r->type === 'Receita' ? 'badge-concluido' : 'badge-cancelado';

            if ($financialStatus === 'Cancelado') {
                $financialStatusControl = '<span class="badge badge-cancelado">Cancelado</span>';
            } else {
                $isSettled = in_array($financialStatus, ['Pago', 'Recebido'], true);
                $financialStatusClass = $isSettled ? 'badge-concluido' : 'badge-pendente';
                $financialStatusOptions = '';
                $statusOptionsByType = $r->type === 'Despesa'
                    ? ['A pagar' => 'A pagar', 'Pago' => 'Pago']
                    : ['A receber' => 'A receber', 'Recebido' => 'Recebido'];
                foreach ($statusOptionsByType as $statusValue => $statusLabel) {
                    $selected = $financialStatus === $statusValue ? ' selected' : '';
                    $financialStatusOptions .= '<option value="' . htmlspecialchars($statusValue, ENT_QUOTES, 'UTF-8') . '"' . $selected . '>' . htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') . '</option>';
                }
                $financialStatusControl = '<form method="POST" action="index.php?class=FinancialController" class="inline-status-form">'
                    . $csrf
                    . '<input type="hidden" name="action" value="update_financial_status">'
                    . '<input type="hidden" name="id" value="' . htmlspecialchars((string)$r->id, ENT_QUOTES, 'UTF-8') . '">'
                    . '<select name="status" class="status-select ' . $financialStatusClass . '" title="Alterar status do lançamento" onchange="this.form.submit()">'
                    . $financialStatusOptions
                    . '</select></form>';
            }

            $rows .= "<tr>
                <td><strong>" . htmlspecialchars((string)$r->description, ENT_QUOTES, 'UTF-8') . "</strong></td>
                <td><span class='badge {$typeClass}'>" . htmlspecialchars($r->type) . "</span></td>
                <td>" . htmlspecialchars($r->category) . "</td>
                <td><strong>{$valFmt}</strong></td>
                <td>" . date('d/m/Y', strtotime($r->due_date)) . "</td>
                <td>" . $financialStatusControl . "</td>
                <td>
                    <form method='POST' action='index.php?class=FinancialController' style='display:inline' onsubmit='return confirm(\'Excluir este lançamento?\')'>{$csrf}<input type='hidden' name='action' value='delete_financial'><input type='hidden' name='id' value='{$r->id}'><button type='submit' class='btn btn-sm btn-danger'>Excluir</button></form>
                </td>
            </tr>";
        }

        $saldo = $totalReceitas - $totalDespesas;
        $recFmt = 'R$ ' . number_format($totalReceitas, 2, ',', '.');
        $despFmt = 'R$ ' . number_format($totalDespesas, 2, ',', '.');
        $saldoFmt = 'R$ ' . number_format($saldo, 2, ',', '.');

        // A análise avançada está incluída no plano único e consulta somente
        // ordens de serviço pertencentes ao tenant autenticado.
        $premiumPanel = '';
        if (\App\Service\PlanGuard::allows('finance_advanced')) {
            TTransaction::open();
            try {
                $methodStmt = TTransaction::get()->prepare("SELECT COALESCE(NULLIF(BTRIM(payment_method), ''), 'Não informado') AS method,
                    COALESCE(SUM(total_amount), 0) AS revenue
                    FROM public.service_orders
                    WHERE tenant_id = :tenant_id AND status IN (:finished, :legacy_finished)
                    GROUP BY COALESCE(NULLIF(BTRIM(payment_method), ''), 'Não informado')
                    ORDER BY revenue DESC LIMIT 6");
                $methodStmt->execute([
                    ':tenant_id' => $tenantId,
                    ':finished' => \App\Service\OrderStatus::FINISHED,
                    ':legacy_finished' => 'Concluído',
                ]);
                $methodRowsData = $methodStmt->fetchAll(\PDO::FETCH_ASSOC);
            } finally {
                TTransaction::close();
            }
            $methodRows = '';
            foreach ($methodRowsData as $methodRow) {
                $methodRows .= '<tr><td>' . htmlspecialchars((string)$methodRow['method'], ENT_QUOTES, 'UTF-8') . '</td><td><strong>R$ ' . number_format((float)$methodRow['revenue'], 2, ',', '.') . '</strong></td></tr>';
            }
            if ($methodRows === '') {
                $methodRows = '<tr><td colspan="2" class="empty-state"><strong>Nenhum pagamento concluído</strong><span>Os dados aparecerão após a finalização dos atendimentos.</span></td></tr>';
            }
            $premiumPanel = '<div class="panel premium-finance-panel"><div class="panel-header"><div><div class="panel-title">Análise financeira avançada</div><div class="panel-subtitle">Premium · distribuição das receitas por forma de pagamento.</div></div></div><div class="panel-body"><div class="table-responsive"><table class="table-system"><thead><tr><th>Forma de pagamento</th><th>Total recebido</th></tr></thead><tbody>' . $methodRows . '</tbody></table></div></div></div>';
        }

        echo "
        <div class='page-heading'><div><div class='eyebrow'>Financeiro</div><h1>Caixa & Lançamentos</h1><p>Resumo de receitas, despesas e resultado do lava-jato.</p></div><div class='heading-actions'><button onclick='openFinModal()' class='btn btn-primary btn-lg'>+ Novo Lançamento</button></div></div>
        <div class='metrics-grid'>
            <div class='metric-card'>
                <div class='metric-title'>Total Receitas</div>
                <div class='metric-value' style='color:#28a745;'>{$recFmt}</div>
                <div class='metric-sub'>Faturamento Bruto</div>
            </div>
            <div class='metric-card'>
                <div class='metric-title'>Total Despesas</div>
                <div class='metric-value' style='color:#dc3545;'>{$despFmt}</div>
                <div class='metric-sub'>Custos e Insumos</div>
            </div>
            <div class='metric-card'>
                <div class='metric-title'>Saldo em Caixa</div>
                <div class='metric-value' style='color:#0066cc;'>{$saldoFmt}</div>
                <div class='metric-sub'>Resultado Líquido</div>
            </div>
        </div>

        <div class='panel'>
            <div class='panel-header'>
                <div><div class='panel-title'>Lançamentos Financeiros (Livro Caixa)</div><div class='panel-subtitle'>Movimentações registradas no período.</div></div>
            </div>
            <div class='panel-body'>
                <div class='table-responsive'>
                    <table class='table-system'>
                        <thead>
                            <tr>
                                <th>Descrição / Origem</th>
                                <th>Tipo</th>
                                <th>Categoria</th>
                                <th>Valor (R$)</th>
                                <th>Data</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rows}
                        </tbody>
                    </table>
                </div>
                {$pagination}
            </div>
        </div>

        {$premiumPanel}

        <div id='modalFin' class='modal-backdrop financial-form-modal'>
            <div class='modal-content'>
                <form method='POST' action='index.php?class=FinancialController'>{$csrf}
                    <input type='hidden' name='action' value='save_financial'>
                    <input type='hidden' id='fin_id' name='id' value=''>
                    <div class='modal-header'>
                        <div class='modal-title'>Novo Lançamento Financeiro</div>
                        <button type='button' onclick='closeFinModal()' style='background:none;border:none;font-size:18px;cursor:pointer;'>&times;</button>
                    </div>
                    <div class='modal-body'>
                        <div class='form-group'>
                            <label>Descrição<span class=req>&nbsp;*</span></label>
                            <input type='text' id='f_description' name='description' class='form-control' required placeholder='Ex: Compra de Shampoo Automotivo'>
                        </div>
                        <div class='form-row'>
                            <div class='form-group'>
                                <label>Tipo<span class=req>&nbsp;*</span></label>
                                <select id='f_type' name='type' class='form-control' required>
                                    <option value='Receita'>Receita (Entrada)</option>
                                    <option value='Despesa'>Despesa (Saída)</option>
                                </select>
                            </div>
                            <div class='form-group'>
                                <label>Valor (R$)<span class=req>&nbsp;*</span></label>
                                <input type='text' id='f_amount' name='amount' class='form-control' required placeholder='150,00'>
                            </div>
                        </div>
                        <div class='form-row'>
                            <div class='form-group'>
                                <label>Categoria</label>
                                <input type='text' id='f_category' name='category' class='form-control' placeholder='Ex: Insumos, Aluguel, Serviços'>
                            </div>
                            <div class='form-group'>
                                <label>Status</label>
                                <select id='f_status' name='status' class='form-control'>
                                    <option value='A receber'>A receber</option>
                                    <option value='Recebido'>Recebido</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class='modal-footer'>
                        <button type='button' onclick='closeFinModal()' class='btn btn-secondary'>Cancelar</button>
                        <button type='submit' class='btn btn-primary'>Salvar Lançamento</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function syncFinancialStatusOptions() {
                const type = document.getElementById('f_type').value;
                const status = document.getElementById('f_status');
                const options = type === 'Despesa'
                    ? [['A pagar', 'A pagar'], ['Pago', 'Pago']]
                    : [['A receber', 'A receber'], ['Recebido', 'Recebido']];
                while (status.firstChild) status.removeChild(status.firstChild);
                options.forEach(function (item) {
                    const option = document.createElement('option');
                    option.value = item[0];
                    option.textContent = item[1];
                    status.appendChild(option);
                });
                status.value = options[0][0];
            }
            document.getElementById('f_type').addEventListener('change', syncFinancialStatusOptions);
            function openFinModal() {
                document.getElementById('fin_id').value = '';
                document.getElementById('f_description').value = '';
                document.getElementById('f_amount').value = '';
                document.getElementById('f_category').value = '';
                document.getElementById('f_type').value = 'Receita';
                syncFinancialStatusOptions();
                document.getElementById('modalFin').classList.add('show');
            }
            function closeFinModal() {
                document.getElementById('modalFin').classList.remove('show');
            }
        </script>
        ";
    }
}
