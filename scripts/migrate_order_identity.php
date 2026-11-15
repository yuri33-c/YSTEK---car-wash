<?php
// Cria/atualiza as colunas de identidade das O.S. (número curto e retrato de cliente/veículo)
// e preenche as O.S. já existentes. É seguro executar mais de uma vez.
// Uso: php scripts/migrate_order_identity.php
require_once __DIR__ . '/../lib/adianti/database/TTransaction.php';
require_once __DIR__ . '/../app/service/OrderIdentity.php';
$pdo = \Adianti\Database\TTransaction::get();
\App\Service\OrderIdentity::ensureSchema($pdo);
echo "Identidade das O.S. atualizada.\n";
