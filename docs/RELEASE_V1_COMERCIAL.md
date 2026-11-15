# YSTEK Lava Jato — Release V1 Comercial

## Objetivo
Esta versão concentra as correções necessárias para transformar o projeto em uma base comercial mais segura e previsível para os primeiros clientes pagantes.

## Fluxo comercial atual
1. O SUPER_ADMIN cadastra o lava-jato em uma única tela.
2. No mesmo cadastro define administrador, e-mail, senha, plano, status e vencimento.
3. O sistema cria o tenant e o usuário `ADMIN_TENANT` em uma transação.
4. O preço do plano é definido exclusivamente pelo catálogo do servidor.
5. O SUPER_ADMIN pode renovar manualmente a assinatura por 30, 90 ou 365 dias.
6. O status efetivo da assinatura é calculado pelo banco considerando vencimento e até 3 dias de carência.
7. A cada requisição autenticada, o usuário e o tenant são revalidados.
8. Empresa suspensa, assinatura vencida fora da carência ou usuário desativado não continuam com acesso.

## Planos configurados
### Iniciante — R$ 49,90/mês
Operação básica, com limites menores.

### Profissional — R$ 99,90/mês
Inclui gestão de equipe, relatórios, exportação e indicadores avançados.

### Premium SaaS — R$ 199,90/mês
Inclui relatórios avançados, análise financeira avançada e auditoria.

Os limites e recursos são centralizados em `app/service/PlanCatalog.php` e protegidos pelo `PlanGuard` no backend.

## Segurança adicionada
- Sessão com cookies HTTP-only e SameSite.
- Regeneração do ID da sessão após login.
- Logout por POST + CSRF.
- Cabeçalhos de no-cache e segurança.
- CSRF obrigatório para POST.
- Revalidação do usuário a cada requisição autenticada.
- Revalidação de tenant e assinatura a cada requisição operacional.
- Limitação de tentativas de login por e-mail e IP.
- Validação explícita de relações cliente/veículo/tenant.
- Nenhum `tenant_id = 1` hardcoded.
- Proteção do tenant administrativo da YSTEK contra alterações pela carteira comercial.

## Integridade dos dados
- Placas não podem ser duplicadas dentro da mesma empresa.
- O.S. exige cliente e veículo pertencentes ao tenant correto.
- Status e formas de pagamento são validados no backend.
- Valores financeiros e preços têm limites e validações.
- Alterações comerciais não confiam em valores enviados pelo navegador.

## Verificações realizadas
- Lint completo dos arquivos PHP da aplicação: OK.
- Renderização CLI da página de login: OK.
- Varredura por `tenant_id = 1` / `id = 1`: nenhum resultado na aplicação.
- Ações de alteração de estado estão em POST.

## Antes da produção pública
- Configurar `YSTEK_APP_ENV=production`.
- Configurar `YSTEK_DB_HOST`, `YSTEK_DB_PORT`, `YSTEK_DB_NAME`, `YSTEK_DB_USER` e `YSTEK_DB_PASS` no servidor.
- Executar `php scripts/migrate_commercial.php`.
- Executar `php scripts/health_check.php` e corrigir qualquer erro.
- Publicar atrás de HTTPS.
- Configurar backup e monitoramento do PostgreSQL.
- Revisar termos de uso, privacidade e tratamento de dados.
- Integrar um provedor de cobrança para Pix/cartão recorrente antes de automatizar a cobrança.

## Observação de arquitetura
O projeto possui MVC em transição: `View` e `Service` já estão presentes e as novas funcionalidades comerciais seguem a separação por camadas, mas algumas telas legadas ainda montam HTML nos controllers. A migração completa dessas telas deve ser feita em uma etapa separada para evitar regressões no lançamento.
