# Banco PostgreSQL do YSTEK

O banco esperado pelo projeto é `ystek_db`.

## 1. Criar o banco

No pgAdmin 4, crie:

- Database: `ystek_db`

Não é necessário criar as tabelas manualmente.

## 2. Importar as tabelas

Abra o **Query Tool** dentro do banco `ystek_db`, abra o arquivo `database/ystek_schema.sql` e execute todo o conteúdo.

O script é idempotente para as estruturas previstas: usa `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... IF NOT EXISTS` e índices condicionais quando aplicável.

## 3. Usuário do PostgreSQL

Para ambiente local, o projeto consegue usar `postgres` com a senha configurada no `app/config/application.php` ou nas variáveis de ambiente.

Para produção, prefira um usuário exclusivo da aplicação e defina:

```text
YSTEK_DB_HOST=127.0.0.1
YSTEK_DB_PORT=5432
YSTEK_DB_NAME=ystek_db
YSTEK_DB_USER=ystek_app
YSTEK_DB_PASS=uma_senha_forte
YSTEK_APP_ENV=production
```

## 4. Não use o pgAdmin como banco

O PostgreSQL guarda os dados. O pgAdmin 4 apenas administra o PostgreSQL.

## 5. Plano único Premium

O catálogo comercial do projeto possui somente **Premium — R$ 199,90/mês**.
Para verificar quais empresas e assinaturas ainda possuem outro valor de plano,
execute a prévia somente leitura:

```text
php scripts/migrate_single_premium.php
```

Confira os IDs e a quantidade apresentados antes de qualquer atualização. A
prévia exclui o tenant interno da YSTEK e não cria assinatura para empresas que
ainda não tenham uma. Depois da conferência, a aplicação exige confirmação
explícita:

```text
php scripts/migrate_single_premium.php --apply --confirm-preview TOKEN_DA_PREVIA
```

A transação exige o token da prévia exibida anteriormente e aborta se os
registros ou valores considerados mudarem. Ela altera somente `tenants.plan`
e `subscriptions.plan`. Os valores
anteriores são preservados na tabela aditiva `ystek_plan_migration_backup` para
rollback; pagamentos/eventos, valores, datas, status, IDs e relacionamentos não
são alterados. Para reverter os campos de plano:

```text
php scripts/migrate_single_premium.php --rollback --confirm-rollback
```

Não execute a aplicação antes de revisar a prévia. O usuário do banco precisa
ter permissão ordinária para criar a tabela de backup e atualizar as duas
colunas; não conceda SUPERUSER nem transfira ownership para executar a migração.
