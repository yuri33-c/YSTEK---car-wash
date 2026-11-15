<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Model\ServiceOrder;
use App\Model\VehicleCheckin;
use App\Model\VehicleDamage;
use App\Model\Client;
use App\Model\Vehicle;
use App\Service\OrderIdentity;
use App\Service\OrderStatus;
use App\Service\PlanGuard;
use App\Service\TenantAccessService;

/**
 * Controller da etapa operacional de check-in e checklist.
 *
 * Fluxo: Agenda -> Check-in -> Avarias -> O.S. em andamento.
 * Todas as gravações são sempre amarradas ao tenant da sessão.
 */
class OperationalController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    private function redirect(string $orderId): void
    {
        header('Location: index.php?class=OperationalController&order_id=' . rawurlencode($orderId));
        exit;
    }

    private function loadOrder(string $orderId): ServiceOrder
    {
        $objects = ServiceOrder::getObjects(
            'id = :id AND tenant_id = :tenant',
            [':id' => $orderId, ':tenant' => $_SESSION['tenant_id']]
        );
        if (empty($objects)) {
            throw new \Exception('Ordem de serviço não encontrada.');
        }
        return $objects[0];
    }

    public function process()
    {
        if (!PlanGuard::ensure('checkin_checklist')) {
            header('Location: index.php?class=AgendaController');
            exit;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['save_checkin', 'add_damage', 'delete_damage'], true)) {
            return;
        }

        $orderId = trim((string)($_POST['order_id'] ?? ''));
        try {
            if (!\App\Core\Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                throw new \Exception('Solicitação inválida. Atualize a página e tente novamente.');
            }

            TTransaction::open();
            $tenantId = (string)$_SESSION['tenant_id'];
            $order = $this->loadOrder($orderId);

            if ($action === 'save_checkin') {
                $checkins = VehicleCheckin::getObjects(
                    'service_order_id = :order AND tenant_id = :tenant',
                    [':order' => $orderId, ':tenant' => $tenantId]
                );
                $checkin = $checkins[0] ?? new VehicleCheckin();
                $checkin->tenant_id = $tenantId;
                $checkin->service_order_id = $orderId;
                $checkin->odometer = ($_POST['odometer'] ?? '') === '' ? null : max(0, (int)$_POST['odometer']);
                $checkin->fuel_level = ($_POST['fuel_level'] ?? '') === '' ? null : max(0, min(100, (int)$_POST['fuel_level']));
                $checkin->key_received = isset($_POST['key_received']);
                $checkin->spare_tire = isset($_POST['spare_tire']);
                $checkin->jack = isset($_POST['jack']);
                $checkin->wheel_wrench = isset($_POST['wheel_wrench']);
                $checkin->documents_received = isset($_POST['documents_received']);
                $checkin->accessories = trim((string)($_POST['accessories'] ?? ''));
                $checkin->observations = trim((string)($_POST['observations'] ?? ''));
                $checkin->confirmed_at = date('Y-m-d H:i:s');
                $checkin->created_by = $_SESSION['user_id'] ?? null;
                $checkin->store();

                // Confirmar a entrada inicia a execução quando a O.S. ainda está pendente.
                if (in_array(OrderStatus::normalize((string)$order->status), [OrderStatus::SCHEDULED, OrderStatus::WAITING], true)) {
                    $order->status = OrderStatus::IN_SERVICE;
                    $order->store();
                }

                \App\Service\AuditService::log('CHECKIN_CONFIRMED', 'service_order', $orderId, [
                    'vehicle_checkin_id' => (string)$checkin->id,
                ]);
                TTransaction::close();

                TMessage::add('success', 'Check-in confirmado. O atendimento foi marcado como Em serviço.');
                $this->redirect($orderId);
            }

            if ($action === 'add_damage') {
                $area = trim((string)($_POST['area'] ?? ''));
                $type = trim((string)($_POST['damage_type'] ?? ''));
                $severity = trim((string)($_POST['severity'] ?? 'Leve'));
                $description = trim((string)($_POST['description'] ?? ''));
                $allowedAreas = ['Frente', 'Traseira', 'Lateral esquerda', 'Lateral direita', 'Teto', 'Rodas', 'Vidros', 'Interior'];
                $allowedTypes = ['Risco', 'Arranhão', 'Amassado', 'Trinca', 'Mancha', 'Pintura', 'Outro'];
                $allowedSeverity = ['Leve', 'Média', 'Grave'];

                if (!in_array($area, $allowedAreas, true) || !in_array($type, $allowedTypes, true) || !in_array($severity, $allowedSeverity, true)) {
                    throw new \Exception('Dados da avaria inválidos.');
                }
                if (mb_strlen($description) > 500) {
                    throw new \Exception('A descrição da avaria ultrapassou o limite permitido.');
                }

                $damage = new VehicleDamage();
                $damage->tenant_id = $tenantId;
                $damage->service_order_id = $orderId;
                $damage->area = $area;
                $damage->damage_type = $type;
                $damage->severity = $severity;
                $damage->description = $description;
                $damage->created_by = $_SESSION['user_id'] ?? null;
                $damage->store();

                \App\Service\AuditService::log('DAMAGE_REGISTERED', 'service_order', $orderId, [
                    'damage_id' => (string)$damage->id,
                    'area' => $area,
                    'damage_type' => $type,
                    'severity' => $severity,
                ]);
                TTransaction::close();
                TMessage::add('success', 'Avaria registrada no checklist.');
                $this->redirect($orderId);
            }

            if ($action === 'delete_damage') {
                $damageId = trim((string)($_POST['damage_id'] ?? ''));
                $objects = VehicleDamage::getObjects(
                    'id = :id AND tenant_id = :tenant AND service_order_id = :order',
                    [':id' => $damageId, ':tenant' => $tenantId, ':order' => $orderId]
                );
                if (empty($objects)) {
                    throw new \Exception('Avaria não encontrada.');
                }
                $objects[0]->delete();
                \App\Service\AuditService::log('DAMAGE_REMOVED', 'service_order', $orderId, ['damage_id' => $damageId]);
                TTransaction::close();
                TMessage::add('success', 'Avaria removida do checklist.');
                $this->redirect($orderId);
            }
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Operational checklist error: ' . $e->getMessage());
            TMessage::add('error', $e->getMessage());
            if ($orderId !== '') {
                $this->redirect($orderId);
            }
        }
    }

    private function renderOrderPicker(): void
    {
        $rows = '';
        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $orderNumber = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'order_number') ? 'so.order_number' : 'NULL::INTEGER';
            $clientName = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'client_name_snapshot') ? 'so.client_name_snapshot' : 'NULL::TEXT';
            $vehiclePlate = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'vehicle_plate_snapshot') ? 'so.vehicle_plate_snapshot' : 'NULL::TEXT';
            $vehicleDescription = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'vehicle_desc_snapshot') ? 'so.vehicle_desc_snapshot' : 'NULL::TEXT';
            $stmt = $pdo->prepare("SELECT so.id, {$orderNumber} AS order_number, so.status, so.entry_time, COALESCE(c.name, {$clientName}) AS client_name, COALESCE(v.plate, {$vehiclePlate}) AS plate, COALESCE(v.brand, {$vehicleDescription}) AS brand, v.model,
                    (SELECT COUNT(*) FROM vehicle_checkins vc WHERE vc.service_order_id = so.id AND vc.tenant_id = so.tenant_id) AS checkin_count
                FROM service_orders so
                LEFT JOIN clients c ON c.id = so.client_id AND c.tenant_id = so.tenant_id
                LEFT JOIN vehicles v ON v.id = so.vehicle_id AND v.tenant_id = so.tenant_id
                WHERE so.tenant_id = :tenant
                ORDER BY so.entry_time DESC LIMIT 30");
            $stmt->execute([':tenant' => $_SESSION['tenant_id']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $status = OrderStatus::normalize((string)$r['status']);
                $done = (int)$r['checkin_count'] > 0;
                $rows .= '<tr><td><strong>' . $this->e(OrderIdentity::label($r)) . '</strong></td>'
                    . '<td>' . $this->e((string)($r['client_name'] ?? '—')) . '</td>'
                    . '<td>' . $this->e(trim(($r['plate'] ?? '') . ' · ' . ($r['brand'] ?? '') . ' ' . ($r['model'] ?? ''), ' ·')) . '</td>'
                    . '<td>' . $this->e(date('d/m/Y H:i', strtotime((string)$r['entry_time']))) . '</td>'
                    . '<td><span class="badge ' . OrderStatus::badgeClass((string)$r['status']) . '">' . $this->e($status) . '</span></td>'
                    . '<td>' . ($done ? '<span class="badge badge-finalizado">Feito</span>' : '<span class="badge badge-pendente">Pendente</span>') . '</td>'
                    . '<td><a class="btn btn-primary btn-sm" href="index.php?class=OperationalController&order_id=' . rawurlencode((string)$r['id']) . '">' . ($done ? 'Ver check-in' : 'Abrir check-in') . '</a></td></tr>';
            }
            TTransaction::close();
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Operational picker error: ' . $e->getMessage());
        }
        if ($rows === '') {
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Nenhuma O.S. encontrada.</strong><span>Crie um agendamento para registrar o check-in do veículo.</span></div></div>';
            return;
        }
        echo '<div class="page-heading"><div><span class="eyebrow">OPERAÇÃO</span><h1>Check-in &amp; Checklist</h1><p>Escolha o atendimento para registrar a entrada do veículo (km, combustível, acessórios e avarias).</p></div></div>'
            . '<div class="panel"><div class="panel-header"><div><div class="panel-title">Atendimentos recentes</div><div class="panel-subtitle">Últimas 30 O.S. desta empresa, de qualquer data.</div></div></div>'
            . '<div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>O.S.</th><th>Cliente</th><th>Veículo</th><th>Entrada</th><th>Status</th><th>Check-in</th><th>Ação</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div></div>';
    }

    public function show()
    {
        if (!PlanGuard::allows('checkin_checklist')) {
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Check-in indisponível</strong><span>É necessário manter uma assinatura válida e ativa para usar este recurso.</span></div></div>';
            return;
        }

        $orderId = trim((string)($_GET['order_id'] ?? ''));
        if ($orderId === '') {
            $this->renderOrderPicker();
            return;
        }

        try {
            TTransaction::open();
            $order = $this->loadOrder($orderId);
            // Cliente/veículo podem ter sido excluídos: o histórico usa o retrato gravado na O.S.
            if (!empty($order->client_id)) {
                TenantAccessService::client((string)$order->client_id);
                $client = new Client($order->client_id);
            } else {
                $client = (object)['name' => trim((string)($order->client_name_snapshot ?? '')) ?: 'Cliente (cadastro excluído)', 'phone' => (string)($order->client_phone_snapshot ?? '')];
            }
            if (!empty($order->vehicle_id)) {
                TenantAccessService::vehicle((string)$order->vehicle_id, (string)$order->client_id);
                $vehicle = new Vehicle($order->vehicle_id);
            } else {
                $vehicle = (object)['brand' => trim((string)($order->vehicle_desc_snapshot ?? '')) ?: 'Veículo', 'model' => '', 'plate' => (string)($order->vehicle_plate_snapshot ?? ''), 'color' => 'cadastro excluído', 'category' => ''];
            }

            $checkins = VehicleCheckin::getObjects('service_order_id = :order AND tenant_id = :tenant', [':order' => $orderId, ':tenant' => $_SESSION['tenant_id']]);
            $checkin = $checkins[0] ?? null;
            $damages = VehicleDamage::getObjects('service_order_id = :order AND tenant_id = :tenant', [':order' => $orderId, ':tenant' => $_SESSION['tenant_id']]);
            usort($damages, static fn($a, $b) => strcmp((string)($b->created_at ?? ''), (string)($a->created_at ?? '')));

            $historyRows = '';
            if (PlanGuard::allows('vehicle_history')) {
                $pdo = TTransaction::get();
                $orderNumber = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'order_number') ? 'so.order_number' : 'NULL::INTEGER';
                $hasVehiclePlateSnapshot = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'vehicle_plate_snapshot');
                $vehiclePlate = $hasVehiclePlateSnapshot
                    ? " OR (:plate <> '' AND so.vehicle_plate_snapshot = :plate2)"
                    : '';
                $stmt = $pdo->prepare("SELECT so.id, {$orderNumber} AS order_number, so.status, so.entry_time,
                    (SELECT COUNT(*) FROM vehicle_damages vd WHERE vd.service_order_id = so.id AND vd.tenant_id = so.tenant_id) AS damage_count,
                    (SELECT COUNT(*) FROM vehicle_checkins vc WHERE vc.service_order_id = so.id AND vc.tenant_id = so.tenant_id) AS checkin_count
                    FROM service_orders so
                    WHERE so.tenant_id = :tenant AND (so.vehicle_id = NULLIF(:vehicle, '')::uuid{$vehiclePlate})
                    ORDER BY so.entry_time DESC LIMIT 8");
                $historyParams = [
                    ':tenant' => $_SESSION['tenant_id'],
                    ':vehicle' => (string)($order->vehicle_id ?? ''),
                ];
                if ($hasVehiclePlateSnapshot) {
                    $historyParams[':plate'] = (string)($order->vehicle_plate_snapshot ?? '');
                    $historyParams[':plate2'] = (string)($order->vehicle_plate_snapshot ?? '');
                }
                $stmt->execute($historyParams);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $history) {
                    $historyRows .= '<tr><td><strong>' . $this->e(OrderIdentity::label($history)) . '</strong></td><td>' . $this->e(date('d/m/Y H:i', strtotime((string)$history['entry_time']))) . '</td><td>' . $this->e((string)$history['status']) . '</td><td>' . (int)$history['damage_count'] . '</td><td>' . ((int)$history['checkin_count'] > 0 ? 'OK' : '—') . '</td></tr>';
                }
            }
            TTransaction::close();
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Operational show error: ' . $e->getMessage());
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Não foi possível carregar a inspeção.</strong><span>Volte para a Agenda e tente novamente.</span></div></div>';
            return;
        }

        $odometer = $this->e($checkin->odometer ?? '');
        $fuel = $this->e($checkin->fuel_level ?? '');
        $accessories = $this->e($checkin->accessories ?? '');
        $observations = $this->e($checkin->observations ?? '');
        $isChecked = static function ($value): string { return !empty($value) ? ' checked' : ''; };

        $damageRows = '';
        foreach ($damages as $damage) {
            $severityClass = $damage->severity === 'Grave' ? 'badge-cancelado' : ($damage->severity === 'Média' ? 'badge-pendente' : 'badge-andamento');
            $damageRows .= '<tr><td><strong>' . $this->e($damage->area) . '</strong><div class="table-secondary">' . $this->e($damage->damage_type) . '</div></td><td><span class="badge ' . $severityClass . '">' . $this->e($damage->severity) . '</span></td><td>' . $this->e($damage->description ?: 'Sem descrição') . '</td><td class="actions-cell"><form method="POST" onsubmit="return confirm(\'Remover esta avaria?\')">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="delete_damage"><input type="hidden" name="order_id" value="' . $this->e($orderId) . '"><input type="hidden" name="damage_id" value="' . $this->e($damage->id) . '"><button class="btn btn-danger btn-sm">Remover</button></form></td></tr>';
        }
        if ($damageRows === '') {
            $damageRows = '<tr><td colspan="4" class="empty-state"><strong>Nenhuma avaria registrada</strong><span>Faça a inspeção visual antes de iniciar o serviço.</span></td></tr>';
        }

        $historyPanel = '';
        if (PlanGuard::allows('vehicle_history')) {
            if ($historyRows === '') {
                $historyRows = '<tr><td colspan="5" class="empty-state"><strong>Sem histórico anterior</strong><span>Esta pode ser a primeira passagem deste veículo.</span></td></tr>';
            }
            $historyPanel = '<div class="panel"><div class="panel-header"><div><div class="panel-title">Histórico do veículo</div><div class="panel-subtitle">Recurso Premium · últimas passagens desta placa.</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>O.S.</th><th>Data</th><th>Status</th><th>Avarias</th><th>Check-in</th></tr></thead><tbody>' . $historyRows . '</tbody></table></div></div></div>';
        }

        $status = $this->e($order->status);
        $orderShort = $this->e(ltrim(OrderIdentity::label($order), '#'));
        echo '<div class="page-heading"><div><a class="back-link" href="index.php?class=AgendaController">← Voltar para Agenda</a><div class="eyebrow">Operação</div><h1>Check-in & Checklist</h1><p>O.S. #' . $orderShort . ' · ' . $this->e($client->name) . ' · ' . $this->e($vehicle->plate) . '</p></div><div class="heading-actions"><span class="badge badge-andamento">' . $status . '</span></div></div>
        <div class="inspection-hero"><div><span>VEÍCULO</span><strong>' . $this->e($vehicle->brand . ' ' . $vehicle->model) . '</strong><small>' . $this->e($vehicle->plate) . ' · ' . $this->e($vehicle->color) . ' · ' . $this->e($vehicle->category) . '</small></div><div><span>CLIENTE</span><strong>' . $this->e($client->name) . '</strong><small>' . $this->e($client->phone ?: 'Telefone não informado') . '</small></div><div><span>O.S.</span><strong>#' . $orderShort . '</strong><small>R$ ' . number_format((float)$order->total_amount, 2, ',', '.') . '</small></div></div>
        <div class="inspection-grid">
            <section class="panel"><div class="panel-header"><div><div class="panel-title">01 · Entrada do veículo</div><div class="panel-subtitle">Registre a condição de chegada antes de iniciar o serviço.</div></div></div><div class="panel-body"><form method="POST">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="save_checkin"><input type="hidden" name="order_id" value="' . $this->e($orderId) . '"><div class="form-grid cols-2"><div class="form-group"><label>Quilometragem</label><input class="form-control" type="number" min="0" name="odometer" value="' . $odometer . '" placeholder="Ex.: 45890"></div><div class="form-group"><label>Combustível (%)</label><input class="form-control" type="number" min="0" max="100" name="fuel_level" value="' . $fuel . '" placeholder="Ex.: 50"></div></div><div class="checklist-options"><label><input type="checkbox" name="key_received"' . $isChecked($checkin->key_received ?? true) . '> Chave recebida</label><label><input type="checkbox" name="spare_tire"' . $isChecked($checkin->spare_tire ?? false) . '> Estepe presente</label><label><input type="checkbox" name="jack"' . $isChecked($checkin->jack ?? false) . '> Macaco presente</label><label><input type="checkbox" name="wheel_wrench"' . $isChecked($checkin->wheel_wrench ?? false) . '> Chave de roda</label><label><input type="checkbox" name="documents_received"' . $isChecked($checkin->documents_received ?? false) . '> Documentos recebidos</label></div><div class="form-group"><label>Acessórios / objetos deixados no veículo</label><textarea class="form-control" name="accessories" rows="3" placeholder="Ex.: tapetes, cadeirinha, carregador, objetos pessoais...">' . $accessories . '</textarea></div><div class="form-group"><label>Observações de entrada</label><textarea class="form-control" name="observations" rows="4" placeholder="Condições gerais, instruções do cliente, cuidados especiais...">' . $observations . '</textarea></div><div class="inspection-actions"><button class="btn btn-primary btn-lg" type="submit">' . ($checkin ? 'Atualizar check-in' : 'Confirmar entrada') . '</button></div></form></div></section>
            <section class="panel"><div class="panel-header"><div><div class="panel-title">02 · Registrar avaria</div><div class="panel-subtitle">Documente qualquer dano visível antes da execução.</div></div></div><div class="panel-body"><form method="POST">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="add_damage"><input type="hidden" name="order_id" value="' . $this->e($orderId) . '"><div class="form-grid cols-2"><div class="form-group"><label>Área<span class=req>&nbsp;*</span></label><select class="form-control" name="area" required><option value="">Selecione</option><option>Frente</option><option>Traseira</option><option>Lateral esquerda</option><option>Lateral direita</option><option>Teto</option><option>Rodas</option><option>Vidros</option><option>Interior</option></select></div><div class="form-group"><label>Tipo<span class=req>&nbsp;*</span></label><select class="form-control" name="damage_type" required><option value="">Selecione</option><option>Risco</option><option>Arranhão</option><option>Amassado</option><option>Trinca</option><option>Mancha</option><option>Pintura</option><option>Outro</option></select></div><div class="form-group"><label>Gravidade</label><select class="form-control" name="severity"><option>Leve</option><option>Média</option><option>Grave</option></select></div><div class="form-group span-2"><label>Descrição</label><input class="form-control" name="description" maxlength="500" placeholder="Ex.: risco horizontal na porta dianteira direita"></div></div><div class="inspection-actions"><button class="btn btn-secondary" type="submit">+ Adicionar avaria</button></div></form></div></section>
        </div>
        <div class="panel"><div class="panel-header"><div><div class="panel-title">03 · Avarias registradas</div><div class="panel-subtitle">Histórico vinculado a esta O.S.</div></div><span class="badge badge-pendente">' . count($damages) . ' registro(s)</span></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>Área / tipo</th><th>Gravidade</th><th>Descrição</th><th>Ações</th></tr></thead><tbody>' . $damageRows . '</tbody></table></div></div></div>
        ' . $historyPanel . '
        <style>
            .inspection-hero{display:grid;grid-template-columns:1.4fr 1fr .65fr;gap:12px;margin-bottom:16px}.inspection-hero>div{background:#fff;border:1px solid var(--border);border-radius:6px;padding:14px 16px;box-shadow:0 2px 8px rgba(20,50,80,.03)}.inspection-hero span{display:block;color:#728392;font-size:8px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.inspection-hero strong{display:block;color:#173a58;font-size:14px;margin-top:5px}.inspection-hero small{display:block;color:#8996a1;font-size:9px;margin-top:3px}.inspection-grid{display:grid;grid-template-columns:1.2fr 1fr;gap:16px;margin-bottom:16px}.checklist-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:5px 0 15px;padding:12px;border:1px solid var(--border-light);background:#fbfcfd;border-radius:5px}.checklist-options label{display:flex;align-items:center;gap:7px;color:#465d70;font-size:10px}.checklist-options input{accent-color:#1478cf}.inspection-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:7px}.modal-open{overflow:hidden}@media(max-width:1000px){.inspection-grid,.inspection-hero{grid-template-columns:1fr}}@media(max-width:650px){.checklist-options{grid-template-columns:1fr}.inspection-actions .btn{width:100%}}
        </style>';
    }
}
