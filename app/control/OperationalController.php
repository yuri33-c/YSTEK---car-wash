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
        $cards = '';
        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $orderNumber = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'order_number') ? 'so.order_number' : 'NULL::INTEGER';
            $clientName = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'client_name_snapshot') ? 'so.client_name_snapshot' : 'NULL::TEXT';
            $vehiclePlate = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'vehicle_plate_snapshot') ? 'so.vehicle_plate_snapshot' : 'NULL::TEXT';
            $vehicleDescription = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_orders', 'vehicle_desc_snapshot') ? 'so.vehicle_desc_snapshot' : 'NULL::TEXT';
            $stmt = $pdo->prepare("SELECT so.id, {$orderNumber} AS order_number, so.status, so.entry_time,
                    COALESCE(NULLIF(c.name, ''), NULLIF({$clientName}, ''), 'Cliente não identificado') AS client_name,
                    COALESCE(NULLIF(v.plate, ''), NULLIF({$vehiclePlate}, ''), 'Placa não informada') AS plate,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', v.brand, v.model)), ''), NULLIF({$vehicleDescription}, ''), 'Veículo não identificado') AS vehicle_name,
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
                $orderLabel = OrderIdentity::label($r);
                $entryDate = !empty($r['entry_time']) ? date('d/m/Y · H:i', strtotime((string)$r['entry_time'])) : 'Data não informada';
                $cards .= '<article class="checkin-order-card">'
                    . '<div class="checkin-order-card-top"><span class="checkin-order-number">O.S. ' . $this->e($orderLabel) . '</span>'
                    . '<span class="badge ' . OrderStatus::badgeClass((string)$r['status']) . '">' . $this->e($status) . '</span></div>'
                    . '<div class="checkin-order-client"><span>CLIENTE</span><strong>' . $this->e($r['client_name']) . '</strong></div>'
                    . '<div class="checkin-order-vehicle"><span>VEÍCULO</span><strong>' . $this->e($r['vehicle_name']) . '</strong><small>' . $this->e($r['plate']) . '</small></div>'
                    . '<div class="checkin-order-card-bottom"><span class="checkin-order-date">' . $this->e($entryDate) . '</span>'
                    . '<span class="checkin-state ' . ($done ? 'is-done' : 'is-pending') . '">' . ($done ? 'Check-in registrado' : 'Check-in pendente') . '</span></div>'
                    . '<a class="btn btn-primary checkin-order-action" href="index.php?class=OperationalController&order_id=' . rawurlencode((string)$r['id']) . '">' . ($done ? 'Ver / editar check-in' : 'Iniciar check-in') . '</a>'
                    . '</article>';
            }
            TTransaction::close();
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('Operational picker error: ' . $e->getMessage());
            echo '<div class="panel"><div class="panel-body empty-state"><strong>Não foi possível carregar os atendimentos.</strong><span>Atualize a página. Se o problema continuar, procure o administrador da unidade.</span></div></div>';
            return;
        }

        if ($cards === '') {
            echo '<div class="operational-page checkin-picker-page"><div class="page-heading"><div><span class="eyebrow">OPERAÇÃO</span><h1>Check-in &amp; Checklist</h1><p>Registre a condição do veículo antes de iniciar o serviço.</p></div></div>'
                . '<div class="panel"><div class="panel-body empty-state"><strong>Nenhuma ordem de serviço encontrada.</strong><span>Crie um atendimento na Agenda ou em Ordens de Serviço para iniciar o check-in.</span><a class="btn btn-primary" href="index.php?class=OrdersController">Abrir Ordens de Serviço</a></div></div></div>';
            return;
        }

        $cardCount = substr_count($cards, '<article class="checkin-order-card">');
        echo '<div class="operational-page checkin-picker-page">'
            . '<div class="page-heading"><div><span class="eyebrow">OPERAÇÃO</span><h1>Check-in &amp; Checklist</h1><p>Escolha o atendimento e registre a condição do veículo antes de iniciar o serviço.</p></div></div>'
            . '<section class="panel checkin-picker-panel"><div class="panel-header"><div><div class="panel-title">Atendimentos recentes</div><div class="panel-subtitle">Últimas 30 ordens de serviço desta empresa.</div></div><span class="checkin-picker-count">' . $cardCount . ' atendimento(s)</span></div>'
            . '<div class="panel-body"><div class="checkin-order-list">' . $cards . '</div></div></section></div>';
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

        $damageCards = '';
        foreach ($damages as $damage) {
            $severityClass = $damage->severity === 'Grave' ? 'badge-cancelado' : ($damage->severity === 'Média' ? 'badge-pendente' : 'badge-andamento');
            $damageCards .= '<article class="damage-card">'
                . '<div class="damage-card-heading"><div><strong>' . $this->e($damage->area) . '</strong><span>' . $this->e($damage->damage_type) . '</span></div><span class="badge ' . $severityClass . '">' . $this->e($damage->severity) . '</span></div>'
                . '<p>' . $this->e($damage->description ?: 'Sem descrição adicional.') . '</p>'
                . '<form method="POST" class="damage-card-actions" onsubmit="return confirm(\'Remover esta avaria?\')">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="delete_damage"><input type="hidden" name="order_id" value="' . $this->e($orderId) . '"><input type="hidden" name="damage_id" value="' . $this->e($damage->id) . '"><button class="btn btn-danger btn-sm" type="submit">Remover registro</button></form>'
                . '</article>';
        }
        if ($damageCards === '') {
            $damageCards = '<div class="damage-empty"><strong>Nenhuma avaria registrada</strong><span>Registre qualquer risco, amassado ou dano visível antes de iniciar o serviço.</span></div>';
        }

        $historyPanel = '';
        if (PlanGuard::allows('vehicle_history')) {
            if ($historyRows === '') {
                $historyRows = '<tr><td colspan="5" class="empty-state"><strong>Sem histórico anterior</strong><span>Esta pode ser a primeira passagem deste veículo.</span></td></tr>';
            }
            $historyPanel = '<section class="panel inspection-history-panel"><div class="panel-header"><div><div class="panel-title">Histórico do veículo</div><div class="panel-subtitle">Últimas passagens desta placa.</div></div></div><div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system"><thead><tr><th>O.S.</th><th>Data</th><th>Status</th><th>Avarias</th><th>Check-in</th></tr></thead><tbody>' . $historyRows . '</tbody></table></div></div></section>';
        }

        $status = OrderStatus::normalize((string)$order->status);
        $orderShort = $this->e(ltrim(OrderIdentity::label($order), '#'));
        $vehicleName = trim((string)$vehicle->brand . ' ' . (string)$vehicle->model);
        $vehicleMeta = array_filter([
            trim('Placa ' . (string)$vehicle->plate),
            trim((string)($vehicle->color ?? '')),
            trim((string)($vehicle->category ?? '')),
        ], static fn(string $value): bool => $value !== '' && $value !== 'Placa');
        $checkinStatusLabel = $checkin ? 'Entrada registrada' : 'Aguardando check-in';
        $checkinStatusClass = $checkin ? 'is-done' : 'is-pending';
        $entryButtonLabel = $checkin ? 'Salvar alterações do check-in' : 'Confirmar entrada e iniciar serviço';

        echo '<div class="operational-page inspection-page">'
            . '<div class="page-heading inspection-page-heading"><div><a class="back-link" href="index.php?class=AgendaController">← Voltar para Agenda</a><span class="eyebrow">OPERAÇÃO · O.S. #' . $orderShort . '</span><h1>Check-in do veículo</h1><p>Confira os dados e registre a condição do veículo antes de começar o atendimento.</p></div><div class="inspection-heading-status"><span class="badge ' . OrderStatus::badgeClass((string)$order->status) . '">' . $this->e($status) . '</span><span class="checkin-state ' . $checkinStatusClass . '">' . $checkinStatusLabel . '</span></div></div>'
            . '<section class="inspection-summary" aria-label="Resumo do atendimento">'
            . '<article class="inspection-summary-card inspection-summary-vehicle"><span>VEÍCULO</span><strong>' . $this->e($vehicleName !== '' ? $vehicleName : 'Veículo') . '</strong><small>' . $this->e(implode(' · ', $vehicleMeta)) . '</small></article>'
            . '<article class="inspection-summary-card"><span>CLIENTE</span><strong>' . $this->e($client->name) . '</strong><small>' . $this->e($client->phone ?: 'Telefone não informado') . '</small></article>'
            . '<article class="inspection-summary-card"><span>ORDEM DE SERVIÇO</span><strong>#' . $orderShort . '</strong><small>Valor: R$ ' . number_format((float)$order->total_amount, 2, ',', '.') . '</small></article>'
            . '</section>'
            . '<div class="inspection-grid">'
            . '<section class="panel inspection-panel inspection-entry-panel"><div class="panel-header"><div><div class="panel-title">01 · Entrada do veículo</div><div class="panel-subtitle">Registre quilometragem, combustível, itens recebidos e observações.</div></div><span class="inspection-step-number">01</span></div>'
            . '<div class="panel-body"><form method="POST" class="inspection-form">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="save_checkin"><input type="hidden" name="order_id" value="' . $this->e($orderId) . '">'
            . '<div class="form-grid cols-2 inspection-fields"><div class="form-group"><label for="checkin_odometer">Quilometragem (km)</label><input id="checkin_odometer" class="form-control" type="number" min="0" inputmode="numeric" name="odometer" value="' . $odometer . '" placeholder="Ex.: 45890"></div><div class="form-group"><label for="checkin_fuel">Combustível (%)</label><input id="checkin_fuel" class="form-control" type="number" min="0" max="100" inputmode="numeric" name="fuel_level" value="' . $fuel . '" placeholder="Ex.: 50"></div></div>'
            . '<fieldset class="checklist-fieldset"><legend>Itens recebidos junto com o veículo</legend><div class="checklist-options">'
            . '<label class="checklist-option"><input type="checkbox" name="key_received"' . $isChecked($checkin->key_received ?? true) . '><span>Chave recebida</span></label>'
            . '<label class="checklist-option"><input type="checkbox" name="spare_tire"' . $isChecked($checkin->spare_tire ?? false) . '><span>Estepe presente</span></label>'
            . '<label class="checklist-option"><input type="checkbox" name="jack"' . $isChecked($checkin->jack ?? false) . '><span>Macaco presente</span></label>'
            . '<label class="checklist-option"><input type="checkbox" name="wheel_wrench"' . $isChecked($checkin->wheel_wrench ?? false) . '><span>Chave de roda</span></label>'
            . '<label class="checklist-option"><input type="checkbox" name="documents_received"' . $isChecked($checkin->documents_received ?? false) . '><span>Documentos recebidos</span></label>'
            . '</div></fieldset>'
            . '<div class="form-group"><label for="checkin_accessories">Acessórios e objetos deixados no veículo</label><textarea id="checkin_accessories" class="form-control" name="accessories" rows="3" placeholder="Ex.: tapetes, cadeirinha, carregador, objetos pessoais...">' . $accessories . '</textarea><small class="field-help">Registre itens que devem permanecer no veículo durante o serviço.</small></div>'
            . '<div class="form-group"><label for="checkin_observations">Observações de entrada</label><textarea id="checkin_observations" class="form-control" name="observations" rows="4" placeholder="Condições gerais, instruções do cliente, cuidados especiais...">' . $observations . '</textarea></div>'
            . '<div class="inspection-actions"><button class="btn btn-primary btn-lg" type="submit">' . $entryButtonLabel . '</button></div></form></div></section>'
            . '<section class="panel inspection-panel inspection-damage-panel"><div class="panel-header"><div><div class="panel-title">02 · Registrar avaria</div><div class="panel-subtitle">Documente qualquer dano visível e evite dúvidas na retirada.</div></div><span class="inspection-step-number">02</span></div>'
            . '<div class="panel-body"><form method="POST" class="inspection-form damage-form">' . \App\Core\Security::csrfInput() . '<input type="hidden" name="action" value="add_damage"><input type="hidden" name="order_id" value="' . $this->e($orderId) . '">'
            . '<div class="form-grid cols-2"><div class="form-group"><label for="damage_area">Área<span class=req>&nbsp;*</span></label><select id="damage_area" class="form-control" name="area" required><option value="">Selecione a área</option><option>Frente</option><option>Traseira</option><option>Lateral esquerda</option><option>Lateral direita</option><option>Teto</option><option>Rodas</option><option>Vidros</option><option>Interior</option></select></div>'
            . '<div class="form-group"><label for="damage_type">Tipo<span class=req>&nbsp;*</span></label><select id="damage_type" class="form-control" name="damage_type" required><option value="">Selecione o tipo</option><option>Risco</option><option>Arranhão</option><option>Amassado</option><option>Trinca</option><option>Mancha</option><option>Pintura</option><option>Outro</option></select></div>'
            . '<div class="form-group"><label for="damage_severity">Gravidade</label><select id="damage_severity" class="form-control" name="severity"><option>Leve</option><option>Média</option><option>Grave</option></select></div>'
            . '<div class="form-group span-2"><label for="damage_description">Descrição</label><input id="damage_description" class="form-control" name="description" maxlength="500" placeholder="Ex.: risco horizontal na porta dianteira direita"></div></div>'
            . '<div class="inspection-actions"><button class="btn btn-secondary" type="submit">+ Adicionar avaria</button></div></form></div></section></div>'
            . '<section class="panel inspection-damages-panel"><div class="panel-header"><div><div class="panel-title">03 · Avarias registradas</div><div class="panel-subtitle">Danos vinculados a esta ordem de serviço.</div></div><span class="badge badge-pendente">' . count($damages) . ' registro(s)</span></div><div class="panel-body"><div class="damage-list">' . $damageCards . '</div></div></section>'
            . $historyPanel
            . '</div>';

    }
}
