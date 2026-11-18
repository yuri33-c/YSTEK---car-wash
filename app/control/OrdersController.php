<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Model\ServiceOrder;
use App\Model\ServiceOrderItem;
use App\Model\Client;
use App\Model\Vehicle;
use App\Model\Service;
use App\Model\User;
use App\Model\FinancialRecord;
use App\Service\PlanGuard;
use App\Service\OrderStatus;
use App\Service\OrderIdentity;
use App\Service\WhatsAppService;

/**
 * Controller de Ordens de Serviço.
 *
 * A tela concentra cliente, veículo, serviço, lavador, pagamento e observações
 * em um único fluxo. O banco continua sendo a fonte de verdade para tenant,
 * preço do serviço e percentual do lavador.
 */
class OrdersController extends TPage
{
    private function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /** Converte moeda brasileira para float. */
    private function parseMoney(string $value): float
    {
        $value = trim($value);
        if ($value === '') return 0.0;
        $normalized = str_replace('.', '', $value);
        $normalized = str_replace(',', '.', $normalized);
        if (!is_numeric($normalized)) throw new \Exception('Informe um valor numérico válido.');
        return (float)$normalized;
    }

    private function validateStatus(string $status): void
    {
        if (!in_array($status, OrderStatus::all(), true)) {
            throw new \Exception('Status da O.S. inválido.');
        }
    }

    private function appointmentTimestamp(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d\TH:i') !== $value) {
            throw new \Exception('Informe uma data e horário válidos para o agendamento.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function phoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (strlen($digits) < 10 || strlen($digits) > 13) {
            throw new \Exception('Informe um telefone válido com DDD.');
        }
        return $digits;
    }

    private function validatePayment(string $status, string $method): void
    {
        if (!in_array($status, ['Pendente', 'Pago'], true)) throw new \Exception('Status de pagamento inválido.');
        if (!in_array($method, ['Pix', 'Cartao_Credito', 'Cartao_Debito', 'Dinheiro'], true)) {
            throw new \Exception('Forma de pagamento inválida.');
        }
    }

    /** Reuses a customer by tenant-scoped phone before creating a new record. */
    private function createClientIfNeeded(string $clientId, string $name, string $phone): string
    {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '' || strlen($name) > 100) throw new \Exception('Informe um nome de cliente válido.');
        $digits = $this->phoneDigits($phone);
        $tenantId = (string)$_SESSION['tenant_id'];
        $pdo = TTransaction::get();
        $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
        $lock->execute([':lock_key' => 'client:' . $tenantId . ':' . $digits]);
        if ($clientId !== '') {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE id = :id AND tenant_id = :tenant_id AND regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone LIMIT 1");
            $stmt->execute([':id' => $clientId, ':tenant_id' => $tenantId, ':phone' => $digits]);
            if ($stmt->fetchColumn()) return $clientId;
            throw new \Exception('O cliente selecionado não corresponde ao telefone informado.');
        }

