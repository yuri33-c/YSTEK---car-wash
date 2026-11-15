<?php
/**
 * Migração segura do catálogo comercial para o único plano Premium.
 *
 * Padrão: php scripts/migrate_single_premium.php (prévia somente leitura)
 * Aplicação: php scripts/migrate_single_premium.php --apply --confirm-preview TOKEN
 * Rollback: php scripts/migrate_single_premium.php --rollback --confirm-rollback
 *
 * A alteração limita-se às colunas plan de tenants e subscriptions. A tabela de
 * backup guarda os valores anteriores para permitir rollback sem tocar em eventos,
 * pagamentos, datas, status, IDs ou relacionamentos. A conta da plataforma é excluída.
 */
$mode = $argv[1] ?? '--preview';
$args = array_slice($argv, 1);
if (!in_array($mode, ['--preview', '--apply', '--rollback'], true)) {
    fwrite(STDERR, "Uso: php scripts/migrate_single_premium.php [--preview|--apply --confirm-preview|--rollback --confirm-rollback]\n");
    exit(2);
}
$confirmToken = null;
$confirmIndex = array_search('--confirm-preview', $args, true);
if ($confirmIndex !== false) {
    $confirmToken = $args[$confirmIndex + 1] ?? null;
}
if ($mode === '--apply' && (!is_string($confirmToken) || !preg_match('/^[a-f0-9]{16}$/', $confirmToken))) {
    fwrite(STDERR, "Aplicação bloqueada. Informe o token exibido na prévia: --apply --confirm-preview TOKEN.\n");
    exit(2);
}
if ($mode === '--rollback' && !in_array('--confirm-rollback', $args, true)) {
    fwrite(STDERR, "Rollback bloqueado. Informe --rollback --confirm-rollback.\n");
    exit(2);
}

$config = require __DIR__ . '/../app/config/application.php';
$db = $config['database'];
$premium = 'Premium';
$migrationKey = 'single-premium-2026-11';
$backupTable = 'public.ystek_plan_migration_backup';
$customerPredicate = "LOWER(COALESCE(t.email, '')) <> :tenant_email
    AND NOT EXISTS (
        SELECT 1 FROM users AS platform_user
        WHERE platform_user.tenant_id = t.id
          AND (
              platform_user.role = 'SUPER_ADMIN'
              OR LOWER(platform_user.email) = :user_email
          )
    )";
