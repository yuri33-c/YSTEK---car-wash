# YSTEK — Segurança: Partes 1, 2 e 3

## 1. Nenhum segredo no código

A aplicação não contém credenciais reais de banco, e-mails reais de usuários ou contas de demonstração.

As configurações de banco são fornecidas por ambiente. Em Ubuntu, use `/etc/ystek/ystek.env` com permissão `600` e mantenha esse arquivo fora do document root.

## 2. Primeiro SUPER_ADMIN somente por instalação CLI

A web não cria mais SUPER_ADMIN automaticamente. Execute:

`php scripts/create_super_admin.php`

O comando solicita as informações ao operador e grava somente o hash da senha.

## 3. Sem fallback para o banco postgres

O componente de banco conecta somente ao database configurado. Não existe tentativa automática em outro database.

## Fluxo recomendado

1. Criar o banco no PostgreSQL/pgAdmin.
2. Configurar o ambiente externo.
3. Aplicar `database/ystek_schema.sql` via CLI/pgAdmin.
4. Criar o primeiro SUPER_ADMIN por CLI.
5. Publicar somente a pasta da aplicação sem o arquivo de ambiente real.
