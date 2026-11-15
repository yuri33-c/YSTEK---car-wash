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
    public function process()
    {
        // Alteração rápida do status diretamente na listagem do Caixa.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_financial_status') {
            try {
                if (!\App\Core\Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                    throw new \Exception('Solicitação inválida.');
                }

                $id = trim((string)($_POST['id'] ?? ''));
                $status = trim((string)($_POST['status'] ?? ''));
                if ($id === '' || !in_array($status, ['Pago', 'Pendente'], true)) {
                    throw new \Exception('Status financeiro inválido.');
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
                $status = trim((string)($_POST['status'] ?? 'Pago'));

                if ($description === '') throw new \Exception('Informe a descrição do lançamento.');
                if (strlen($description) > 200 || strlen($category) > 50) throw new \Exception('Um dos campos financeiros ultrapassou o limite permitido.');
                if (!in_array($type, ['Receita', 'Despesa'], true)) throw new \Exception('Tipo de lançamento inválido.');
                if (!in_array($status, ['Pago', 'Pendente'], true)) throw new \Exception('Status financeiro inválido.');
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
        TTransaction::open();
        $records = FinancialRecord::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']]);
        TTransaction::close();

        $totalReceitas = 0;
        $totalDespesas = 0;

        $rows = '';
        foreach ($records as $r) {
            $financialStatus = trim((string)($r->status ?? 'Pago'));
            if ($financialStatus !== 'Cancelado' && $r->type === 'Receita') $totalReceitas += $r->amount;
            if ($financialStatus !== 'Cancelado' && $r->type === 'Despesa') $totalDespesas += $r->amount;

            $valFmt = 'R$ ' . number_format($r->amount, 2, ',', '.');
            $typeClass = $r->type === 'Receita' ? 'badge-concluido' : 'badge-cancelado';

            if ($financialStatus === 'Cancelado') {
                $financialStatusControl = '<span class="badge badge-cancelado">Cancelado</span>';
            } else {
                $financialStatusClass = $financialStatus === 'Pago' ? 'badge-concluido' : 'badge-pendente';
                $financialStatusOptions = '';
                foreach (['Pago' => 'Pago / Recebido', 'Pendente' => 'Pendente'] as $statusValue => $statusLabel) {
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
                <td>#" . substr((string)$r->id, 0, 8) . "</td>
                <td><strong>" . htmlspecialchars($r->description) . "</strong></td>
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
            $orders = \App\Model\ServiceOrder::getObjects(
                'tenant_id = :tenant AND status IN (:finished, :legacy_finished)',
                [':tenant' => $_SESSION['tenant_id'], ':finished' => \App\Service\OrderStatus::FINISHED, ':legacy_finished' => 'Concluído']
            );
            TTransaction::close();

            $byMethod = [];
            foreach ($orders as $order) {
                $method = trim((string)($order->payment_method ?? 'Não informado')) ?: 'Não informado';
                $byMethod[$method] = ($byMethod[$method] ?? 0) + (float)$order->total_amount;
            }
            arsort($byMethod);
            $methodRows = '';
            foreach (array_slice($byMethod, 0, 6, true) as $method => $value) {
                $methodRows .= '<tr><td>' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '</td><td><strong>R$ ' . number_format($value, 2, ',', '.') . '</strong></td></tr>';
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
                                <th>ID</th>
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
            </div>
        </div>

        {$premiumPanel}

        <div id='modalFin' class='modal-backdrop'>
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
                                    <option value='Pago'>Pago / Recebido</option>
                                    <option value='Pendente'>Pendente</option>
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
            function openFinModal() {
                document.getElementById('fin_id').value = '';
                document.getElementById('f_description').value = '';
                document.getElementById('f_amount').value = '';
                document.getElementById('modalFin').classList.add('show');
            }
            function closeFinModal() {
                document.getElementById('modalFin').classList.remove('show');
            }
        </script>
        ";
    }
}
