<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\Vehicle;
use App\Model\Client;
use Adianti\Widget\TMessage;
use App\Service\PlanGuard;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class VehiclesController extends TPage
{
    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_vehicle') {
            try {
                TTransaction::open();
                if (empty($_POST['id'])) {
                    PlanGuard::enforceLimit('vehicles', count(Vehicle::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']])));
                }
                if (!empty($_POST['id'])) {
                    $objects = Vehicle::getObjects('id = :id AND tenant_id = :tenant_id', [
                        ':id' => $_POST['id'],
                        ':tenant_id' => $_SESSION['tenant_id']
                    ]);
                    if (empty($objects)) {
                        throw new \Exception("Acesso negado.");
                    }
                    $v = $objects[0];
                } else {
                    $v = new Vehicle();
                    $v->tenant_id = $_SESSION['tenant_id'];
                }
                
                $clientId = trim((string)($_POST['client_id'] ?? ''));
                $plate = strtoupper(trim((string)($_POST['plate'] ?? '')));
                $brand = trim((string)($_POST['brand'] ?? ''));
                $model = trim((string)($_POST['model'] ?? ''));
                $color = trim((string)($_POST['color'] ?? ''));

                if ($clientId === '') throw new \Exception('Selecione o proprietário do veículo.');
                if ($plate === '') throw new \Exception('Informe a placa do veículo.');
                if (strlen($plate) > 10) throw new \Exception('A placa deve ter no máximo 10 caracteres.');
                if (strlen($brand) > 50 || strlen($model) > 50 || strlen($color) > 30) throw new \Exception('Um dos campos do veículo ultrapassou o limite permitido.');

                \App\Service\TenantAccessService::client($clientId);

                $duplicateSql = 'SELECT id FROM vehicles WHERE tenant_id = :tenant_id AND UPPER(plate) = :plate';
                $duplicateParams = [':tenant_id' => (string)$_SESSION['tenant_id'], ':plate' => $plate];
                if (!empty($_POST['id'])) {
                    $duplicateSql .= ' AND id <> :id';
                    $duplicateParams[':id'] = (string)$_POST['id'];
                }
                $duplicateSql .= ' LIMIT 1';
                $duplicateStmt = TTransaction::get()->prepare($duplicateSql);
                $duplicateStmt->execute($duplicateParams);
                if ($duplicateStmt->fetchColumn()) throw new \Exception('Já existe um veículo com esta placa nesta empresa.');

                $v->client_id = $clientId;
                $v->plate = $plate;
                $v->brand = $brand;
                $v->model = $model;
                $v->color = $color;
                $allowedCategories = ['Passeio', 'SUV / Picape', 'Moto', 'Utilitario'];
                $category = $_POST['category'] ?? 'Passeio';
                if (!in_array($category, $allowedCategories, true)) {
                    throw new \Exception('Categoria de veículo inválida.');
                }
                $v->category = $category;
                $v->store();
                TTransaction::close();

                TMessage::add('success', 'Veículo salvo com sucesso.');
                header('Location: index.php?class=VehiclesController');
                exit;
            } catch (\Exception $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                TMessage::add('error', 'Erro ao salvar veículo: ' . $e->getMessage());
                header('Location: index.php?class=VehiclesController');
                exit;
            }
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'delete_vehicle' && !empty($_POST['id'])) {
            try {
                TTransaction::open();
                $objects = Vehicle::getObjects('id = :id AND tenant_id = :tenant_id', [
                    ':id' => $_POST['id'],
                    ':tenant_id' => $_SESSION['tenant_id']
                ]);
                
                if (empty($objects)) {
                    throw new \Exception("Acesso negado.");
                }
                $v = $objects[0];

                // O histórico das O.S. mantém placa/modelo; só o vínculo com o cadastro é removido.
                $pdoV = TTransaction::get();
                \App\Service\OrderIdentity::snapshotForVehicle($pdoV, (string)$_SESSION['tenant_id'], (string)$v->id);
                $pdoV->prepare('UPDATE service_orders SET vehicle_id = NULL WHERE tenant_id = :t AND vehicle_id = :v')
                     ->execute([':t' => (string)$_SESSION['tenant_id'], ':v' => (string)$v->id]);
                $v->delete();
                TTransaction::close();
                
                TMessage::add('success', 'Veículo excluído com sucesso.');
                header('Location: index.php?class=VehiclesController');
                exit;
            } catch (\Exception $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                if (strpos($e->getMessage(), 'foreign key constraint') !== false || strpos($e->getMessage(), 'violates foreign key') !== false) {
                    TMessage::add('error', 'Não é possível excluir este veículo pois existem ordens de serviço vinculadas a ele.');
                } else {
                    TMessage::add('error', 'Erro ao excluir veículo. Tente novamente.');
                }
                header('Location: index.php?class=VehiclesController');
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
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM public.vehicles WHERE tenant_id = :tenant_id');
            $countStmt->execute([':tenant_id' => $tenantId]);
            $totalVehicles = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalVehicles / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $vehicleStmt = $pdo->prepare('SELECT * FROM public.vehicles WHERE tenant_id = :tenant_id ORDER BY created_at DESC, id DESC LIMIT :limit_rows OFFSET :offset_rows');
            $vehicleStmt->bindValue(':tenant_id', $tenantId);
            $vehicleStmt->bindValue(':limit_rows', $perPage, \PDO::PARAM_INT);
            $vehicleStmt->bindValue(':offset_rows', ($currentPage - 1) * $perPage, \PDO::PARAM_INT);
            $vehicleStmt->execute();
            $vehicles = [];
            foreach ($vehicleStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $vehicle = new Vehicle();
                $vehicle->fromArray($row);
                $vehicles[] = $vehicle;
            }

            // O seletor precisa dos nomes de todos os clientes, mas não de objetos completos.
            $clientStmt = $pdo->prepare('SELECT id, name FROM public.clients WHERE tenant_id = :tenant_id ORDER BY name ASC');
            $clientStmt->execute([':tenant_id' => $tenantId]);
            $clientMap = [];
            $clientOptions = '';
            foreach ($clientStmt->fetchAll(\PDO::FETCH_ASSOC) as $clientRow) {
                $clientMap[(string)$clientRow['id']] = (string)$clientRow['name'];
                $clientOptions .= "<option value='" . htmlspecialchars((string)$clientRow['id'], ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars((string)$clientRow['name'], ENT_QUOTES, 'UTF-8') . "</option>";
            }
        } finally {
            TTransaction::close();
        }

        $pagination = '';
        if ($totalPages > 1) {
            $pagination = '<nav class="pagination-bar" aria-label="Paginação de veículos">';
            if ($currentPage > 1) $pagination .= '<a class="pagination-link" href="index.php?class=VehiclesController&page=' . ($currentPage - 1) . '">Anterior</a>';
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++) {
                $active = $pageNumber === $currentPage ? ' is-active' : '';
                $pagination .= '<a class="pagination-link' . $active . '" href="index.php?class=VehiclesController&page=' . $pageNumber . '">' . $pageNumber . '</a>';
            }
            if ($currentPage < $totalPages) $pagination .= '<a class="pagination-link" href="index.php?class=VehiclesController&page=' . ($currentPage + 1) . '">Próxima</a>';
            $pagination .= '<span class="pagination-caption">' . $totalVehicles . ' veículos · página ' . $currentPage . ' de ' . $totalPages . '</span></nav>';
        }

        $rows = '';
        foreach ($vehicles as $veh) {
            $clientName = $clientMap[$veh->client_id] ?? 'Cliente Não Informado';
            $rows .= "<tr>
                <td><strong>" . htmlspecialchars($veh->plate) . "</strong></td>
                <td>" . htmlspecialchars($veh->brand . ' ' . $veh->model) . "</td>
                <td>" . htmlspecialchars($veh->color) . "</td>
                <td>" . htmlspecialchars($veh->category) . "</td>
                <td>" . htmlspecialchars($clientName) . "</td>
                <td>
                    <button onclick='editVehicle(" . json_encode($veh->toArray()) . ")' class='btn btn-sm btn-secondary'>Editar</button>
                    <form method='POST' action='index.php?class=VehiclesController' style='display:inline' onsubmit='return confirm(\"Remover este veículo?\")'>{$csrf}<input type='hidden' name='action' value='delete_vehicle'><input type='hidden' name='id' value='{$veh->id}'><button type='submit' class='btn btn-sm btn-danger'>Excluir</button></form>
                </td>
            </tr>";
        }

        echo "
        <div class='page-heading'><div><div class='eyebrow'>Cadastros</div><h1>Veículos</h1><p>Controle da frota vinculada aos clientes.</p></div><div class='heading-actions'><button onclick='openVehicleModal()' class='btn btn-primary btn-lg'>+ Cadastrar Veículo</button></div></div>
        <div class='panel'>
            <div class='panel-header'>
                <div><div class='panel-title'>Frota & Veículos Cadastrados</div><div class='panel-subtitle'>" . $totalVehicles . " veículo(s) cadastrado(s) · 25 por página</div></div>
            </div>
            <div class='panel-body'>
                <div class='table-responsive'>
                    <table class='table-system'>
                        <thead>
                            <tr>
                                <th>Placa</th>
                                <th>Marca / Modelo</th>
                                <th>Cor</th>
                                <th>Categoria</th>
                                <th>Proprietário (Cliente)</th>
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

        <div id='modalVehicle' class='modal-backdrop'>
            <div class='modal-content'>
                <form method='POST' action='index.php?class=VehiclesController'>{$csrf}
                    <input type='hidden' name='action' value='save_vehicle'>
                    <input type='hidden' id='vehicle_id' name='id' value=''>
                    <div class='modal-header'>
                        <div class='modal-title' id='vModalTitle'>Novo Veículo</div>
                        <button type='button' onclick='closeVehicleModal()' style='background:none;border:none;font-size:18px;cursor:pointer;'>&times;</button>
                    </div>
                    <div class='modal-body'>
                        <div class='form-row'>
                            <div class='form-group'>
                                <label>Placa<span class=req>&nbsp;*</span></label>
                                <input type='text' id='v_plate' name='plate' class='form-control' placeholder='ABC-1234' maxlength='10' required>
                            </div>
                            <div class='form-group'>
                                <label>Proprietário<span class=req>&nbsp;*</span></label>
                                <select id='v_client_id' name='client_id' class='form-control' required>
                                    <option value=''>Selecione o Cliente</option>
                                    {$clientOptions}
                                </select>
                            </div>
                        </div>
                        <div class='form-row'>
                            <div class='form-group'>
                                <label>Marca</label>
                                <input type='text' id='v_brand' name='brand' class='form-control' placeholder='Ex: Toyota' maxlength='50'>
                            </div>
                            <div class='form-group'>
                                <label>Modelo</label>
                                <input type='text' id='v_model' name='model' class='form-control' placeholder='Ex: Corolla' maxlength='50'>
                            </div>
                        </div>
                        <div class='form-row'>
                            <div class='form-group'>
                                <label>Cor</label>
                                <input type='text' id='v_color' name='color' class='form-control' placeholder='Ex: Preto' maxlength='30'>
                            </div>
                            <div class='form-group'>
                                <label>Categoria</label>
                                <select id='v_category' name='category' class='form-control'>
                                    <option value='Passeio'>Hatch / Sedan</option>
                                    <option value='SUV / Picape'>SUV / Picape</option>
                                    <option value='Moto'>Moto</option>
                                    <option value='Utilitario'>Utilitário / Van</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class='modal-footer'>
                        <button type='button' onclick='closeVehicleModal()' class='btn btn-secondary'>Cancelar</button>
                        <button type='submit' class='btn btn-primary'>Salvar Veículo</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openVehicleModal() {
                document.getElementById('vehicle_id').value = '';
                document.getElementById('v_plate').value = '';
                document.getElementById('v_brand').value = '';
                document.getElementById('v_model').value = '';
                document.getElementById('v_color').value = '';
                document.getElementById('vModalTitle').innerText = 'Novo Veículo';
                document.getElementById('modalVehicle').classList.add('show');
            }
            function closeVehicleModal() {
                document.getElementById('modalVehicle').classList.remove('show');
            }
            function editVehicle(data) {
                document.getElementById('vehicle_id').value = data.id;
                document.getElementById('v_plate').value = data.plate || '';
                document.getElementById('v_client_id').value = data.client_id || '';
                document.getElementById('v_brand').value = data.brand || '';
                document.getElementById('v_model').value = data.model || '';
                document.getElementById('v_color').value = data.color || '';
                document.getElementById('v_category').value = data.category || 'Passeio';
                document.getElementById('vModalTitle').innerText = 'Editar Veículo ' + data.plate;
                document.getElementById('modalVehicle').classList.add('show');
            }
        </script>
        ";
    }
}
