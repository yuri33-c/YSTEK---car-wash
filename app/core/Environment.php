<?php
namespace App\Core;

/**
 * Carrega somente configurações externas ao código-fonte.
 *
 * Ordem:
 * 1. variáveis já fornecidas pelo processo/servidor;
 * 2. arquivo apontado por YSTEK_ENV_FILE;
 * 3. caminhos padrão fora do document root, quando existirem.
 *
 * Nenhuma senha, e-mail ou conta é embutida nesta classe.
 */
final class Environment
{
    private static bool $loaded = false;

    private function __construct() {}

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $paths = [];
        $explicit = getenv('YSTEK_ENV_FILE');
        if ($explicit !== false && trim($explicit) !== '') {
            $paths[] = $explicit;
        }

        // Linux/Unix: fora da pasta pública da aplicação.
        $paths[] = '/etc/ystek/ystek.env';

        // Windows: diretório de dados do sistema, também fora do projeto.
        $programData = getenv('ProgramData');
        if ($programData) {
            $paths[] = rtrim($programData, '\\\/') . DIRECTORY_SEPARATOR . 'YSTEK' . DIRECTORY_SEPARATOR . 'ystek.env';
        }

        foreach (array_unique($paths) as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                self::loadFile($path);
                break;
            }
        }
    }

    private static function loadFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Não foi possível ler o arquivo de ambiente do YSTEK.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
