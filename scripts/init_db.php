<?php
/**
 * Instalador de schema do YSTEK.
 *
 * O banco deve ser criado previamente pelo administrador do PostgreSQL/pgAdmin.
 * Este script trabalha somente dentro do banco configurado e não cria contas
 * administrativas nem utiliza o superusuário postgres como fallback.
 */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $schema = file_get_contents(__DIR__ . '/../database/ystek_schema.sql');
    if ($schema === false) {
        throw new RuntimeException('database/ystek_schema.sql não encontrado.');
    }

    $pdo->exec($schema);
    echo "[OK] Schema YSTEK aplicado no banco configurado.\n";
    echo "[PRÓXIMO PASSO] Crie o primeiro SUPER_ADMIN com: php scripts/create_super_admin.php\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] {$e->getMessage()}\n");
    exit(1);
}
