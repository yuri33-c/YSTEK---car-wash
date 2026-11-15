<?php
/**
 * Importa/atualiza o schema do YSTEK no banco configurado.
 *
 * Execute depois de criar o banco `ystek_db` no PostgreSQL/pgAdmin 4.
 * O script não cria o banco: ele trabalha somente no banco já existente.
 */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = file_get_contents(__DIR__ . '/../database/ystek_schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Não foi possível ler database/ystek_schema.sql');
    }

    $pdo->exec($sql);
    echo "Schema YSTEK aplicado com sucesso em {$db['name']}.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Falha ao aplicar schema: {$e->getMessage()}\n");
    exit(1);
}
