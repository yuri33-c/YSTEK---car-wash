<?php
namespace App\Core;

/**
 * Renderizador simples de views PHP.
 *
 * A regra é: controlador prepara dados; view apenas apresenta.
 * O helper mantém o projeto compatível com a estrutura atual e permite
 * migrar as telas antigas sem alterar toda a aplicação de uma vez.
 */
final class View
{
    private function __construct() {}

    /** Renderiza uma view localizada em app/view/<arquivo>.php. */
    public static function render(string $view, array $data = []): string
    {
        $file = dirname(__DIR__) . '/view/' . ltrim($view, '/') . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View não encontrada: ' . $view);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }
}
