<?php
require __DIR__ . '/../app/config/application.php';
require __DIR__ . '/../lib/adianti/database/TTransaction.php';
Adianti\Database\TTransaction::open();
$pdo = Adianti\Database\TTransaction::get();

$stmt = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema='public'");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "--- LISTA DE TABELAS REAIS NO BD (SCHEMA PUBLIC) ---\n";
foreach ($tables as $table) {
    echo "- {$table}\n";
}
echo "\n--- ESTRUTURA DE CADA TABELA ---\n";
foreach ($tables as $table) {
    echo "\nTABELA: {$table}\n";
    $cols = $pdo->query("SELECT column_name, data_type, character_maximum_length FROM information_schema.columns WHERE table_schema='public' AND table_name='{$table}'");
    foreach ($cols as $col) {
        $len = $col['character_maximum_length'] ? "({$col['character_maximum_length']})" : '';
        echo "   {$col['column_name']} : {$col['data_type']}{$len}\n";
    }
}
