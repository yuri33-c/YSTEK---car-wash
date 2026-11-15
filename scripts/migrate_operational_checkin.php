<?php
/**
 * Migração idempotente da etapa de check-in e checklist.
 * Execute uma vez em uma instalação existente:
 *     php scripts/migrate_operational_checkin.php
 */
require_once __DIR__ . '/../app/config/application.php';
$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];

try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $schema = file_get_contents(__DIR__ . '/../app/config/schema.sql');
    $pdo->exec($schema);
    echo "Migração de check-in/checklist concluída.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha na migração: {$e->getMessage()}\n");
    exit(1);
}
