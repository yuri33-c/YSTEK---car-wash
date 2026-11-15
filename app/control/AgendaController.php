<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use App\Model\ServiceOrder;
use App\Model\Client;
use App\Model\Vehicle;
use App\Model\Service;
use App\Service\OrderStatus;

/**
 * Controller MVC: recebe a requisição, aplica regras de fluxo e prepara os dados
 * para a camada de apresentação. Persistência fica nos Models e regras reutilizáveis
 * ficam em Services.
 */
class AgendaController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    public function process()
    {
        // Atualiza o status do atendimento diretamente pela Agenda.
        // O ID sempre é validado junto do tenant atual para impedir alteração cruzada.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'update_order_status') {
            try {
                if (!\App\Core\Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                    throw new \Exception('Solicitação inválida.');
                }
                $id = trim((string)($_POST['id'] ?? ''));
                $status = trim((string)($_POST['status'] ?? ''));
                $allowed = OrderStatus::all();
                if ($id === '' || !in_array($status, $allowed, true)) {
                    throw new \Exception('Status do atendimento inválido.');
                }

                $pdo = TTransaction::open();
                $pdo->beginTransaction();
                $objects = ServiceOrder::getObjects(
                    'id = :id AND tenant_id = :tenant_id',
                    [':id' => $id, ':tenant_id' => $_SESSION['tenant_id']]
                );
                if (empty($objects)) {
                    throw new \Exception('Atendimento não encontrado.');
                }
                $order = $objects[0];
                $previousStatus = OrderStatus::normalize((string)$order->status);
                $order->status = $status;
                if ($status === OrderStatus::FINISHED && empty($order->completion_time)) {
                    $order->completion_time = date('Y-m-d H:i:s');
                }
                if ($status !== OrderStatus::FINISHED) $order->completion_time = null;
                $order->store();
                $orderController = new OrdersController();
                if ($status === OrderStatus::FINISHED) {
                    $orderController->ensureCompletionFinancials($order);
                } elseif ($status === OrderStatus::CANCELED || $previousStatus === OrderStatus::FINISHED) {
                    $orderController->cancelOrderFinancials((string)$order->id);
                }
                $pdo->commit();
                TTransaction::close();

                if ($status === OrderStatus::FINISHED) {
                    header('Location: index.php?class=OrdersController&completed_id=' . rawurlencode($id), true, 303);
                    exit;
                }

                \Adianti\Widget\TMessage::add('success', 'Status do atendimento atualizado.');
            } catch (\Throwable $e) {
                try {
                    if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                    TTransaction::close();
                } catch (\Throwable $ignore) {}
                error_log('Agenda status error: ' . $e->getMessage());
                \Adianti\Widget\TMessage::add('error', 'Não foi possível atualizar o status do atendimento.');
            }

            $date = trim((string)($_POST['return_date'] ?? date('Y-m-d')));
            header('Location: index.php?class=AgendaController&date=' . rawurlencode($date));
            exit;
        }
    }

    public function show()
    {
        $date = trim((string)($_GET['date'] ?? date('Y-m-d')));
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$parsedDate || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $parsedDate->format('Y-m-d') !== $date) {
            $date = date('Y-m-d');
        }
        $status = trim((string)($_GET['status'] ?? ''));
        $q = trim((string)($_GET['q'] ?? ''));
        $status = OrderStatus::normalize($status);
        if ($status !== '' && !in_array($status, OrderStatus::all(), true)) $status = '';

        TTransaction::open();
        $conditions = 'tenant_id = :tenant AND entry_time >= :start AND entry_time < :end';
        $params = [
            ':tenant' => $_SESSION['tenant_id'],
            ':start' => $date . ' 00:00:00',
            ':end' => (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00'),
        ];
        if ($status !== '') {
            $storedStatuses = OrderStatus::storedValues($status);
            $statusParams = [];
            foreach ($storedStatuses as $index => $storedStatus) {
                $key = ':status_' . $index;
                $statusParams[] = $key;
                $params[$key] = $storedStatus;
            }
            $conditions .= ' AND status IN (' . implode(',', $statusParams) . ')';
        }
        $orders = ServiceOrder::getObjects($conditions, $params);
        $clients = Client::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']]);
        $vehicles = Vehicle::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']]);
        TTransaction::close();

        $clientMap = [];
        $clientContactMap = [];
        foreach ($clients as $c) {
            $clientMap[(string)$c->id] = (string)$c->name;
            $clientContactMap[(string)$c->id] = [
                'phone' => (string)($c->phone ?? ''),
                'document' => (string)($c->document ?? ''),
            ];
        }
        $vehicleMap = [];
        $vehicleDataMap = [];
        foreach ($vehicles as $v) {
            $vehicleMap[(string)$v->id] = trim((string)$v->plate . ' · ' . (string)$v->brand . ' ' . (string)$v->model);
            $vehicleDataMap[(string)$v->id] = [
                'plate' => (string)($v->plate ?? ''),
                'brand' => (string)($v->brand ?? ''),
                'model' => (string)($v->model ?? ''),
                'color' => (string)($v->color ?? ''),
                'category' => (string)($v->category ?? ''),
            ];
        }
        $serviceNameMap = [];
        $serviceDescriptionMap = [];
        $serviceItemsByOrder = [];
        TTransaction::open();
        try {
            $pdo = TTransaction::get();
            $snapshotColumn = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_name_snapshot')
                ? ', soi.service_name_snapshot'
                : '';
            $descriptionColumn = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_description_snapshot')
                ? ', soi.service_description_snapshot'
                : '';
            $stmt = $pdo->prepare('SELECT soi.service_order_id, soi.service_id' . $snapshotColumn . $descriptionColumn . ' FROM service_order_items soi INNER JOIN service_orders so ON so.id = soi.service_order_id WHERE so.tenant_id = :tenant');
            $stmt->execute([':tenant' => $_SESSION['tenant_id']]);
            $itemRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($itemRows as $itemRow) {
                $serviceItemsByOrder[(string)$itemRow['service_order_id']] = [
                    'service_id' => (string)$itemRow['service_id'],
                    'service_name_snapshot' => (string)($itemRow['service_name_snapshot'] ?? ''),
                    'service_description_snapshot' => (string)($itemRow['service_description_snapshot'] ?? ''),
                ];
            }
            $services = Service::getObjects('tenant_id = :t', [':t' => $_SESSION['tenant_id']]);
            foreach ($services as $svc) {
                $serviceNameMap[(string)$svc->id] = (string)$svc->name;
                $serviceDescriptionMap[(string)$svc->id] = (string)($svc->description ?? '');
            }
        } finally {
            TTransaction::close();
        }

        // A pesquisa é aplicada apenas aos dados do dia selecionado, sem alterar o histórico salvo.
        if ($q !== '') {
            $needle = mb_strtolower($q, 'UTF-8');
            $needleDigits = preg_replace('/\D+/', '', $q);
            $orders = array_values(array_filter($orders, function ($o) use ($needle, $needleDigits, $clientMap, $clientContactMap, $vehicleMap) {
                $clientId = (string)$o->client_id;
                $vehicleId = (string)$o->vehicle_id;
                $hay = mb_strtolower(
                    ($clientMap[$clientId] ?? ($o->client_name_snapshot ?? '')) . ' ' . ($o->vehicle_plate_snapshot ?? '') . ' ' . ($o->vehicle_desc_snapshot ?? '') . ' ' .
                    ($clientContactMap[$clientId]['phone'] ?? ($o->client_phone_snapshot ?? '')) . ' ' .
                    ($clientContactMap[$clientId]['document'] ?? '') . ' ' .
                    ($vehicleMap[$vehicleId] ?? ''),
                    'UTF-8'
                );
                if ($needle !== '' && mb_strpos($hay, $needle, 0, 'UTF-8') !== false) return true;
                if ($needleDigits !== '') {
                    $digitsHay = preg_replace('/\D+/', '',
                        ($clientContactMap[$clientId]['phone'] ?? '') . ' ' .
                        ($clientContactMap[$clientId]['document'] ?? '') . ' ' .
                        ($vehicleMap[$vehicleId] ?? '')
                    );
                    if ($needleDigits !== '' && strpos($digitsHay, $needleDigits) !== false) return true;
                }
                return false;
            }));
        }

        usort($orders, fn($a, $b) => strcmp((string)$a->entry_time, (string)$b->entry_time));
        $dateLabel = date('d/m/Y', strtotime($date));
        $rows = '';
        $detailModals = '';
        foreach ($orders as $o) {
            $canonicalStatus = OrderStatus::normalize((string)$o->status);
            $stClass = OrderStatus::badgeClass($canonicalStatus);

            $time = $o->entry_time ? date('H:i', strtotime((string)$o->entry_time)) : '--:--';
            $clientId = (string)$o->client_id;
            $vehicleId = (string)$o->vehicle_id;
            $snapName = trim((string)($o->client_name_snapshot ?? ''));
            $client = $this->e($clientMap[$clientId] ?? ($snapName !== '' ? $snapName : 'Cliente não informado'));
            $phoneRaw = (string)($clientContactMap[$clientId]['phone'] ?? ($o->client_phone_snapshot ?? ''));
            $documentRaw = (string)($clientContactMap[$clientId]['document'] ?? '');
            $phone = $this->e($phoneRaw !== '' ? $phoneRaw : 'Telefone não cadastrado');
            $document = $this->e($documentRaw !== '' ? $documentRaw : 'CPF/CNPJ não cadastrado');

            $vehicle = $vehicleDataMap[$vehicleId] ?? [];
            $vehicleTitle = trim(($vehicle['brand'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
            if ($vehicleTitle === '') $vehicleTitle = trim((string)($o->vehicle_desc_snapshot ?? ''));
            if ($vehicleTitle === '') $vehicleTitle = 'Veículo não informado';
            $vehicleTitle = $this->e($vehicleTitle);
            $plate = $this->e((string)($vehicle['plate'] ?? ((trim((string)($o->vehicle_plate_snapshot ?? '')) !== '') ? $o->vehicle_plate_snapshot : 'Sem placa')));
            $color = $this->e((string)($vehicle['color'] ?? 'Cor não informada'));

            $serviceItem = $serviceItemsByOrder[(string)$o->id] ?? [];
            $serviceId = $serviceItem['service_id'] ?? '';
            $serviceName = $serviceNameMap[$serviceId]
                ?? ($serviceItem['service_name_snapshot'] ?? 'Serviço não informado');
            $serviceDescription = $serviceDescriptionMap[$serviceId]
                ?? ($serviceItem['service_description_snapshot'] ?? '');
            $serviceNameEsc = $this->e($serviceName);
            $serviceDescriptionEsc = $this->e($serviceDescription);
            $value = 'R$ ' . number_format((float)$o->total_amount, 2, ',', '.');
            $serviceDetails = ($serviceDescriptionEsc !== '' ? $serviceDescriptionEsc . ' · ' : '') . $value;
            $paymentStatus = $this->e((string)($o->payment_status ?? 'Pendente'));
            $paymentMethod = $this->e((string)($o->payment_method ?? 'Não informado'));
            $washerName = trim((string)($o->washer_name_snapshot ?? ''));
            $washerName = $washerName !== '' ? $washerName : 'Não definido';
            $washerEsc = $this->e($washerName);
            $commission = number_format((float)($o->washer_commission_percent ?? 0), 1, ',', '.') . '%';

            $statusOptions = '';
            foreach (OrderStatus::all() as $statusValue) {
                $statusLabel = $statusValue;
                $selected = ($canonicalStatus === $statusValue) ? ' selected' : '';
                $statusOptions .= '<option value="' . $this->e($statusValue) . '"' . $selected . '>' . $this->e($statusLabel) . '</option>';
            }
            $statusSelect = '<form method="POST" action="index.php?class=AgendaController" class="inline-status-form">'
                . \App\Core\Security::csrfInput()
                . '<input type="hidden" name="action" value="update_order_status">'
                . '<input type="hidden" name="id" value="' . $this->e($o->id) . '">'
                . '<input type="hidden" name="return_date" value="' . $this->e($date) . '">'
                . '<select name="status" class="status-select ' . $this->e($stClass) . '" title="Alterar status" onchange="this.form.submit()">'
                . $statusOptions . '</select></form>';

            $detailId = 'agenda_detail_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$o->id);
            $detailModal = '<div class="agenda-detail-modal" id="' . $detailId . '" aria-hidden="true">
                <div class="agenda-detail-backdrop" onclick="closeAgendaDetail(this.closest(".agenda-detail-modal").id)"></div>
                <div class="agenda-detail-dialog" role="dialog" aria-modal="true" aria-labelledby="' . $this->e($detailId) . '_title">
                    <div class="agenda-detail-header"><div><div class="eyebrow">Atendimento</div><h3 id="' . $this->e($detailId) . '_title">' . $client . '</h3><div class="agenda-detail-sub">' . $phone . '</div></div><button type="button" class="agenda-detail-close" onclick="closeAgendaDetail(this.closest(".agenda-detail-modal").id)">×</button></div>
                    <div class="agenda-detail-grid">
                        <div><span>Veículo</span><strong>' . $vehicleTitle . '</strong><small>' . $plate . ' · ' . $color . '</small></div>
                        <div><span>Serviço</span><strong>' . $serviceNameEsc . '</strong><small>' . $serviceDetails . '</small></div>
                        <div><span>Lavador</span><strong>' . $washerEsc . '</strong><small>Comissão: ' . $this->e($commission) . '</small></div>
                        <div><span>Pagamento</span><strong>' . $paymentStatus . '</strong><small>' . $paymentMethod . '</small></div>
                        <div><span>CPF/CNPJ</span><strong>' . $document . '</strong><small>Horário: ' . $this->e($time) . '</small></div>
                        <div><span>Status</span><strong>' . $this->e($canonicalStatus) . '</strong><small>O.S. ' . $this->e(\App\Service\OrderIdentity::label($o)) . '</small></div>
                    </div>
                    <div class="agenda-detail-notes"><span>Observações</span><p>' . nl2br($this->e((string)($o->notes ?? 'Sem observações.'))) . '</p></div>
                </div>
            </div>';
            $detailModals .= $detailModal;

            $rows .= '<tr>
                <td class="agenda-time"><strong>' . $time . '</strong><div class="table-secondary">' . $this->e($dateLabel ?? '') . '</div></td>
                <td><strong class="agenda-primary-text">' . $client . '</strong><div class="table-secondary">' . $phone . '</div></td>
                <td><strong>' . $vehicleTitle . '</strong><div class="table-secondary">' . $plate . ' · ' . $color . '</div></td>
                <td><strong>' . $serviceNameEsc . '</strong></td>
                <td><strong>' . $value . '</strong></td>
                <td>' . $statusSelect . '</td>
                <td class="actions-cell"><button type="button" class="btn btn-secondary btn-sm" onclick="openAgendaDetail(this.dataset.id)" data-id="' . $this->e($detailId) . '">Ver detalhes</button>' . (\App\Service\PlanCatalog::allows(\App\Service\PlanGuard::currentPlan(), 'checkin_checklist') ? '<a class="btn btn-primary btn-sm" href="index.php?class=OperationalController&order_id=' . rawurlencode((string)$o->id) . '">Check-in</a>' : '') . '</td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="empty-state"><strong>Nenhum atendimento encontrado</strong><span>O histórico permanece salvo. Selecione outra data ou altere a pesquisa.</span></td></tr>';
        }

        $prevDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
        $statusButtons = '';
        $statusFilters = ['' => 'Todos'];
        foreach (OrderStatus::all() as $filterStatus) $statusFilters[$filterStatus] = $filterStatus;
        foreach ($statusFilters as $value => $label) {
            $active = $status === $value ? ' is-active' : '';
            $href = 'index.php?class=AgendaController&date=' . rawurlencode($date);
            if ($q !== '') $href .= '&q=' . rawurlencode($q);
            if ($value !== '') $href .= '&status=' . rawurlencode($value);
            $statusButtons .= '<a class="filter-chip' . $active . '" href="' . $href . '">' . $this->e($label) . '</a>';
        }
        $prevHref = 'index.php?class=AgendaController&date=' . rawurlencode($prevDate) . ($status !== '' ? '&status=' . rawurlencode($status) : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
        $nextHref = 'index.php?class=AgendaController&date=' . rawurlencode($nextDate) . ($status !== '' ? '&status=' . rawurlencode($status) : '') . ($q !== '' ? '&q=' . rawurlencode($q) : '');
        $todayHref = 'index.php?class=AgendaController&date=' . rawurlencode(date('Y-m-d')) . ($status !== '' ? '&status=' . rawurlencode($status) : '');

        echo '<div class="page-heading">
            <div><div class="eyebrow">Operação</div><h1>Agenda</h1><p>Horários e status dos atendimentos do dia.</p></div>
            <div class="heading-actions"><a class="btn btn-primary btn-lg" href="index.php?class=OrdersController&new=1">+ Novo Agendamento</a></div>
        </div>
        <div class="panel agenda-filter-panel">
            <div class="panel-body"><form class="agenda-toolbar" method="GET">
                <input type="hidden" name="class" value="AgendaController">
                <div><label>Data</label><input class="form-control" type="date" name="date" value="' . $this->e($date) . '"></div>
                <div><label>Pesquisar cliente</label><input class="form-control" type="search" name="q" value="' . $this->e($q) . '" placeholder="Nome, CPF, telefone ou placa"></div>
                <div class="agenda-chips">' . $statusButtons . '</div>
                <div class="agenda-date-actions"><a class="btn btn-secondary" href="' . $prevHref . '">‹ Anterior</a><a class="btn btn-secondary" href="' . $todayHref . '">Hoje</a><a class="btn btn-secondary" href="' . $nextHref . '">Próximo ›</a></div>
                <button class="btn btn-primary" type="submit">Aplicar</button>
            </form></div>
        </div>
        <div class="panel">
            <div class="panel-header"><div><div class="panel-title">' . $dateLabel . '</div><div class="panel-subtitle">' . count($orders) . ' atendimento(s) no período</div></div></div>
            <div class="panel-body table-body-compact"><div class="table-responsive"><table class="table-system agenda-table"><thead><tr><th>Horário</th><th>Cliente</th><th>Veículo / Placa</th><th>Serviço</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead><tbody>' . $rows . '</tbody></table></div></div>
        </div>
        ' . $detailModals . '
        <script>
            function openAgendaDetail(id){
                var el=document.getElementById(id);
                if(!el) return;
                el.classList.add("is-open");
                el.setAttribute("aria-hidden","false");
                document.body.classList.add("modal-open");
            }
            function closeAgendaDetail(id){
                var el=document.getElementById(id);
                if(!el) return;
                el.classList.remove("is-open");
                el.setAttribute("aria-hidden","true");
                document.body.classList.remove("modal-open");
            }
        </script>';
    }
}
