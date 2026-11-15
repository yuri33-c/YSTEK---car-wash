# Validação da adaptação mobile e sincronização

## Verificações executadas

- `php -l` passou nos 68 arquivos PHP do projeto atualizado.
- `node --check public/js/app-shell.js` passou.
- A migração foi aplicada e reaplicada em um PostgreSQL temporário: as duas execuções retornaram sucesso, confirmando idempotência.
- Inserts, updates, exclusões e alterações em itens da ordem incrementaram a revisão da empresa correspondente. Um `ROLLBACK` reverteu também o contador.
- O controlador retornou revisões diferentes com dois valores de `tenant_id` de sessão; o tenant é obtido da sessão no código e não da URL.
- Chromium headless conferiu uma tela representativa com tabela larga e modal em 320, 360, 390, 430, 768 e 1280 px. `document`, `body` e área principal permaneceram dentro da largura do viewport; a tabela ficou rolável dentro do próprio contêiner. O botão de navegação apareceu nas larguras mobile e ficou oculto no desktop.
- Em 320 px, a tabela de 860 px permaneceu dentro de um contêiner de 244 px e o modal ficou com 280 px, sem overflow horizontal da página.
- Regressão mobile em 320×568, 360×800, 390×844, 430×900 e 768×900: cabeçalho e conteúdo ficaram em fluxo, sem sobreposição; a área “Sair do sistema” ficou visível no menu, inclusive no viewport de 568 px de altura. Em 1280×900, o botão mobile ficou oculto e o cabeçalho voltou à altura desktop.

## Limite do ambiente

O ZIP não contém credenciais do banco e não havia configuração `YSTEK_DB_*` no ambiente. Por isso, `scripts/health_check.php` e o fluxo entre dois usuários em uma instalação completa não foram executados contra o banco do projeto. A validação do endpoint e dos triggers foi feita em um banco PostgreSQL temporário; para ativar a sincronização no banco da instalação, execute `php scripts/migrate_realtime.php` conforme `SINCRONIZACAO_MOBILE.md`.
