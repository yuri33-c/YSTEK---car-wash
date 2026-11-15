<?php
/**
 * Configuração central da aplicação.
 *
 * IMPORTANTE:
 * Nenhuma credencial é armazenada neste arquivo.
 * Os valores sensíveis vêm do ambiente do servidor ou de um arquivo externo
 * apontado por YSTEK_ENV_FILE / /etc/ystek/ystek.env / %ProgramData%/YSTEK/ystek.env.
 */
require_once dirname(__DIR__) . '/core/Environment.php';

\App\Core\Environment::load();

$required = [
    'YSTEK_DB_HOST',
    'YSTEK_DB_PORT',
    'YSTEK_DB_NAME',
    'YSTEK_DB_USER',
    'YSTEK_DB_PASS',
];

foreach ($required as $key) {
    $value = getenv($key);
    if ($value === false || trim((string)$value) === '') {
        throw new \RuntimeException("Configuração obrigatória ausente: {$key}. Configure o ambiente do servidor antes de iniciar o YSTEK.");
    }
}

$environment = strtolower((string)(getenv('YSTEK_APP_ENV') ?: 'production'));
$sslMode = strtolower(trim((string)(getenv('YSTEK_DB_SSLMODE') ?: '')));
$validSslModes = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
if ($sslMode !== '' && !in_array($sslMode, $validSslModes, true)) {
    throw new \RuntimeException('YSTEK_DB_SSLMODE possui um valor inválido.');
}

return [
    'general' => [
        'title' => 'YSTEK Lava Jato - Sistema de Gestão & SaaS',
        'timezone' => 'America/Sao_Paulo',
        'language' => 'pt',
        'application' => 'ystek',
        'theme' => 'theme3',
        'environment' => $environment,
    ],
    'database' => [
        'host' => (string)getenv('YSTEK_DB_HOST'),
        'port' => (string)getenv('YSTEK_DB_PORT'),
        'name' => (string)getenv('YSTEK_DB_NAME'),
        'user' => (string)getenv('YSTEK_DB_USER'),
        'pass' => (string)getenv('YSTEK_DB_PASS'),
        'sslmode' => $sslMode,
        'type' => 'pgsql',
    ],
];
