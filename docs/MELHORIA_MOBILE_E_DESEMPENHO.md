# Melhorias de experiência mobile e desempenho — YSTEK

## O que mudou

### Experiência no celular
- Navegação inferior fixa com atalhos para Início, Agenda, Ordens e Caixa (ou Clientes para perfis sem acesso financeiro).
- Atalho **Mais** abre o menu lateral existente; o menu continua acessível pelo botão superior.
- Indicadores em duas colunas, formulários em uma coluna, campos maiores e modais ajustados à altura do aparelho.
- Tabelas operacionais passam a ser apresentadas como cartões com rótulos por campo em telas de até 780 px.
- Espaçamento inferior considera a barra de navegação e a área segura de aparelhos com recorte/gestos.

### Tempo de abertura das telas
- A validação de usuário e empresa do tenant passou a usar uma consulta com `JOIN`, reaproveitando a conexão atual.
- O fallback de assinatura usa os dados do tenant já carregados, evitando uma consulta adicional quando não há registro em `subscriptions`.
- A leitura da revisão realtime reaproveita a mesma conexão e o cache da requisição.
- O Dashboard calcula indicadores com agregações SQL e traz somente as seis O.S. mais recentes do dia.
- A Agenda carrega clientes, veículos e itens relacionados apenas às ordens do dia selecionado.
- Ordens, Clientes, Veículos e Lançamentos Financeiros exibem 25 registros por página, em vez de renderizar todo o histórico.
- O resumo financeiro por forma de pagamento é calculado no PostgreSQL.
- A frequência de verificação realtime do navegador foi ajustada de 5 para 12 segundos, com recuo progressivo quando há falhas.
- Foi incluída uma barra de progresso visual ao navegar. Ela dá retorno imediato ao clique, sem fingir que a resposta do servidor já terminou.

## Migration de índices

Para acelerar as consultas por empresa e data, execute uma única vez no SQL Editor do projeto Supabase usado pelo YSTEK:

`database/migrations/20261118_mobile_performance_indexes.sql`

A migration usa somente `CREATE INDEX IF NOT EXISTS`. Não apaga, atualiza ou recria registros. Os mesmos índices também foram acrescentados aos schemas de instalação nova.

## Publicação

1. Faça backup/retenção normal do repositório antes de substituir arquivos.
2. Copie os arquivos desta entrega por cima do checkout local do projeto, sem apagar `.git`, `.env.local` ou outros arquivos locais não incluídos na entrega.
3. Execute a migration no Supabase novo `YSTEK--lava-rápido`.
4. Faça commit e push para a branch ligada à Vercel; aguarde o deployment ficar `Ready`.
5. Teste no desktop e no celular: login, Dashboard, Agenda, abrir/editar O.S., Clientes, Veículos e Financeiro.

## Observações sobre velocidade

A aplicação ainda depende da latência de rede entre o runtime da Vercel e a região do PostgreSQL Supabase. As mudanças reduzem consultas repetidas, conexões redundantes e quantidade de dados transferidos, mas não removem a latência física entre regiões. Não foi feito benchmark contra o ambiente de produção nesta entrega; compare os tempos depois do deploy, usando os mesmos aparelhos e a mesma conexão.
