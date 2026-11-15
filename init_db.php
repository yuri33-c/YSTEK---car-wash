<?php
/**
 * Inicializa o schema no banco que já existe.
 *
 * O script não tenta executar CREATE DATABASE. Crie `ystek_db` pelo pgAdmin 4
 * (ou outro administrador PostgreSQL) e execute este arquivo uma vez.
 */
$config = require __DIR__ . '/app/config/application.php';
$db = $config['database'];

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = file_get_contents(__DIR__ . '/database/ystek_schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Arquivo database/ystek_schema.sql não encontrado.');
    }

    $pdo->exec($sql);
    echo "Banco {$db['name']} pronto. Schema aplicado com sucesso.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Erro ao inicializar o YSTEK: {$e->getMessage()}\n");
    exit(1);
}