        $stmt = $pdo->prepare("SELECT id FROM clients WHERE tenant_id = :tenant_id AND regexp_replace(COALESCE(phone, ''), '[^0-9]', '', 'g') = :phone ORDER BY created_at ASC NULLS LAST, id ASC LIMIT 1");
        $stmt->execute([':tenant_id' => $tenantId, ':phone' => $digits]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) return (string)$existingId;

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE tenant_id = :tenant_id');
        $countStmt->execute([':tenant_id' => $tenantId]);
        $count = (int)$countStmt->fetchColumn();
        PlanGuard::enforceLimit('clients', $count);
        $client = new Client();
        $client->tenant_id = $tenantId;
        $client->name = $name;
        $client->phone = $phone;
        $client->store();
        return (string)$client->id;
    }

    /** Reuses a matching tenant vehicle only when it belongs to the resolved customer. */
    private function createVehicleIfNeeded(string $vehicleId, string $clientId, array $data): string
    {
        $plate = strtoupper(trim((string)($data['plate'] ?? '')));
        $brand = trim((string)($data['brand'] ?? ''));
        $model = trim((string)($data['model'] ?? ''));
        $color = trim((string)($data['color'] ?? ''));
        $category = trim((string)($data['category'] ?? 'Passeio'));
        $plateKey = preg_replace('/[^A-Z0-9]/', '', $plate);
        if (!preg_match('/^[A-Z]{3}-?[0-9][A-Z0-9][0-9]{2}$/', $plate)) {
            throw new \Exception('Informe uma placa válida.');
        }
        if ($brand === '' || $model === '') throw new \Exception('Informe a marca e o modelo do veículo.');
        if (strlen($plate) > 10 || strlen($brand) > 50 || strlen($model) > 50 || strlen($color) > 30 || strlen($category) > 30) {
            throw new \Exception('Um dos dados do veículo ultrapassou o limite permitido.');
        }

        $pdo = TTransaction::get();
        $tenantId = (string)$_SESSION['tenant_id'];
        $lock = $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
        $lock->execute([':lock_key' => 'vehicle:' . $tenantId . ':' . $plateKey]);

        if ($vehicleId !== '') {
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE id = :id AND tenant_id = :tenant_id AND client_id = :client_id AND regexp_replace(upper(plate), '[^A-Z0-9]', '', 'g') = :plate LIMIT 1");
            $stmt->execute([':id' => $vehicleId, ':tenant_id' => $tenantId, ':client_id' => $clientId, ':plate' => $plateKey]);
            if ($stmt->fetchColumn()) return $vehicleId;
            throw new \Exception('O veículo selecionado não corresponde à placa ou ao cliente informado.');
        }

        $stmt = $pdo->prepare("SELECT id, client_id FROM vehicles WHERE tenant_id = :tenant_id AND regexp_replace(upper(plate), '[^A-Z0-9]', '', 'g') = :plate ORDER BY created_at ASC NULLS LAST, id ASC LIMIT 1");
        $stmt->execute([':tenant_id' => $tenantId, ':plate' => $plateKey]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            if ((string)$existing['client_id'] !== $clientId) {
                throw new \Exception('Esta placa já pertence a outro cliente cadastrado nesta empresa.');
            }
            return (string)$existing['id'];
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM vehicles WHERE tenant_id = :tenant_id');
        $countStmt->execute([':tenant_id' => $tenantId]);
        PlanGuard::enforceLimit('vehicles', (int)$countStmt->fetchColumn());
        $vehicle = new Vehicle();
        $vehicle->tenant_id = $tenantId;
        $vehicle->client_id = $clientId;
        $vehicle->plate = $plate;
        $vehicle->brand = $brand;
        $vehicle->model = $model;
        $vehicle->color = $color;
        $vehicle->category = $category;
        $vehicle->store();
        return (string)$vehicle->id;
    }

    /** Obtém o lavador e seu percentual do banco, sem confiar em percentual enviado pelo navegador. */
    private function loadWasher(string $washerId): array
    {
        if ($washerId === '') return ['id' => '', 'name' => '', 'percent' => 0.0];
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare("SELECT id, name, phone, commission_percent FROM users WHERE id = :id AND tenant_id = :tenant_id AND role = 'WASHER' AND active = TRUE LIMIT 1");
        $stmt->execute([':id' => $washerId, ':tenant_id' => $_SESSION['tenant_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) throw new \Exception('Lavador não encontrado ou inativo.');
        $percent = max(0.0, min(100.0, (float)($user['commission_percent'] ?? 0)));
        return ['id' => (string)$user['id'], 'name' => (string)$user['name'], 'percent' => $percent, 'phone' => (string)($user['phone'] ?? '')];
    }

    /** Registra receita da O.S. uma única vez. */
    private function ensureOrderRevenue(string $orderId, float $amount): void
    {
        $pdo = TTransaction::get();
        $description = "O.S. #{$orderId} - Atendimento";
        $check = $pdo->prepare("SELECT id FROM financial_records WHERE tenant_id = :tenant_id AND description = :description LIMIT 1");
        $check->execute([':tenant_id' => $_SESSION['tenant_id'], ':description' => $description]);
        $existingId = $check->fetchColumn();
        if ($existingId) {
            $pdo->prepare("UPDATE financial_records SET status = 'Pago' WHERE id = :id AND tenant_id = :tenant_id AND status = 'Cancelado'")
                ->execute([':id' => $existingId, ':tenant_id' => $_SESSION['tenant_id']]);
            return;
        }

        $record = new FinancialRecord();
        $record->tenant_id = $_SESSION['tenant_id'];
        $record->description = $description;
        $record->type = 'Receita';
        $record->amount = $amount;
        $record->category = 'Lava-Jato';
        $record->status = 'Pago';
        $record->store();
    }

    /** Registra a comissão como despesa pendente somente uma vez. */
    private function ensureWasherCommission(string $orderId, float $amount, string $washerName): void
    {
        if ($amount <= 0 || $washerName === '') return;
        $pdo = TTransaction::get();
        $description = "Comissão O.S. #{$orderId} - {$washerName}";
        $check = $pdo->prepare("SELECT id FROM financial_records WHERE tenant_id = :tenant_id AND description = :description LIMIT 1");
        $check->execute([':tenant_id' => $_SESSION['tenant_id'], ':description' => $description]);
        $existingId = $check->fetchColumn();
        if ($existingId) {
            $pdo->prepare("UPDATE financial_records SET status = 'Pendente' WHERE id = :id AND tenant_id = :tenant_id AND status = 'Cancelado'")
                ->execute([':id' => $existingId, ':tenant_id' => $_SESSION['tenant_id']]);
            return;
        }

        $record = new FinancialRecord();
        $record->tenant_id = $_SESSION['tenant_id'];
        $record->description = $description;
        $record->type = 'Despesa';
        $record->amount = $amount;
        $record->category = 'Comissão de Lavador';
        $record->status = 'Pendente';
        $record->store();
    }

    public function ensureCompletionFinancials(ServiceOrder $order): void
    {
        if ((float)($order->washer_commission_amount ?? 0) <= 0 && !empty($order->washer_id)) {
            $washer = $this->loadWasher((string)$order->washer_id);
            $order->washer_name_snapshot = $washer['name'];
            $order->washer_commission_percent = $washer['percent'];
            $order->washer_commission_amount = round((float)$order->total_amount * ($washer['percent'] / 100), 2);
            $order->store();
        }
        $this->ensureWasherCommission(
            (string)$order->id,
            (float)($order->washer_commission_amount ?? 0),
            (string)($order->washer_name_snapshot ?? '')
        );
        if ((string)$order->payment_status === 'Pago') {
            $this->ensureOrderRevenue((string)$order->id, (float)$order->total_amount);
        }
    }

    public function cancelOrderFinancials(string $orderId): void
    {
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare("UPDATE financial_records SET status = 'Cancelado' WHERE tenant_id = :tenant_id AND (description = :revenue OR description LIKE :commission) AND category IN ('Lava-Jato', 'Comissão de Lavador')");
        $stmt->execute([
            ':tenant_id' => $_SESSION['tenant_id'],
            ':revenue' => "O.S. #{$orderId} - Atendimento",
            ':commission' => "Comissão O.S. #{$orderId} - %",
        ]);
    }

    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;

        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['save_order', 'update_status'], true)) return;

        $pdo = null;
        try {
            if (!\App\Core\Security::verifyCsrf($_POST['_csrf'] ?? null)) {
                throw new \Exception('Solicitação inválida. Atualize a página e tente novamente.');
            }
            $pdo = TTransaction::open();
            $pdo->beginTransaction();
            $tenantId = (string)$_SESSION['tenant_id'];

            if ($action === 'save_order') {
                $id = trim((string)($_POST['id'] ?? ''));
                $os = null;
                if ($id !== '') {
                    $objects = ServiceOrder::getObjects('id = :id AND tenant_id = :tenant_id', [':id' => $id, ':tenant_id' => $tenantId]);
                    if (empty($objects)) throw new \Exception('Acesso negado à O.S. selecionada.');
                    $os = $objects[0];
                } else {
                    $os = new ServiceOrder();
                    $os->tenant_id = $tenantId;
                }

                $clientId = trim((string)($_POST['client_id'] ?? ''));
                $clientRemoved = $id !== '' && empty($os->client_id) && $clientId === '';
                if (!$clientRemoved) {
                    $clientId = $this->createClientIfNeeded(
                        $clientId,
                        trim((string)($_POST['client_name'] ?? '')),
                        trim((string)($_POST['client_phone'] ?? ''))
                    );
                }

                $vehicleId = trim((string)($_POST['vehicle_id'] ?? ''));
                if ($clientRemoved && $vehicleId !== '') {
                    $vehicleStmt = $pdo->prepare("SELECT id FROM public.vehicles
                        WHERE id = :id AND tenant_id = :tenant_id
                          AND regexp_replace(upper(plate), '[^A-Z0-9]', '', 'g') = :plate
                        LIMIT 1");
                    $vehicleStmt->execute([
                        ':id' => $vehicleId,
                        ':tenant_id' => $tenantId,
                        ':plate' => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_POST['vehicle_plate'] ?? ''))),
                    ]);
                    if (!$vehicleStmt->fetchColumn()) {
                        throw new \Exception('O veículo histórico não pertence a esta empresa ou a placa não corresponde.');
                    }
                } else {
                    $vehicleId = $this->createVehicleIfNeeded($vehicleId, $clientId, [
                        'plate' => $_POST['vehicle_plate'] ?? '',
                        'brand' => $_POST['vehicle_brand'] ?? '',
                        'model' => $_POST['vehicle_model'] ?? '',
                        'color' => $_POST['vehicle_color'] ?? '',
                        'category' => $_POST['vehicle_category'] ?? 'Passeio',
                    ]);
                }

                $serviceId = trim((string)($_POST['service_id'] ?? ''));
                $historicalItem = null;
                if ($serviceId === '' && $id !== '') {
                    $snapshotSelect = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_name_snapshot')
                        ? ', item.service_name_snapshot'
                        : '';
                    $itemStmt = $pdo->prepare('SELECT item.id, item.price' . $snapshotSelect . '
                        FROM public.service_order_items item
                        JOIN public.service_orders orders ON orders.id = item.service_order_id
                        WHERE orders.id = :order_id AND orders.tenant_id = :tenant_id AND item.service_id IS NULL
                        ORDER BY item.id LIMIT 1');
                    $itemStmt->execute([':order_id' => $id, ':tenant_id' => $tenantId]);
                    $historicalItem = $itemStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                }

                $service = null;
                if ($historicalItem === null) {
                    if ($serviceId === '') throw new \Exception('Selecione o serviço que será realizado.');
                    $serviceObjects = Service::getObjects('id = :id AND tenant_id = :tenant_id AND status = :status', [':id' => $serviceId, ':tenant_id' => $tenantId, ':status' => 'ativo']);
                    if (empty($serviceObjects)) throw new \Exception('Serviço não encontrado ou inativo.');
                    $service = $serviceObjects[0];
                }

                $washer = $this->loadWasher(trim((string)($_POST['washer_id'] ?? '')));
                $status = $id === ''
                    ? OrderStatus::SCHEDULED
                    : OrderStatus::normalize(trim((string)($_POST['status'] ?? OrderStatus::SCHEDULED)));
                $paymentStatus = trim((string)($_POST['payment_status'] ?? 'Pendente'));
                $paymentMethod = trim((string)($_POST['payment_method'] ?? 'Pix'));
                $notes = trim((string)($_POST['notes'] ?? ''));
                if ($notes !== '' && strlen($notes) > 20000) throw new \Exception('As observações ultrapassaram o limite permitido.');
                $this->validateStatus($status);
                $this->validatePayment($paymentStatus, $paymentMethod);
                $appointmentDate = trim((string)($_POST['appointment_date'] ?? ''));
                $appointmentTime = trim((string)($_POST['appointment_time'] ?? ''));
                $appointmentAt = $this->appointmentTimestamp($appointmentDate . 'T' . $appointmentTime);

                // O preço vem do cadastro do serviço, evitando manipulação do valor pelo navegador.
                $totalAmount = $historicalItem !== null
                    ? (float)$os->total_amount
                    : round((float)$service->price, 2);
                if ($totalAmount < 0 || $totalAmount > 99999999.99) throw new \Exception('Preço do serviço inválido.');

                $os->client_id = $clientId !== '' ? $clientId : null;
                $os->vehicle_id = $vehicleId;
                $os->status = $status;
                $os->total_amount = $totalAmount;
                $os->payment_status = $paymentStatus;
                $os->payment_method = $paymentMethod;
                $os->notes = $notes;
                $os->entry_time = $appointmentAt;
                $os->completion_time = $status === OrderStatus::FINISHED
                    ? ($os->completion_time ?: date('Y-m-d H:i:s'))
                    : null;

                $os->washer_id = $washer['id'] !== '' ? $washer['id'] : null;
                $os->washer_name_snapshot = $washer['name'];
                $os->washer_commission_percent = $washer['percent'];
                $commissionAmount = round($totalAmount * ($washer['percent'] / 100), 2);
                $os->washer_commission_amount = $commissionAmount;
                $os->store();

                // A O.S. trabalha com um serviço principal; a tabela de itens permite
                // evolução futura para múltiplos serviços sem quebrar o cadastro atual.
                $pdo = TTransaction::get();
                // Número curto da O.S. (só na criação) e retrato de cliente/veículo para o histórico.
                if ($id === '' || empty($os->order_number)) OrderIdentity::assignNumber($pdo, (string)$tenantId, (string)$os->id);
                OrderIdentity::snapshotOrder($pdo, (string)$tenantId, (string)$os->id);
                if ($historicalItem === null) {
                    $pdo->prepare('DELETE FROM service_order_items WHERE service_order_id = :id')->execute([':id' => $os->id]);
                    $item = new ServiceOrderItem();
                    $item->service_order_id = $os->id;
                    $item->service_id = $service->id;
                    $item->price = $totalAmount;
                    if (\App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_name_snapshot')) {
                        $item->service_name_snapshot = $service->name;
                    }
                    if (\App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_description_snapshot')) {
                        $item->service_description_snapshot = $service->description;
                    }
                    $item->store();
                }

                if ($status === OrderStatus::CANCELED) {
                    $this->cancelOrderFinancials((string)$os->id);
                } elseif ($status === OrderStatus::FINISHED) {
                    $this->ensureCompletionFinancials($os);
                } elseif ($paymentStatus === 'Pago') {
                    $this->ensureOrderRevenue((string)$os->id, $totalAmount);
                }

                $pdo->commit();
                TTransaction::close();

                if ($status === OrderStatus::FINISHED) {
                    header('Location: index.php?class=OrdersController&completed_id=' . rawurlencode((string)$os->id), true, 303);
                } else {
                    TMessage::add('success', $id !== '' ? 'Ordem de serviço atualizada com sucesso.' : 'Ordem de serviço criada com sucesso.');
                    header('Location: index.php?class=OrdersController', true, 303);
                }
                exit;
            }

            // Atualização rápida de status diretamente na lista.
            $id = trim((string)($_POST['id'] ?? ''));
            $status = trim((string)($_POST['status'] ?? ''));
            if ($id === '') throw new \Exception('O.S. não informada.');
            $objects = ServiceOrder::getObjects('id = :id AND tenant_id = :tenant_id', [':id' => $id, ':tenant_id' => $tenantId]);
            if (empty($objects)) throw new \Exception('Acesso negado.');
            $os = $objects[0];
            $previousStatus = OrderStatus::normalize((string)$os->status);
            $status = OrderStatus::normalize($status);
            $this->validateStatus($status);

            $os->status = $status;
            $os->completion_time = $status === OrderStatus::FINISHED
                ? ($os->completion_time ?: date('Y-m-d H:i:s'))
                : null;
            if ($status === OrderStatus::FINISHED && (float)($os->washer_commission_amount ?? 0) <= 0 && !empty($os->washer_id)) {
                $washer = $this->loadWasher((string)$os->washer_id);
                $os->washer_name_snapshot = $washer['name'];
                $os->washer_commission_percent = $washer['percent'];
                $os->washer_commission_amount = round(((float)$os->total_amount) * ($washer['percent'] / 100), 2);
            }
            $os->store();
            if ($status === OrderStatus::FINISHED) {
                $this->ensureCompletionFinancials($os);
            } elseif ($status === OrderStatus::CANCELED || $previousStatus === OrderStatus::FINISHED) {
                $this->cancelOrderFinancials((string)$os->id);
            }
            $pdo->commit();
            TTransaction::close();

            if ($status === OrderStatus::FINISHED) {
                header('Location: index.php?class=OrdersController&completed_id=' . rawurlencode((string)$os->id), true, 303);
            } else {
                TMessage::add('success', 'Status atualizado para ' . $status . '.');
                header('Location: index.php?class=OrdersController', true, 303);
            }
            exit;
        } catch (\Throwable $e) {
            try {
                if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
                TTransaction::close();
            } catch (\Throwable $ignore) {}
            error_log('Orders error: ' . $e->getMessage());
            TMessage::add('error', 'Não foi possível concluir a operação. ' . $e->getMessage());
            header('Location: index.php?class=OrdersController', true, 303);
            exit;
        }
    }

    public function show()
    {
        $tenantId = (string)$_SESSION['tenant_id'];
        $csrf = \App\Core\Security::csrfInput();

        // Lista paginada: evita carregar todo o histórico e serializar milhares
        // de registros no HTML/JavaScript a cada abertura da tela.
        $perPage = 25;
        $currentPage = max(1, (int)($_GET['page'] ?? 1));
        $totalOrders = 0;
        $totalPages = 1;
        $orders = [];
        $clientData = [];
        $vehicleData = [];
        $serviceData = [];
        $washerData = [];
        $serviceIdByOrder = [];
        $serviceNameByOrder = [];
        $servicePriceByOrder = [];
        $clients = [];
        $vehicles = [];
        $services = [];
        $washers = [];

        TTransaction::open();
        try {
            $pdo = TTransaction::get();
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM public.service_orders WHERE tenant_id = :tenant_id');
            $countStmt->execute([':tenant_id' => $tenantId]);
            $totalOrders = (int)$countStmt->fetchColumn();
            $totalPages = max(1, (int)ceil($totalOrders / $perPage));
            $currentPage = min($currentPage, $totalPages);
            $offset = ($currentPage - 1) * $perPage;

            $ordersStmt = $pdo->prepare('SELECT * FROM public.service_orders
                WHERE tenant_id = :tenant_id
                ORDER BY entry_time DESC, id DESC
                LIMIT :limit_rows OFFSET :offset_rows');
            $ordersStmt->bindValue(':tenant_id', $tenantId);
            $ordersStmt->bindValue(':limit_rows', $perPage, \PDO::PARAM_INT);
            $ordersStmt->bindValue(':offset_rows', $offset, \PDO::PARAM_INT);
            $ordersStmt->execute();
            foreach ($ordersStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $order = new ServiceOrder();
                $order->fromArray($row);
                $orders[] = $order;
            }

            $makeInParams = static function (string $prefix, array $ids, array &$binds): array {
                $placeholders = [];
                foreach ($ids as $index => $id) {
                    $key = ':' . $prefix . $index;
                    $placeholders[] = $key;
                    $binds[$key] = $id;
                }
                return $placeholders;
            };
            $clientIds = array_values(array_unique(array_filter(array_map(static fn($o) => (string)$o->client_id, $orders))));
            $vehicleIds = array_values(array_unique(array_filter(array_map(static fn($o) => (string)$o->vehicle_id, $orders))));
            $orderIds = array_values(array_unique(array_filter(array_map(static fn($o) => (string)$o->id, $orders))));

            if ($clientIds) {
                $binds = [':tenant_id' => $tenantId];
                $placeholders = $makeInParams('client_', $clientIds, $binds);
                $stmt = $pdo->prepare('SELECT id, name, phone, email, document, notes FROM public.clients WHERE tenant_id = :tenant_id AND id IN (' . implode(',', $placeholders) . ')');
                $stmt->execute($binds);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $client = new Client();
                    $client->fromArray($row);
                    $clients[] = $client;
                }
            }

            if ($vehicleIds) {
                $binds = [':tenant_id' => $tenantId];
                $placeholders = $makeInParams('vehicle_', $vehicleIds, $binds);
                $stmt = $pdo->prepare('SELECT id, client_id, plate, brand, model, color, category FROM public.vehicles WHERE tenant_id = :tenant_id AND id IN (' . implode(',', $placeholders) . ')');
                $stmt->execute($binds);
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $vehicle = new Vehicle();
                    $vehicle->fromArray($row);
                    $vehicles[] = $vehicle;
                }
            }

            $services = Service::getObjects("tenant_id = :t AND status = 'ativo'", [':t' => $tenantId]);
            $washers = User::getObjects("tenant_id = :t AND role = 'WASHER' AND active = TRUE", [':t' => $tenantId]);

            if ($orderIds) {
                $binds = [':tenant_id' => $tenantId];
                $placeholders = $makeInParams('order_', $orderIds, $binds);
                $snapshotColumn = \App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', 'service_name_snapshot')
                    ? ', i.service_name_snapshot'
                    : '';
                $stmtItems = $pdo->prepare('SELECT i.service_order_id, i.service_id, i.price' . $snapshotColumn . '
                    FROM public.service_order_items i
                    INNER JOIN public.service_orders o ON o.id = i.service_order_id
                    WHERE o.tenant_id = :tenant_id AND o.id IN (' . implode(',', $placeholders) . ')
                    ORDER BY i.id');
                $stmtItems->execute($binds);
                foreach ($stmtItems->fetchAll(\PDO::FETCH_ASSOC) as $itemRow) {
                    $orderId = (string)$itemRow['service_order_id'];
                    $serviceIdByOrder[$orderId] = (string)($itemRow['service_id'] ?? '');
                    $servicePriceByOrder[$orderId] = (float)$itemRow['price'];
                    if (!empty($itemRow['service_name_snapshot'])) {
                        $serviceNameByOrder[$orderId] = (string)$itemRow['service_name_snapshot'];
                    }
                }
            }

            foreach ($clients as $client) {
                $clientData[(string)$client->id] = [
                    'name' => (string)$client->name,
                    'phone' => (string)($client->phone ?? ''),
                    'email' => (string)($client->email ?? ''),
                    'document' => (string)($client->document ?? ''),
                    'notes' => (string)($client->notes ?? ''),
                ];
            }
            foreach ($vehicles as $vehicle) {
                $vehicleData[(string)$vehicle->id] = [
                    'client_id' => (string)$vehicle->client_id,
                    'plate' => (string)$vehicle->plate,
                    'brand' => (string)($vehicle->brand ?? ''),
                    'model' => (string)($vehicle->model ?? ''),
                    'color' => (string)($vehicle->color ?? ''),
                    'category' => (string)($vehicle->category ?? 'Passeio'),
                ];
            }
            foreach ($services as $service) {
                $serviceData[(string)$service->id] = [
                    'name' => (string)$service->name,
                    'price' => (float)$service->price,
                    'duration' => (int)($service->duration_minutes ?? 0),
                ];
            }
            foreach ($washers as $washer) {
                $washerData[(string)$washer->id] = [
                    'name' => (string)$washer->name,
                    'percent' => (float)($washer->commission_percent ?? 0),
                ];
            }
        } finally {
            TTransaction::close();
        }

        $clientMap = [];
        foreach ($clients as $c) $clientMap[(string)$c->id] = (string)$c->name;
        $vehicleMap = [];
        foreach ($vehicles as $v) $vehicleMap[(string)$v->id] = trim((string)$v->plate . ' · ' . (string)$v->brand . ' ' . (string)$v->model);

        $rows = '';
        foreach ($orders as $os) {
            $snapPlate = trim((string)($os->vehicle_plate_snapshot ?? ''));
            $snapVehicle = trim((string)($os->vehicle_desc_snapshot ?? ''));
            $snapClient = trim((string)($os->client_name_snapshot ?? ''));
            $clientRemoved = !isset($clientMap[(string)$os->client_id]) && $snapClient !== '';
            $vehicleRemoved = !isset($vehicleMap[(string)$os->vehicle_id]) && ($snapPlate !== '' || $snapVehicle !== '');
            $clientName = $clientMap[(string)$os->client_id] ?? ($snapClient !== '' ? $snapClient : 'Cliente');
            $vehicleDesc = $vehicleMap[(string)$os->vehicle_id] ?? ($vehicleRemoved ? trim($snapPlate . ' · ' . $snapVehicle, ' ·') : 'Veículo');
            $value = 'R$ ' . number_format((float)$os->total_amount, 2, ',', '.');
            $statusClass = OrderStatus::badgeClass((string)$os->status);
            $displayStatus = OrderStatus::normalize((string)$os->status);
            $washerLabel = !empty($os->washer_name_snapshot)
                ? $this->e($os->washer_name_snapshot) . ' · ' . number_format((float)$os->washer_commission_percent, 1, ',', '.') . '%'
                : 'Não definido';
            $actions = '';
            if (!in_array($displayStatus, [OrderStatus::FINISHED, OrderStatus::CANCELED], true)) {
                $actions .= "<form method='POST' action='index.php?class=OrdersController' class='inline-form'>{$csrf}<input type='hidden' name='action' value='update_status'><input type='hidden' name='id' value='" . $this->e($os->id) . "'><input type='hidden' name='status' value='" . OrderStatus::IN_SERVICE . "'><button type='submit' class='btn btn-sm btn-secondary'>Iniciar</button></form>";
                $actions .= "<form method='POST' action='index.php?class=OrdersController' class='inline-form'>{$csrf}<input type='hidden' name='action' value='update_status'><input type='hidden' name='id' value='" . $this->e($os->id) . "'><input type='hidden' name='status' value='" . OrderStatus::FINISHED . "'><button type='submit' class='btn btn-sm btn-success'>Finalizar</button></form>";
            }
            if (PlanGuard::allows('checkin_checklist')) {
                $actions .= "<a class='btn btn-sm btn-primary' href='index.php?class=OperationalController&order_id=" . rawurlencode((string)$os->id) . "'>Check-in</a>";
            }
            $actions .= "<button type='button' onclick='editOrder(" . htmlspecialchars(json_encode(array_merge($os->toArray(), ['service_id' => ($serviceIdByOrder[(string)$os->id] ?? ''), 'service_name_snapshot' => ($serviceNameByOrder[(string)$os->id] ?? ''), 'service_price_snapshot' => ($servicePriceByOrder[(string)$os->id] ?? $os->total_amount)]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . ")' class='btn btn-sm btn-secondary'>Editar</button>";

            $rows .= '<tr>
                <td><strong>' . $this->e(OrderIdentity::label($os)) . '</strong></td>
                <td><strong>' . $this->e($clientName) . '</strong>' . ($clientRemoved ? '<div class="table-secondary">Cadastro excluído</div>' : '') . '</td>
                <td><strong>' . $this->e($vehicleDesc) . '</strong>' . ($vehicleRemoved ? '<div class="table-secondary">Cadastro excluído</div>' : '') . '</td>
                <td><span class="badge ' . $statusClass . '">' . $this->e($displayStatus) . '</span></td>
                <td><strong>' . $value . '</strong><div class="table-secondary">' . $this->e($os->payment_status) . '</div></td>
                <td>' . date('d/m/Y H:i', strtotime($os->entry_time)) . '</td>
                <td><div class="order-actions">' . $actions . '</div><div class="table-secondary washer-label">Lavador: ' . $washerLabel . '</div></td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="empty-state"><strong>Nenhuma Ordem de Serviço cadastrada</strong><span>Crie o primeiro atendimento pelo botão acima.</span></td></tr>';
        }

        $pagination = '';
        if ($totalPages > 1) {
            $pagination .= '<nav class="pagination-bar" aria-label="Paginação de ordens">';
            if ($currentPage > 1) {
                $pagination .= '<a class="pagination-link" href="index.php?class=OrdersController&page=' . ($currentPage - 1) . '">Anterior</a>';
            }
            $startPage = max(1, $currentPage - 2);
            $endPage = min($totalPages, $currentPage + 2);
            for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++) {
                $activeClass = $pageNumber === $currentPage ? ' is-active' : '';
                $pagination .= '<a class="pagination-link' . $activeClass . '" href="index.php?class=OrdersController&page=' . $pageNumber . '"' . ($pageNumber === $currentPage ? ' aria-current="page"' : '') . '>' . $pageNumber . '</a>';
            }
            if ($currentPage < $totalPages) {
                $pagination .= '<a class="pagination-link" href="index.php?class=OrdersController&page=' . ($currentPage + 1) . '">Próxima</a>';
            }
            $pagination .= '<span class="pagination-caption">' . $totalOrders . ' O.S. · página ' . $currentPage . ' de ' . $totalPages . '</span></nav>';
        }

        $serviceOpts = '<option value="">Selecione um serviço</option>';
        foreach ($services as $s) $serviceOpts .= '<option value="' . $this->e($s->id) . '">' . $this->e($s->name) . ' — R$ ' . number_format((float)$s->price, 2, ',', '.') . '</option>';
        $washerOpts = '<option value="">Sem lavador definido</option>';
        foreach ($washers as $w) $washerOpts .= '<option value="' . $this->e($w->id) . '">' . $this->e($w->name) . ' — ' . number_format((float)($w->commission_percent ?? 0), 1, ',', '.') . '%</option>';

        $completedModal = '';
        $completedId = trim((string)($_GET['completed_id'] ?? ''));
        if ($completedId !== '') {
            $completed = [];
            $tenant = null;
            $settings = [];
            $c = null;
            $v = null;
            $s = null;
            $completedServiceName = '';
            $completedServiceDescription = '';

            try {
                TTransaction::open();
                $pdo = TTransaction::get();
                $completed = ServiceOrder::getObjects(
                    'id = :id AND tenant_id = :tenant_id',
                    [':id' => $completedId, ':tenant_id' => $tenantId]
                );
                $tenant = new \App\Model\Tenant($tenantId);

                // A configuração é isolada por empresa. A mensagem salva aqui
                // passa a ser a fonte do texto que será aberto no WhatsApp.
                $settingsStmt = $pdo->prepare('SELECT whatsapp_message, whatsapp_number
                    FROM public.tenant_settings WHERE tenant_id = :tenant_id LIMIT 1');
                $settingsStmt->execute([':tenant_id' => $tenantId]);
                $settings = $settingsStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

                if (!empty($completed)) {
                    $o = $completed[0];
                    if (!empty($o->client_id)) {
                        $clientsFound = Client::getObjects(
                            'id = :id AND tenant_id = :t',
                            [':id' => $o->client_id, ':t' => $tenantId]
                        );
                        $c = $clientsFound[0] ?? null;
                    }
                    if (!empty($o->vehicle_id)) {
                        $vehiclesFound = Vehicle::getObjects(
                            'id = :id AND tenant_id = :t',
                            [':id' => $o->vehicle_id, ':t' => $tenantId]
                        );
                        $v = $vehiclesFound[0] ?? null;
                    }

                    $items = ServiceOrderItem::getObjects('service_order_id = :id', [':id' => $completedId]);
                    if ($items) {
                        if (!empty($items[0]->service_id)) {
                            $servicesFound = Service::getObjects(
                                'id = :id AND tenant_id = :t',
                                [':id' => $items[0]->service_id, ':t' => $tenantId]
                            );
                            $s = $servicesFound[0] ?? null;
                        }

                        $snapshotColumns = [];
                        foreach (['service_name_snapshot', 'service_description_snapshot'] as $column) {
                            if (\App\Service\RecordDeletionService::hasColumn($pdo, 'service_order_items', $column)) {
                                $snapshotColumns[] = $column;
                            }
                        }
                        if ($snapshotColumns) {
                            $itemHistory = $pdo->prepare('SELECT ' . implode(', ', $snapshotColumns) . '
                                FROM public.service_order_items WHERE id = :id');
                            $itemHistory->execute([':id' => $items[0]->id]);
                            $historyRow = $itemHistory->fetch(\PDO::FETCH_ASSOC) ?: [];
                            $completedServiceName = (string)($historyRow['service_name_snapshot'] ?? '');
                            $completedServiceDescription = (string)($historyRow['service_description_snapshot'] ?? '');
                        }
                    }
                }
                TTransaction::close();
            } catch (\Throwable $e) {
                try { TTransaction::close(); } catch (\Throwable $ignore) {}
                error_log('Order completion WhatsApp preparation failed: ' . $e->getMessage());
                // A falha ao ler o modelo não pode esconder a confirmação da O.S.
                $settings = [];
            }

            if (!empty($completed)) {
                $o = $completed[0];
                $clientName = trim((string)($c->name ?? $o->client_name_snapshot ?? '')) ?: 'cliente';
                $phone = trim((string)($c->phone ?? $o->client_phone_snapshot ?? ''));
                $vehicleBrand = trim((string)($v->brand ?? ''));
                $vehicleModel = trim((string)($v->model ?? ''));
                $vehiclePlate = trim((string)($v->plate ?? $o->vehicle_plate_snapshot ?? ''));
                $vehicleDescription = trim($vehicleBrand . ' ' . $vehicleModel);
                if ($vehicleDescription === '') {
                    $vehicleDescription = trim((string)($o->vehicle_desc_snapshot ?? '')) ?: 'seu veículo';
                }
                $completedServiceLabel = trim((string)($s->name ?? ''));
                if ($completedServiceLabel === '') {
                    $completedServiceLabel = trim($completedServiceName) ?: 'Serviço';
                }
                $completedServiceDetails = trim((string)($s->description ?? ''));
                if ($completedServiceDetails === '') {
                    $completedServiceDetails = trim($completedServiceDescription);
                }
                $formattedValue = 'R$ ' . number_format((float)$o->total_amount, 2, ',', '.');
                $completedAtRaw = trim((string)($o->completion_time ?? ''));
                try {
                    $completedAt = $completedAtRaw !== ''
                        ? new \DateTimeImmutable($completedAtRaw, new \DateTimeZone('America/Sao_Paulo'))
                        : new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
                } catch (\Throwable $dateError) {
                    $completedAt = new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
                }

                $businessAddress = array_filter([
                    trim((string)($tenant->street ?? '') . ' ' . (string)($tenant->number ?? '')),
                    trim((string)($tenant->neighborhood ?? '')),
                    trim((string)($tenant->city ?? '') . '/' . (string)($tenant->state ?? '')),
                ], static fn(string $part): bool => $part !== '' && $part !== '/');
                $businessAddress = implode(', ', $businessAddress);
                $businessWhatsApp = trim((string)($settings['whatsapp_number'] ?? $tenant->whatsapp ?? ''));
                $orderLabel = OrderIdentity::label($o);

                $message = WhatsAppService::completionMessage(
                    (string)($settings['whatsapp_message'] ?? ''),
                    [
                        'NOME' => $clientName,
                        'MARCA' => $vehicleBrand,
                        'MODELO' => $vehicleModel !== '' ? $vehicleModel : $vehicleDescription,
                        'VEICULO' => $vehicleDescription,
                        'PLACA' => $vehiclePlate,
                        'SERVICO' => $completedServiceLabel,
                        'VALOR' => $formattedValue,
                        'DATA' => $completedAt->format('d/m/Y'),
                        'HORA' => $completedAt->format('H:i'),
                        'EMPRESA' => (string)($tenant->name ?? ''),
                        'OS' => $orderLabel,
                        'WHATSAPP_EMPRESA' => $businessWhatsApp,
                        'ENDERECO' => $businessAddress,
                    ]
                );
                $waUrl = WhatsAppService::buildReadyUrl($phone, $message);
                $waButton = $waUrl !== ''
                    ? '<a class="btn btn-whatsapp btn-lg" target="_blank" rel="noopener noreferrer" href="' . $this->e($waUrl) . '">Abrir WhatsApp</a>'
                    : '<button type="button" class="btn btn-secondary btn-lg" disabled>Cliente sem telefone válido</button>';

                $vehicleDisplay = trim($vehicleDescription . ($vehiclePlate !== '' ? ' · placa ' . $vehiclePlate : ''));
                $completedServiceDetails = trim($completedServiceDetails . ($completedServiceDetails !== '' ? ' · ' : '') . $formattedValue);
                $clientDisplayPhone = $phone !== '' ? $phone : 'Telefone não informado';
                $vehicleExtra = $vehiclePlate !== '' ? $vehiclePlate : 'Placa não informada';
                $washerName = trim((string)($o->washer_name_snapshot ?? '')) ?: 'Não definido';
                $completedModal = '<div id="completedModal" class="modal-backdrop show"><div class="modal-content completion-modal"><div class="modal-header"><div><div class="eyebrow">Atendimento concluído</div><div class="modal-title">Seu carro está pronto</div></div><button type="button" class="modal-close" onclick="closeCompletedModal()" aria-label="Fechar">&times;</button></div><div class="modal-body completion-body">
                    <div class="completion-success"><div class="completion-check">✓</div><div><strong>O.S. concluída com sucesso</strong><span>Confira os dados e a mensagem antes de abrir o WhatsApp.</span></div></div>
                    <div class="completion-grid"><div><span>Cliente</span><strong>' . $this->e($clientName) . '</strong><small>' . $this->e($clientDisplayPhone) . '</small></div><div><span>Veículo</span><strong>' . $this->e($vehicleDisplay) . '</strong><small>' . $this->e($vehicleExtra) . '</small></div><div><span>Serviço</span><strong>' . $this->e($completedServiceLabel) . '</strong><small>' . $this->e($completedServiceDetails) . '</small></div><div><span>Lavador</span><strong>' . $this->e($washerName) . '</strong><small>' . number_format((float)($o->washer_commission_percent ?? 0), 1, ',', '.') . '% · R$ ' . number_format((float)($o->washer_commission_amount ?? 0), 2, ',', '.') . '</small></div></div>
                    <div class="whatsapp-preview"><div><span>Mensagem configurada para o WhatsApp</span><p>' . $this->e($message) . '</p><small>O WhatsApp será aberto com o texto preenchido. Confirme o envio no próprio aplicativo.</small></div></div>
                </div><div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeCompletedModal()">Fechar</button>' . $waButton . '</div></div></div><script>function closeCompletedModal(){var m=document.getElementById("completedModal");if(m)m.remove();history.replaceState({},document.title,"index.php?class=OrdersController");}</script>';
            }
        }

        echo "
        <div class='page-heading'><div><div class='eyebrow'>Operação</div><h1>Ordens de Serviço</h1><p>Cadastre o atendimento completo em um único fluxo.</p></div><div class='heading-actions'><a class='btn btn-secondary' href='index.php?class=AgendaController'>Ver agenda</a><button type='button' onclick='openOrderModal()' class='btn btn-primary btn-lg'>+ Novo Agendamento</button></div></div>
        <div class='panel'><div class='panel-header'><div><div class='panel-title'>Atendimentos do lava-jato</div><div class='panel-subtitle'>Exibindo {$totalOrders} ordem(ns), 25 por página.</div></div></div><div class='panel-body'><div class='table-responsive'><table class='table-system'><thead><tr><th>O.S. #</th><th>Cliente</th><th>Veículo</th><th>Status</th><th>Valor & Pagamento</th><th>Entrada</th><th>Ações</th></tr></thead><tbody>{$rows}</tbody></table></div>{$pagination}</div></div>

        <div id='modalOrder' class='modal-backdrop'><div class='modal-content order-modal'><form method='POST' action='index.php?class=OrdersController'>{$csrf}<input type='hidden' name='action' value='save_order'><input type='hidden' id='os_id' name='id' value=''>
            <div class='modal-header'><div><div class='eyebrow'>Agendamento</div><div class='modal-title' id='osModalTitle'>Novo Agendamento</div></div><button type='button' class='modal-close' onclick='closeOrderModal()'>&times;</button></div>
            <div class='modal-body'>
                <div class='order-section'><div class='section-title'>Cliente</div><input type='hidden' id='os_client_id' name='client_id'><div class='form-grid cols-2'><div class='form-group'><label>Nome do cliente<span class=req>&nbsp;*</span></label><input class='form-control' id='client_name' name='client_name' maxlength='100' required></div><div class='form-group'><label>Telefone<span class=req>&nbsp;*</span></label><input class='form-control' id='client_phone' name='client_phone' inputmode='tel' maxlength='20' placeholder='(94) 99999-9999' required></div></div></div>

                <div class='order-section'><div class='section-title'>Veículo</div><input type='hidden' id='os_vehicle_id' name='vehicle_id'><div class='form-grid cols-2'><div class='form-group'><label>Marca<span class=req>&nbsp;*</span></label><input class='form-control' id='vehicle_brand' name='vehicle_brand' maxlength='50' required></div><div class='form-group'><label>Modelo<span class=req>&nbsp;*</span></label><input class='form-control' id='vehicle_model' name='vehicle_model' maxlength='50' required></div><div class='form-group'><label>Cor</label><input class='form-control' id='vehicle_color' name='vehicle_color' maxlength='30'></div><div class='form-group'><label>Placa<span class=req>&nbsp;*</span></label><input class='form-control' id='vehicle_plate' name='vehicle_plate' maxlength='10' placeholder='ABC1D23' required></div></div></div>

                <div class='order-section'><div class='section-title'>Serviço</div><div class='form-grid cols-2'><div class='form-group'><label>Serviço<span class=req>&nbsp;*</span></label><select id='os_service_id' name='service_id' class='form-control' onchange='loadServiceInfo()' required>{$serviceOpts}</select></div><div class='form-group'><label>Valor do serviço</label><input id='os_total_preview' class='form-control readonly-input' value='R$ 0,00' readonly></div></div></div>

                <div class='order-section'><div class='section-title'>Agendamento</div><div class='form-grid cols-2'><div class='form-group'><label>Data<span class=req>&nbsp;*</span></label><input id='appointment_date' name='appointment_date' class='form-control' type='date' required></div><div class='form-group'><label>Horário<span class=req>&nbsp;*</span></label><input id='appointment_time' name='appointment_time' class='form-control' type='time' required></div></div></div>

                <input type='hidden' id='os_status' name='status' value='Agendado'>
                <details id='orderAdvancedFields' class='order-advanced'><summary>Equipe e pagamento</summary><div class='form-grid cols-2'><div class='form-group'><label>Lavador responsável</label><select id='os_washer_id' name='washer_id' class='form-control' onchange='loadWasherInfo()'>{$washerOpts}</select></div><div class='form-group'><label>Comissão do lavador</label><input id='os_commission_preview' class='form-control readonly-input' value='0,0% · R$ 0,00' readonly></div><div class='form-group'><label>Status do pagamento</label><select id='os_payment_status' name='payment_status' class='form-control'><option value='Pendente'>Pendente</option><option value='Pago'>Pago / Recebido</option></select></div><div class='form-group'><label>Forma de pagamento</label><select id='os_payment_method' name='payment_method' class='form-control'><option value='Pix'>Pix</option><option value='Cartao_Credito'>Cartão de Crédito</option><option value='Cartao_Debito'>Cartão de Débito</option><option value='Dinheiro'>Dinheiro</option></select></div><div class='form-group span-2'><label>Observações</label><textarea id='os_notes' name='notes' class='form-control' rows='3'></textarea></div></div><div id='washerInfo' class='field-help'>O lavador e os dados de pagamento são opcionais no agendamento.</div></details>
            </div>
            <div class='modal-footer'><button type='button' onclick='closeOrderModal()' class='btn btn-secondary'>Cancelar</button><button id='osSubmitButton' type='submit' class='btn btn-primary'>Criar agendamento</button></div>
        </form></div></div>

        {$completedModal}

        <script>
        const clientData = " . json_encode($clientData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
        const vehicleData = " . json_encode($vehicleData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
        const serviceData = " . json_encode($serviceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
        const washerData = " . json_encode($washerData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";
        function openOrderModal(){
            const form=document.querySelector('#modalOrder form');
            form.reset();
            document.getElementById('os_id').value='';
            document.getElementById('osModalTitle').textContent='Novo Agendamento';
            document.getElementById('osSubmitButton').textContent='Criar agendamento';
            document.getElementById('os_client_id').value='';
            document.getElementById('os_vehicle_id').value='';
            const serviceSelect=document.getElementById('os_service_id');
            serviceSelect.value='';
            serviceSelect.required=true;
            serviceSelect.options[0].text='Selecione um serviço';
            document.getElementById('os_washer_id').value='';
            document.getElementById('os_status').value='Agendado';
            document.getElementById('os_payment_status').value='Pendente';
            document.getElementById('os_payment_method').value='Pix';
            document.getElementById('appointment_date').value=new Date().toLocaleDateString('sv-SE');
            document.getElementById('appointment_time').value=new Date().toTimeString().slice(0,5);
            document.getElementById('os_total_preview').value='R$ 0,00';
            document.getElementById('os_commission_preview').value='0,0% · R$ 0,00';
            document.getElementById('orderAdvancedFields').open=false;
            document.getElementById('modalOrder').classList.add('show');
        }
        function closeOrderModal(){document.getElementById('modalOrder').classList.remove('show');}
        function loadServiceInfo(){
            const id=document.getElementById('os_service_id').value;
            const s=serviceData[id];
            document.getElementById('os_total_preview').value=s?'R$ '+Number(s.price).toLocaleString('pt-BR',{minimumFractionDigits:2}):'R$ 0,00';
            loadWasherInfo();
        }
        function loadWasherInfo(){
            const wid=document.getElementById('os_washer_id').value;
            const w=washerData[wid];
            const sid=document.getElementById('os_service_id').value;
            const s=serviceData[sid];
            const total=s?Number(s.price):0;
            const p=w?Number(w.percent):0;
            const amount=total*p/100;
            document.getElementById('os_commission_preview').value=p.toLocaleString('pt-BR',{minimumFractionDigits:1})+'% · R$ '+amount.toLocaleString('pt-BR',{minimumFractionDigits:2});
        }
        function normalizeOrderStatus(status){
            return ({'Pendente':'Agendado','Em Andamento':'Em serviço','Concluído':'Finalizado'})[status]||status;
        }
        function editOrder(data){
            document.getElementById('os_id').value=data.id||'';
            document.getElementById('os_client_id').value=data.client_id||'';
            const client=clientData[data.client_id]||{};
            document.getElementById('client_name').value=client.name||data.client_name_snapshot||'';
            document.getElementById('client_phone').value=client.phone||data.client_phone_snapshot||'';
            document.getElementById('os_vehicle_id').value=data.vehicle_id||'';
            const vehicle=vehicleData[data.vehicle_id]||{};
            document.getElementById('vehicle_plate').value=vehicle.plate||data.vehicle_plate_snapshot||'';
            document.getElementById('vehicle_brand').value=vehicle.brand||(vehicle.plate?'':(data.vehicle_desc_snapshot||''));
            document.getElementById('vehicle_model').value=vehicle.model||'';
            document.getElementById('vehicle_color').value=vehicle.color||'';
            const serviceSelect=document.getElementById('os_service_id');
            serviceSelect.value=(data.service_id||'');
            if(!data.service_id&&data.service_name_snapshot){
                serviceSelect.options[0].text='Histórico: '+data.service_name_snapshot+' (removido)';
                serviceSelect.required=false;
                document.getElementById('os_total_preview').value='R$ '+Number(data.service_price_snapshot||data.total_amount||0).toLocaleString('pt-BR',{minimumFractionDigits:2});
            }else{
                serviceSelect.options[0].text='Selecione um serviço';
                serviceSelect.required=true;
            }
            loadServiceInfo();
            document.getElementById('os_washer_id').value=data.washer_id||'';
            loadWasherInfo();
            document.getElementById('os_status').value=normalizeOrderStatus(data.status||'Agendado');
            document.getElementById('os_payment_status').value=data.payment_status||'Pendente';
            document.getElementById('os_payment_method').value=data.payment_method||'Pix';
            document.getElementById('os_notes').value=data.notes||'';
            const scheduled=(data.entry_time||'').replace(' ','T');
            document.getElementById('appointment_date').value=scheduled.slice(0,10);
            document.getElementById('appointment_time').value=scheduled.slice(11,16);
            document.getElementById('orderAdvancedFields').open=true;
            document.getElementById('osModalTitle').textContent='Editar atendimento #'+data.id;
            document.getElementById('osSubmitButton').textContent='Salvar alterações';
            document.getElementById('modalOrder').classList.add('show');
        }
        if(new URLSearchParams(window.location.search).get('new')==='1'){openOrderModal();}
        </script>
        ";
    }
}
