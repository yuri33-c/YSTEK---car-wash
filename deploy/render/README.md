# Publicar o YSTEK no Render

O YSTEK usa PHP e PostgreSQL. No Render, publique-o como **Web Service com Docker**; o serviço web e o PostgreSQL são recursos separados.

## Criar o serviço web

1. No Render, crie um **New Web Service** e conecte `ystekagency-code/YSTEK---car-wash`.
2. Selecione a branch `main`, o runtime **Docker** e o `Dockerfile` na raiz do repositório.
3. Configure estas variáveis no painel do serviço, sem colocá-las no GitHub:

   | Variável | Valor |
   | --- | --- |
   | `YSTEK_APP_ENV` | `production` |
   | `YSTEK_DB_HOST` | Host do PostgreSQL |
   | `YSTEK_DB_PORT` | Porta do PostgreSQL |
   | `YSTEK_DB_NAME` | Nome do banco |
   | `YSTEK_DB_USER` | Usuário do banco |
   | `YSTEK_DB_PASS` | Senha do banco |

4. Verifique no provedor do PostgreSQL se conexões de saída do Render estão autorizadas. Não compartilhe credenciais no chat nem as salve no repositório.
5. Inicie o deploy. O Apache escuta na porta `10000`, usada pelo serviço Docker no Render.

## Banco e primeiro acesso

- O serviço não cria nem migra o banco automaticamente. Se o banco ainda não tiver o schema do YSTEK, faça backup e aplique `database/ystek_schema.sql` pelo administrador PostgreSQL antes de receber dados de produção.
- Só crie o primeiro usuário se ainda não houver um. No shell do serviço, execute `php scripts/create_super_admin.php` e informe os dados diretamente no terminal.
- Não execute `scripts/reset_db.php` em um banco com dados que deseja preservar.

O `app/error.log` da máquina local é ignorado pelo Git e não é enviado na imagem; o container cria um arquivo de log vazio para a aplicação.
