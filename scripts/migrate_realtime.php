<?php
/** Instala as revisões por empresa e triggers usados pela sincronização da operação. */
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $sql = file_get_contents(__DIR__ . '/../database/migrations/20261114_tenant_realtime_versions.sql');
    if ($sql === false) throw new RuntimeException('Não foi possível ler a migração de sincronização.');
    $pdo->exec($sql);
    echo "REALTIME_MIGRATION_OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Falha na migração de sincronização: {$e->getMessage()}\n");
    exit(1);
}
