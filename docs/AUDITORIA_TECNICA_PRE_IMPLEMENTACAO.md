# Auditoria técnica anterior à implementação

Projeto: YSTEK (cópia extraída de `C:\Program Files\Ampps\www\ystek.zip` em 2026-11-14).

## Revisão realizada

- Inventário de 121 entradas do ZIP; nenhum caminho de extração absoluto ou com `..` foi encontrado. 69 arquivos PHP/CSS/SQL/JS, aproximadamente 9.712 linhas.
- Varredura de sintaxe PHP executada nos 65 arquivos `.php`: todos passaram em `php -l`.
- Percurso de rotas, lista de permissões por perfil, autenticação, sessão, CSRF, configuração PostgreSQL e layout global em `index.php`, `Security.php`, `Auth.php`, `TTransaction.php` e `layout.php`.
- Mapeamento das gravações em Agenda, Ordens, Check-in, clientes, veículos, serviços, financeiro, equipe e configurações; leitura das consultas de apresentação e dos estilos responsivos existentes.

## Achados que orientam a implementação

1. A aplicação usa PHP com renderização server-side. Não há bundle JavaScript nem endpoint JSON para atualização; os comportamentos de tela estão em scripts inline nos controladores.
2. O `index.php` já centraliza sessão, verificação de usuário/empresa, matriz de rotas por perfil e validação CSRF para POST. O endpoint novo deve passar por esses controles e retornar apenas a versão da empresa da sessão.
3. O banco é PostgreSQL. As gravações usam tanto autocommit como transação explícita. `TTransaction::close()` apenas libera a conexão; não representa commit. Uma tabela de versão atualizada por triggers permite observar gravações existentes e incrementá-las dentro da mesma transação, sem depender de alterar cada fluxo de formulário.
4. `service_order_items` não carrega `tenant_id`; a trigger deve encontrar a empresa pela ordem associada. As demais tabelas operacionais relevantes carregam `tenant_id`.
5. A folha `style.css` já possui breakpoints, porém as regras estão espalhadas em blocos e temas sobrepostos. Em telas pequenas, a navegação passa para o fluxo normal como uma coluna extensa; tabelas têm rolagem horizontal, mas grids, ações, topbar e alguns formulários ainda podem exceder a largura útil.
6. A maior parte das ações POST redireciona de volta à tela. As telas incluem funções globais inline e alguns listeners anexados ao DOM; a atualização por fragmento precisa preservar funções existentes e evitar perder dados enquanto formulários ou modais estiverem em uso.
7. Não há configuração de banco fornecida no ZIP. As verificações de banco e a validação dinâmica entre empresas dependerão de um PostgreSQL configurado; não executarei migração contra um banco desconhecido.

## Escopo e limites

A revisão estática cobre os arquivos-fonte e configuração indicados acima. Imagens, log de execução, documentação de produto e biblioteca `lib/adianti` foram inventariados, mas não serão tratados como código da aplicação a alterar. O ZIP original permanece intacto.
