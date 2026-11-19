YSTEK — ATUALIZAÇÃO MOBILE / AGENDA / FINANCEIRO

Arquivos incluídos nesta atualização:
- app/control/DashboardController.php
- app/control/AgendaController.php
- app/control/FinancialController.php
- public/css/style.css
- tests/FinancialStatusTest.php

O que muda:
1. Apenas no celular, o dashboard mostra uma visão da semana atual de segunda a domingo. O gráfico desktop continua mostrando os últimos sete dias como antes.
2. Apenas no celular, a Agenda usa cartões próprios com horário, status, nome do cliente contornado, contato, veículo, placa, serviço, valor e ações. A tabela desktop original permanece visível em computadores.
3. No Financeiro, Receita usa os status "A receber" e "Recebido"; Despesa usa "A pagar" e "Pago". Os valores antigos "Pendente" e "Pago" são interpretados de forma compatível conforme o tipo do lançamento, sem migration.
4. O identificador técnico do lançamento não aparece mais na listagem; o UUID continua sendo usado internamente nos formulários.

Não foi alterada a tabela de usuários nem o fluxo de login da equipe. O formulário atual depende de e-mail e senha porque a tabela exige ambos e o login autentica por e-mail/senha. Remover esses campos requer definir um método de acesso substituto (por exemplo, telefone + senha temporária gerada). Não publique alterações para a equipe antes de definir esse fluxo.

Validação executada nesta entrega:
- php -l nos três controllers.
- 10 cenários do normalizador de status financeiro.
- Parsing da folha CSS: zero erros de sintaxe.

Limitação: não foram executados testes em aparelhos físicos. O CSS mobile é responsivo e usa breakpoints existentes, mas a validação visual final deve ser feita em navegadores/aparelhos reais após o deploy.
