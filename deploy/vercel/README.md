# Publicar o YSTEK na Vercel

O YSTEK é PHP com PostgreSQL. O `Dockerfile` da raiz usa o **Container Runtime** da Vercel (beta), que executa a aplicação Apache/PHP no container. Não publique o projeto como site estático.

## Preparar o banco

Antes do primeiro deploy, aplique `database/migrations/20261115_ystek_sessions.sql` no PostgreSQL existente. A migração cria somente a tabela compartilhada de sessões. Faça isso pelo administrador do banco; não envie as credenciais ao GitHub ou ao chat.

## Criar o projeto

1. Importe `yuri33-c/YSTEK---car-wash` na Vercel e selecione a branch `main`.
2. Deixe o `Dockerfile` na raiz e selecione o framework **Container** se a detecção automática não o selecionar.
3. Configure as variáveis de ambiente de produção:

   | Variável | Valor |
   | --- | --- |
   | `YSTEK_APP_ENV` | `production` |
   | `YSTEK_DB_HOST` | Host do PostgreSQL |
   | `YSTEK_DB_PORT` | Porta do PostgreSQL |
   | `YSTEK_DB_NAME` | Nome do banco |
   | `YSTEK_DB_USER` | Usuário do banco |
   | `YSTEK_DB_PASS` | Senha do banco |
   | `YSTEK_DB_SSLMODE` | `require` |

   `YSTEK_SESSION_DRIVER=pgsql` e `YSTEK_ERROR_LOG=/dev/stderr` já são definidos pela imagem. O container escuta na porta padrão `80`.

4. No Supabase, use a conexão **Session Pooler** (porta `5432`), não o Transaction Pooler: as sessões PHP usam locks do PostgreSQL que dependem de uma conexão persistente. O usuário normalmente tem o formato `postgres.<project-ref>`.
5. Verifique se conexões externas da Vercel são permitidas. Faça o deploy depois de aplicar a migração e configurar as variáveis.

## Observações

- O sistema não aplica schemas nem cria usuários durante requisições HTTP. Para banco novo, revise e aplique o schema e as migrações apropriadas antes de usar.
- Se ainda não houver usuário administrador, execute `php scripts/create_super_admin.php` em um terminal com as mesmas variáveis de ambiente. Não crie outro usuário se já existir um.
- A aplicação grava sessões no PostgreSQL para que o login funcione entre instâncias; logs PHP são enviados para `stderr`. O container não deve ser usado para armazenar arquivos persistentes.
- O Container Runtime da Vercel está em beta e segue limites e cobrança de Vercel Functions.
