# Segurança — Partes 1, 2 e 3

## Parte 1 — Credenciais fora do código

O código-fonte não contém e-mails reais, senhas de usuários, senha do PostgreSQL ou contas de demonstração.

A aplicação lê estas variáveis:

- `YSTEK_DB_HOST`
- `YSTEK_DB_PORT`
- `YSTEK_DB_NAME`
- `YSTEK_DB_USER`
- `YSTEK_DB_PASS`

O arquivo recomendado para Ubuntu é `/etc/ystek/ystek.env`, fora do document root e com permissão `600`. No Windows, use `%ProgramData%/YSTEK/ystek.env` ou forneça as mesmas variáveis diretamente ao processo do PHP/Apache.

## Parte 2 — Nenhum SUPER_ADMIN automático

O `index.php` não cria usuários e não executa DDL de banco. A primeira conta administrativa é criada somente pela CLI:

`php scripts/create_super_admin.php`

O script solicita interativamente o e-mail, nome e senha e não salva esses valores no código-fonte.

## Parte 3 — Sem fallback para o banco `postgres`

O `TTransaction` conecta exclusivamente ao banco definido no ambiente. Não existe fallback automático para o database `postgres`.

Para produção, utilize um usuário PostgreSQL próprio da aplicação com privilégios mínimos e mantenha a conta administrativa do PostgreSQL separada.

## Checklist antes de publicar

- nenhum e-mail real em código;
- nenhuma senha real em código;
- nenhum usuário de demonstração;
- nenhum SUPER_ADMIN criado automaticamente;
- nenhum fallback para `postgres`;
- `scripts/` bloqueado por HTTP;
- segredo do banco fora do document root;
- `dev-tools/` fora do fluxo de produção.