try {
    $pdo = new PDO(
        "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']};",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $required = [
        ['tenants', 'id'], ['tenants', 'name'], ['tenants', 'email'], ['tenants', 'plan'],
        ['subscriptions', 'id'], ['subscriptions', 'tenant_id'], ['subscriptions', 'plan'],
        ['users', 'tenant_id'], ['users', 'role'], ['users', 'email'],
    ];
    $columnCheck = $pdo->prepare(
        "SELECT 1 FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = :table AND column_name = :column"
    );
    foreach ($required as [$table, $column]) {
        $columnCheck->execute([':table' => $table, ':column' => $column]);
        if (!$columnCheck->fetchColumn()) {
            throw new RuntimeException("Schema incompatível: coluna public.{$table}.{$column} ausente.");
        }
    }

    $pdo->beginTransaction();
    if ($mode === '--preview') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    }

    $lockClause = $mode === '--apply' ? ' FOR UPDATE OF t' : '';
    $previewSql = "SELECT t.id::text AS tenant_id, t.name, t.plan AS tenant_plan,
                          s.id::text AS subscription_id, s.plan AS subscription_plan,
                          s.status AS subscription_status, s.due_date::text AS due_date
                   FROM tenants AS t
                   LEFT JOIN subscriptions AS s ON s.tenant_id = t.id
                   WHERE {$customerPredicate}
                     AND (
                         t.plan IS DISTINCT FROM :tenant_target
                         OR (s.id IS NOT NULL AND s.plan IS DISTINCT FROM :subscription_target)
                     )
                   ORDER BY t.name, t.id{$lockClause}";
    $previewStmt = $pdo->prepare($previewSql);
    $previewStmt->execute([
        ':tenant_email' => 'plataforma@ystek.com.br',
        ':user_email' => 'plataforma@ystek.com.br',
        ':tenant_target' => $premium,
        ':subscription_target' => $premium,
    ]);
    $records = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
    $tenantRows = 0;
    $subscriptionRows = 0;
    foreach ($records as $record) {
        if ($record['tenant_plan'] !== $premium) $tenantRows++;
        if ($record['subscription_id'] !== null && $record['subscription_plan'] !== $premium) $subscriptionRows++;
    }

    $internalStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tenants AS t
         WHERE LOWER(COALESCE(t.email, '')) = :tenant_email
            OR EXISTS (
                SELECT 1 FROM users AS platform_user
                WHERE platform_user.tenant_id = t.id
                  AND (
                      platform_user.role = 'SUPER_ADMIN'
                      OR LOWER(platform_user.email) = :user_email
                  )
            )"
    );
    $internalStmt->execute([
        ':tenant_email' => 'plataforma@ystek.com.br',
        ':user_email' => 'plataforma@ystek.com.br',
    ]);
    $excludedInternal = (int)$internalStmt->fetchColumn();

    echo "Migração: {$migrationKey}\nModo: " . ($mode === '--preview' ? 'PRÉVIA (sem gravações)' : $mode) . "\n";
    echo "Empresas internas excluídas: {$excludedInternal}\n";
    echo "Empresas clientes com coluna plan a atualizar: {$tenantRows}\n";
    echo "Assinaturas com coluna plan a atualizar: {$subscriptionRows}\n";
    echo "Tenants afetados:\n";
    if (!$records) {
        echo "  (nenhum; migração já aplicada ou não há planos divergentes)\n";
    }
    foreach ($records as $record) {
        $subscription = $record['subscription_id'] === null
            ? 'sem assinatura (não será criada)'
            : $record['subscription_id'] . ' [' . $record['subscription_plan'] . ', ' .
                $record['subscription_status'] . ', vencimento ' . ($record['due_date'] ?? '—') . ']';
        echo '  ' . $record['tenant_id'] . ' | ' . $record['name'] . ' | tenant=' .
            ($record['tenant_plan'] ?? 'NULL') . ' | assinatura=' . $subscription . "\n";
    }
    $previewToken = substr(hash('sha256', json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 0, 16);
    echo "Token da prévia: {$previewToken}\n";

    if ($mode === '--preview') {
        $pdo->rollBack();
        exit(0);
    }

    if ($mode === '--apply' && !hash_equals($previewToken, (string)$confirmToken)) {
        throw new RuntimeException('A prévia mudou ou o token não confere; nenhuma atualização foi executada.');
    }

    if ($mode === '--rollback') {
        $backupExists = $pdo->query("SELECT to_regclass('{$backupTable}')")->fetchColumn();
        if (!$backupExists) {
            throw new RuntimeException('Backup da migração não existe; nenhum dado foi alterado.');
        }
        $tenantRollback = $pdo->prepare(
            "UPDATE tenants AS t SET plan = b.old_plan
             FROM {$backupTable} AS b
             WHERE b.migration_key = :migration_key
               AND b.record_type = 'tenant'
               AND b.record_id = t.id
               AND t.plan = :premium"
        );
        $tenantRollback->execute([':migration_key' => $migrationKey, ':premium' => $premium]);
        $subscriptionRollback = $pdo->prepare(
            "UPDATE subscriptions AS s SET plan = b.old_plan
             FROM {$backupTable} AS b
             WHERE b.migration_key = :migration_key
               AND b.record_type = 'subscription'
               AND b.record_id = s.id
               AND s.plan = :premium"
        );
        $subscriptionRollback->execute([':migration_key' => $migrationKey, ':premium' => $premium]);
        $pdo->commit();
        echo "Rollback concluído: {$tenantRollback->rowCount()} empresa(s) e " .
            "{$subscriptionRollback->rowCount()} assinatura(s) restauradas.\n";
        exit(0);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS {$backupTable} (
            migration_key VARCHAR(80) NOT NULL,
            record_type VARCHAR(20) NOT NULL,
            record_id UUID NOT NULL,
            tenant_id UUID NOT NULL,
            old_plan VARCHAR(50),
            captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (migration_key, record_type, record_id)
        )"
    );
    $tenantBackup = $pdo->prepare(
        "INSERT INTO {$backupTable} (migration_key, record_type, record_id, tenant_id, old_plan)
         SELECT :migration_key, 'tenant', t.id, t.id, t.plan
         FROM tenants AS t
         WHERE {$customerPredicate} AND t.plan IS DISTINCT FROM :premium
         ON CONFLICT (migration_key, record_type, record_id) DO NOTHING"
    );
    $tenantBackup->execute([
        ':migration_key' => $migrationKey,
        ':tenant_email' => 'plataforma@ystek.com.br',
        ':user_email' => 'plataforma@ystek.com.br',
        ':premium' => $premium,
    ]);
    $subscriptionBackup = $pdo->prepare(
        "INSERT INTO {$backupTable} (migration_key, record_type, record_id, tenant_id, old_plan)
         SELECT :migration_key, 'subscription', s.id, t.id, s.plan
         FROM subscriptions AS s
         INNER JOIN tenants AS t ON t.id = s.tenant_id
         WHERE {$customerPredicate} AND s.plan IS DISTINCT FROM :premium
         ON CONFLICT (migration_key, record_type, record_id) DO NOTHING"
    );
    $subscriptionBackup->execute([
        ':migration_key' => $migrationKey,
        ':tenant_email' => 'plataforma@ystek.com.br',
        ':user_email' => 'plataforma@ystek.com.br',
        ':premium' => $premium,
    ]);

    $tenantUpdate = $pdo->prepare(
        "UPDATE tenants AS t SET plan = :new_plan
         WHERE {$customerPredicate} AND t.plan IS DISTINCT FROM :old_plan"
    );
    $tenantUpdate->execute([
        ':new_plan' => $premium,
        ':tenant_email' => 'plataforma@ystek.com.br',
        ':user_email' => 'plataforma@ystek.com.br',
        ':old_plan' => $premium,
    ]);
    $subscriptionUpdate = $pdo->prepare(
        "UPDATE subscriptions AS s SET plan = :new_plan
         FROM tenants AS t
         WHERE s.tenant_id = t.id
           AND {$customerPredicate}
           AND s.plan IS DISTINCT FROM :old_plan"
    );
    $subscriptionUpdate->execute([
        ':new_plan' => $premium,
        ':tenant_email' => 'plataforma@ystek.com.br',
        ':user_email' => 'plataforma@ystek.com.br',
        ':old_plan' => $premium,
    ]);
    $pdo->commit();
    echo "Migração concluída: {$tenantUpdate->rowCount()} empresa(s) e " .
        "{$subscriptionUpdate->rowCount()} assinatura(s) atualizadas.\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[ERRO] Migração não concluída: {$e->getMessage()}\n");
    exit(1);
}
