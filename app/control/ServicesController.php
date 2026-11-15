<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;
use Adianti\Widget\TMessage;
use App\Service\PlanGuard;
use App\Service\RecordDeletionService;

/**
 * Controller do catálogo de serviços.
 *
 * Nesta tela usamos PDO diretamente para o CRUD para tolerar bancos antigos
 * que receberam colunas adicionais ao longo da evolução do sistema. A regra
 * continua a mesma: todo registro é sempre filtrado pelo tenant da sessão.
 */
class ServicesController extends TPage
{
    private function tenantId(): string
    {
        $id = trim((string)($_SESSION['tenant_id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('Sessão da empresa não encontrada. Faça login novamente.');
        }
        return $id;
    }

    /** Aceita 50,00 e 50.00. */
    private function parseMoney(string $value): float
    {
        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }

        $value = preg_replace('/[^0-9,.-]/', '', $value);
        if (strpos($value, ',') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Informe um preço válido.');
        }

        return round((float)$value, 2);
    }

    private function getService(string $id): ?array
    {
        $pdo = TTransaction::get();
        $stmt = $pdo->prepare('SELECT id, tenant_id, name, description, price, duration_minutes, status FROM public.services WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
        $stmt->execute([':id' => $id, ':tenant_id' => $this->tenantId()]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function process()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $action = (string)($_POST['action'] ?? '');
        if (!in_array($action, ['save_service', 'delete_service'], true)) {
            return;
        }

        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $tenantId = $this->tenantId();

            if ($action === 'save_service') {
                $id = trim((string)($_POST['id'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $price = $this->parseMoney((string)($_POST['price'] ?? '0'));
                $duration = (int)($_POST['duration_minutes'] ?? 45);

                if ($name === '') {
                    throw new \InvalidArgumentException('Informe o nome do serviço.');
                }
                if (mb_strlen($name) > 100 || mb_strlen($description) > 20000) {
                    throw new \InvalidArgumentException('Um dos campos ultrapassou o limite permitido.');
                }
                if ($price < 0 || $price > 99999999.99) {
                    throw new \InvalidArgumentException('Informe um preço entre R$ 0,00 e R$ 99.999.999,99.');
                }
                if ($duration < 1 || $duration > 1440) {
                    throw new \InvalidArgumentException('A duração deve estar entre 1 e 1.440 minutos.');
                }

                // Impede duplicidade de serviço dentro da mesma empresa sem confiar no navegador.
                $dup = $pdo->prepare('SELECT id FROM public.services WHERE tenant_id = :tenant_id AND lower(trim(name)) = lower(trim(:name)) AND (:id = \'\' OR id::text <> :id) LIMIT 1');
                $dup->execute([':tenant_id' => $tenantId, ':name' => $name, ':id' => $id]);
                if ($dup->fetchColumn()) {
                    throw new \InvalidArgumentException('Já existe um serviço com este nome.');
                }

                if ($id === '') {
                    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM public.services WHERE tenant_id = :tenant_id');
                    $countStmt->execute([':tenant_id' => $tenantId]);
                    PlanGuard::enforceLimit('services', (int)$countStmt->fetchColumn());

                    $stmt = $pdo->prepare("INSERT INTO public.services (tenant_id, name, description, price, duration_minutes, status)
                        VALUES (:tenant_id, :name, :description, :price, :duration, 'ativo')");
                    $stmt->execute([
                        ':tenant_id' => $tenantId,
                        ':name' => $name,
                        ':description' => $description,
                        ':price' => $price,
                        ':duration' => $duration,
                    ]);
                } else {
                    $service = $this->getService($id);
                    if (!$service) {
                        throw new \RuntimeException('Acesso negado ao serviço.');
                    }

                    $stmt = $pdo->prepare("UPDATE public.services
                        SET name=:name, description=:description, price=:price, duration_minutes=:duration
                        WHERE id=:id AND tenant_id=:tenant_id");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description,
                        ':price' => $price,
                        ':duration' => $duration,
                        ':id' => $id,
                        ':tenant_id' => $tenantId,
                    ]);
                }

                TTransaction::close();
                TMessage::add('success', 'Serviço salvo com sucesso.');
                header('Location: index.php?class=ServicesController', true, 303);
                exit;
            }

            // Exclusão segura: o registro precisa pertencer ao tenant atual.
            $id = trim((string)($_POST['id'] ?? ''));
            if ($id === '') {
                throw new \InvalidArgumentException('Serviço inválido.');
            }

            $pdo->beginTransaction();
            RecordDeletionService::deleteService($pdo, $tenantId, $id);
            $pdo->commit();
            TTransaction::close();
            TMessage::add('success', 'Serviço excluído definitivamente. Os valores das ordens vinculadas foram preservados.');
            header('Location: index.php?class=ServicesController', true, 303);
            exit;
        } catch (\Throwable $e) {
            if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            error_log('ServicesController process: ' . $e->getMessage());

            $message = $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Não foi possível salvar a operação do serviço.';

            TMessage::add('error', $message);
            header('Location: index.php?class=ServicesController', true, 303);
            exit;
        }
    }

    public function show()
    {
        $csrf = \App\Core\Security::csrfInput();
        $services = [];
        $loadError = null;

        try {
            TTransaction::open();
            $pdo = TTransaction::get();
            $stmt = $pdo->prepare("SELECT id, name, description, price, duration_minutes, status
                FROM public.services
                WHERE tenant_id = :tenant_id
                ORDER BY name ASC, id ASC");
            $stmt->execute([':tenant_id' => $this->tenantId()]);
            $services = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            TTransaction::close();
        } catch (\Throwable $e) {
            try { TTransaction::close(); } catch (\Throwable $ignore) {}
            $loadError = $e->getMessage();
            error_log('ServicesController show: ' . $loadError);
        }

        $rows = '';
        foreach ($services as $srv) {
            $id = htmlspecialchars((string)$srv['id'], ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars((string)$srv['name'], ENT_QUOTES, 'UTF-8');
            $description = htmlspecialchars((string)($srv['description'] ?? ''), ENT_QUOTES, 'UTF-8');
            $duration = (int)($srv['duration_minutes'] ?? 45);
            $price = number_format((float)($srv['price'] ?? 0), 2, ',', '.');
            $serviceStatus = strtolower((string)($srv['status'] ?? 'ativo'));
            $status = $serviceStatus === 'ativo' ? 'Ativo' : ($serviceStatus === 'arquivado' ? 'Arquivado' : 'Inativo');
            $payload = htmlspecialchars(json_encode($srv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
            $actions = "<button type='button' class='btn btn-sm btn-secondary service-action service-edit' onclick='editService({$payload})' aria-label='Editar {$name}'>
                        <svg viewBox='0 0 24 24' aria-hidden='true' focusable='false'><path d='M12 20h9'/><path d='M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z'/></svg><span>Editar</span>
                    </button>
                    <form method='POST' action='index.php?class=ServicesController' onsubmit='return confirm(&quot;Excluir definitivamente este serviço? As ordens vinculadas e seus valores serão preservados sem o vínculo com o cadastro.&quot;)'>{$csrf}<input type='hidden' name='action' value='delete_service'><input type='hidden' name='id' value='{$id}'><button type='submit' class='btn btn-sm btn-danger service-action service-delete' aria-label='Excluir {$name}'>
                        <svg viewBox='0 0 24 24' aria-hidden='true' focusable='false'><path d='M3 6h18'/><path d='M8 6V4h8v2'/><path d='m19 6-1 14H6L5 6'/><path d='M10 11v5M14 11v5'/></svg><span>Excluir</span>
                    </button></form>";

            $rows .= "<tr>
                <td class='service-name'><strong>{$name}</strong></td>
                <td>{$description}</td>
                <td>{$duration} min</td>
                <td class='service-price'><strong>R$ {$price}</strong></td>
                <td><span class='status-pill service-status " . ($status === 'Ativo' ? 'status-active' : 'status-inactive') . "'>{$status}</span></td>
                <td class='table-actions service-actions'>{$actions}</td>
            </tr>";
        }

        $empty = !$loadError && !$services
            ? "<tr><td colspan='6'><div class='empty-state'><strong>Nenhum serviço cadastrado.</strong><span>Crie o primeiro serviço para utilizá-lo nas Ordens de Serviço.</span></div></td></tr>"
            : '';

        $alert = $loadError
            ? "<div class='alert alert-error'><strong>Não foi possível carregar os serviços.</strong><span>Verifique a conexão com o banco de dados. O erro foi registrado no log da aplicação.</span></div>"
            : '';

        echo "
        <div class='page-heading'>
            <div><div class='eyebrow'>Cadastros</div><h1>Serviços & Preços</h1><p>Catálogo de serviços, duração e valores praticados pela empresa.</p></div>
            <div class='heading-actions'><button type='button' onclick='openServiceModal()' class='btn btn-primary btn-lg'>+ Novo Serviço</button></div>
        </div>
        {$alert}
        <div class='panel services-catalog'>
            <div class='panel-header'>
                <div><div class='panel-title'>Catálogo de serviços</div><div class='panel-subtitle'>Os valores abaixo ficam disponíveis na abertura de uma Ordem de Serviço.</div></div>
            </div>
            <div class='panel-body'>
                <div class='table-responsive services-table-wrap'>
                    <table class='table-system services-table'>
                        <thead><tr><th>Serviço</th><th>Descrição</th><th>Duração</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead>
                        <tbody>{$rows}{$empty}</tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id='modalService' class='modal-backdrop'>
            <div class='modal-content'>
                <form method='POST' action='index.php?class=ServicesController'>
                    {$csrf}
                    <input type='hidden' name='action' value='save_service'>
                    <input type='hidden' id='service_id' name='id' value=''>
                    <div class='modal-header'>
                        <div><div class='eyebrow'>Catálogo</div><div class='modal-title' id='sModalTitle'>Novo Serviço</div></div>
                        <button type='button' onclick='closeServiceModal()' class='modal-close' aria-label='Fechar'>&times;</button>
                    </div>
                    <div class='modal-body'>
                        <div class='form-group'><label for='s_name'>Nome do Serviço<span class=req>&nbsp;*</span></label><input type='text' id='s_name' name='name' class='form-control' maxlength='100' required placeholder='Ex.: Lavagem completa'></div>
                        <div class='form-row'>
                            <div class='form-group'><label for='s_price'>Preço (R$)<span class=req>&nbsp;*</span></label><input type='text' id='s_price' name='price' class='form-control' inputmode='decimal' required placeholder='50,00'></div>
                            <div class='form-group'><label for='s_duration'>Duração (minutos)</label><input type='number' id='s_duration' name='duration_minutes' class='form-control' min='1' max='1440' value='45'></div>
                        </div>
                        <div class='form-group'><label for='s_description'>Descrição</label><textarea id='s_description' name='description' class='form-control' maxlength='20000' rows='4' placeholder='Detalhes que ajudam a identificar o serviço'></textarea></div>
                    </div>
                    <div class='modal-footer'><button type='button' onclick='closeServiceModal()' class='btn btn-secondary'>Cancelar</button><button type='submit' class='btn btn-primary'>Salvar Serviço</button></div>
                </form>
            </div>
        </div>

        <script>
        function openServiceModal() {
            document.getElementById('service_id').value = '';
            document.getElementById('s_name').value = '';
            document.getElementById('s_price').value = '';
            document.getElementById('s_duration').value = '45';
            document.getElementById('s_description').value = '';
            document.getElementById('sModalTitle').innerText = 'Novo Serviço';
            document.getElementById('modalService').classList.add('show');
            document.getElementById('s_name').focus();
        }
        function closeServiceModal() {
            document.getElementById('modalService').classList.remove('show');
        }
        function editService(data) {
            document.getElementById('service_id').value = data.id || '';
            document.getElementById('s_name').value = data.name || '';
            document.getElementById('s_price').value = data.price != null ? data.price : '';
            document.getElementById('s_duration').value = data.duration_minutes || '45';
            document.getElementById('s_description').value = data.description || '';
            document.getElementById('sModalTitle').innerText = 'Editar Serviço';
            document.getElementById('modalService').classList.add('show');
            document.getElementById('s_name').focus();
        }
        </script>
        ";
    }
}
