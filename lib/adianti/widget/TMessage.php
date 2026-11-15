<?php
namespace Adianti\Widget;

class TMessage
{
    public static function add($type, $message)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['flash_messages'])) {
            $_SESSION['flash_messages'] = [];
        }
        $_SESSION['flash_messages'][] = ['type' => $type, 'message' => $message];
    }

    public static function show()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
            foreach ($_SESSION['flash_messages'] as $msg) {
                $typeClass = $msg['type'] === 'error' ? 'alert-error' : 'alert-success';
                echo "<div class='alert {$typeClass}'>
                        <strong>" . ($msg['type'] === 'error' ? 'Atenção:' : 'Sucesso:') . "</strong> " . htmlspecialchars($msg['message']) . "
                        <button class='close-btn' onclick='this.parentElement.style.display=\"none\";'>&times;</button>
                      </div>";
            }
            unset($_SESSION['flash_messages']);
        }
    }
}
