# Auditoria de estabilidade — YSTEK V1

## Escopo

Revisão estrutural de todos os arquivos PHP da aplicação, com foco em fluxo de login/logout, sessão, CSRF, isolamento multi-tenant, planos, assinatura, persistência e rotas.

## Validações realizadas

- Sintaxe PHP verificada em todos os arquivos `.php` do projeto.
- Carregamento das classes da aplicação verificado em CLI.
- Busca por `tenant_id = 1` e `id = 1` hardcoded na aplicação.
- Revisão do fluxo de autenticação e revalidação do usuário/tenant.
- Revisão dos POSTs e proteção CSRF.
- Revisão da limitação de tentativas de login.
- Revisão das queries de assinatura, incluindo compatibilidade com placeholders PDO.
- Acesso direto a `app/`, `lib/` e `scripts/` bloqueado pelo Apache.

## Correção específica do login após logout

A área de login possui token CSRF independente da sessão autenticada. O cookie separado evita que uma tela de login aberta antes de um logout fique inutilizada quando a sessão anterior é destruída.

Após autenticação válida, o token CSRF da sessão é rotacionado e as falhas recentes de login do mesmo e-mail/IP são limpas.

## Regra de segurança

POSTs autenticados continuam protegidos por CSRF de sessão. O login não herda o token da sessão autenticada anterior.

## Observação

O teste contra o PostgreSQL real depende do ambiente local do servidor. O projeto foi validado estaticamente e por carregamento de classes; as credenciais e o banco local devem ser confirmados no AMMPS antes do deploy.
