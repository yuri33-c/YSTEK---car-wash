<?php
/**
 * Migração pontual da tabela vehicles.
 * Corrige instalações antigas em que category foi criada como VARCHAR(10).
 */
require_once __DIR__ . '/../lib/adianti/database/TTransaction.php';

use Adianti\Database\TTransaction;

try {
    $pdo = TTransaction::open();
    $pdo->exec("ALTER TABLE vehicles ALTER COLUMN category TYPE VARCHAR(30)");
    echo "Migração concluída: vehicles.category agora aceita até 30 caracteres.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Erro na migração: {$e->getMessage()}\n");
    exit(1);
} finally {
    TTransaction::close();
}
