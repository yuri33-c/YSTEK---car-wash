<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\Client;
use Adianti\Widget\TMessage;
use App\Service\PlanGuard;
use App\Service\RecordDeletionService;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class ClientsController extends TPage
{
    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_client') {
            try {
                TTransaction::open();
                if (empty($_POST['id'])) {
                    PlanGuard::enforceLimit('clients', count(Client::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']])));
                }
                if (!empty($_POST['id'])) {
                    $objects = Client::getObjects('id = :id AND tenant_id = :tenant_id', [
                        ':id' => $_POST['id'],
                        ':tenant_id' => $_SESSION['tenant_id']
                    ]);
                    if (empty($objects)) {
                        throw new \Exception("Acesso negado ao registro.");
                    }
                    $client = $objects[0];
                } else {
                    $client = new Client();
                    $client->tenant_id = $_SESSION['tenant_id'];
                }
                
                $client->name = $_POST['name'];
                $client->phone = $_POST['phone'];
                $client->email = $_POST['email'];
                $client->document = $_POST['document'];
                $client->notes = $_POST['notes'] ?? '';
                $client->store();
                TTransaction::close();
                
                TMessage::add('success', 'Cliente salvo com sucesso!');
                header('Location: index.php?class=ClientsController');
                exit;
            } catch (\Exception $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                TMessage::add('error', 'Erro ao salvar cliente: ' . $e->getMessage());
                header('Location: index.php?class=ClientsController');
                exit;
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_client' && isset($_POST['id'])) {
            $pdo = null;
            try {
                TTransaction::open();
                $pdo = TTransaction::get();
                $pdo->beginTransaction();
                $tenantId = (string)$_SESSION['tenant_id'];
                $clientId = (string)$_POST['id'];
                RecordDeletionService::deleteClient($pdo, $tenantId, $clientId);
                $pdo->commit();
                TTransaction::close();
                TMessage::add('success', 'Cliente excluído definitivamente. Veículos e ordens vinculadas foram preservados.');
            } catch (\Throwable $e) {
                if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Client delete error: ' . $e->getMessage());
                TMessage::add('error', $e instanceof \RuntimeException
                    ? $e->getMessage()
                    : 'Não foi possível excluir o cliente. O erro foi registrado no log da aplicação.');
            }
            header('Location: index.php?class=ClientsController');
            exit;
        }


    }

    public function show()
    {
        $csrf = \App\Core\Security::csrfInput();
        TTransaction::open();
        $clients = Client::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']]);
        TTransaction::close();

        $rows = '';
        foreach ($clients as $c) {
            $rows .= "<tr>
                <td>#" . substr((string)$c->id, 0, 8) . "</td>
                <td><strong>" . htmlspecialchars($c->name) . "</strong></td>
                <td>" . htmlspecialchars($c->phone) . "</td>
                <td>" . htmlspecialchars($c->email) . "</td>
                <td>" . htmlspecialchars($c->document) . "</td>
                <td>
                    <button onclick='editClient(" . json_encode($c->toArray()) . ")' class='btn btn-sm btn-secondary'>Editar</button>
                    <form method='POST' action='index.php?class=ClientsController' style='display:inline' onsubmit='return confirm(\"Excluir definitivamente este cliente? Veículos e ordens vinculadas serão preservados, sem o vínculo com o cadastro.\")'>" . $csrf . "<input type='hidden' name='action' value='delete_client'><input type='hidden' name='id' value='{$c->id}'><button type='submit' class='btn btn-sm btn-danger'>Excluir</button></form>
                </td>
            </tr>";
        }

        echo "
        <div class='page-heading'><div><div class='eyebrow'>Cadastros</div><h1>Clientes</h1><p>Cadastro e histórico de contatos do lava-jato.</p></div><div class='heading-actions'><button onclick='openClientModal()' class='btn btn-primary btn-lg'>+ Novo Cliente</button></div></div>
        <div class='panel'>
            <div class='panel-header'>
                <div><div class='panel-title'>Gestão de Clientes</div><div class='panel-subtitle'>" . count($clients) . " cliente(s) cadastrado(s)</div></div>
            </div>
            <div class='panel-body'>
                <div class='table-responsive'>
                    <table class='table-system'>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome Completo</th>
                                <th>Telefone / WhatsApp</th>
                                <th>E-mail</th>
                                <th>CPF / CNPJ</th>
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

        <div id='modalClient' class='modal-backdrop'>
            <div class='modal-content'>
                <form method='POST' action='index.php?class=ClientsController'>{$csrf}
                    <input type='hidden' name='action' value='save_client'>
                    <input type='hidden' id='client_id' name='id' value=''>
                    <div class='modal-header'>
                        <div class='modal-title' id='modalTitle'>Novo Cliente</div>
                        <button type='button' onclick='closeClientModal()' style='background:none;border:none;font-size:18px;cursor:pointer;'>&times;</button>
                    </div>
                    <div class='modal-body'>
                        <div class='form-group'>
                            <label>Nome Completo<span class=req>&nbsp;*</span></label>
                            <input type='text' id='client_name' name='name' class='form-control' required>
                        </div>
                        <div class='form-row'>
                            <div class='form-group'>
                                <label>Telefone / WhatsApp</label>
                                <input type='text' id='client_phone' name='phone' class='form-control' placeholder='(11) 99999-9999'>
                            </div>
                            <div class='form-group'>
                                <label>CPF / CNPJ</label>
                                <input type='text' id='client_document' name='document' class='form-control'>
                            </div>
                        </div>
                        <div class='form-group'>
                            <label>E-mail</label>
                            <input type='email' id='client_email' name='email' class='form-control'>
                        </div>
                    </div>
                    <div class='modal-footer'>
                        <button type='button' onclick='closeClientModal()' class='btn btn-secondary'>Cancelar</button>
                        <button type='submit' class='btn btn-primary'>Salvar Cliente</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openClientModal() {
                document.getElementById('client_id').value = '';
                document.getElementById('client_name').value = '';
                document.getElementById('client_phone').value = '';
                document.getElementById('client_email').value = '';
                document.getElementById('client_document').value = '';
                document.getElementById('modalTitle').innerText = 'Novo Cliente';
                document.getElementById('modalClient').classList.add('show');
            }
            function closeClientModal() {
                document.getElementById('modalClient').classList.remove('show');
            }
            function editClient(data) {
                document.getElementById('client_id').value = data.id;
                document.getElementById('client_name').value = data.name || '';
                document.getElementById('client_phone').value = data.phone || '';
                document.getElementById('client_email').value = data.email || '';
                document.getElementById('client_document').value = data.document || '';
                document.getElementById('modalTitle').innerText = 'Editar Cliente #' + data.id;
                document.getElementById('modalClient').classList.add('show');
            }
        </script>
        ";
    }
}
