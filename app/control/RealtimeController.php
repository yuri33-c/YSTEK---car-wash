<?php
namespace App\Control;

use Adianti\Control\TPage;
use Adianti\Database\TTransaction;

/** Endpoint mínimo de polling; a autorização e o tenant vêm do front controller. */
final class RealtimeController extends TPage
{
    public function process()
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || empty($_SESSION['tenant_id'])) {
            http_response_code(405);
            echo '{"error":"method_not_allowed"}';
            exit;
        }

        // Libera o lock de sessão antes de consultar para não bloquear outros
        // pedidos do mesmo usuário enquanto o navegador aguarda a resposta.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        try {
            $pdo = TTransaction::open();
            $stmt = $pdo->prepare('SELECT version FROM public.tenant_realtime_versions WHERE tenant_id = :tenant_id');
            $stmt->execute([':tenant_id' => (string)$_SESSION['tenant_id']]);
            $version = $stmt->fetchColumn();
            TTransaction::close();
            if ($version === false) {
                http_response_code(503);
                echo '{"error":"realtime_migration_required"}';
                exit;
            }
            echo json_encode(['version' => (string)$version], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            TTransaction::close();
            error_log('Realtime version endpoint error: ' . $e->getMessage());
            http_response_code(503);
            echo '{"error":"realtime_unavailable"}';
        }
        exit;
    }
}
