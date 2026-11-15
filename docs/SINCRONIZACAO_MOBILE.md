# Mobile e sincronização da operação

## Atualização do banco

Depois de configurar o PostgreSQL e aplicar as migrações já existentes, execute a nova migração pela linha de comando:

```text
php scripts/migrate_realtime.php
```

O script cria a revisão por empresa e triggers nas tabelas operacionais disponíveis. A migração é aditiva e pode ser executada novamente. Ela não é executada por uma requisição web.

Confira a instalação com:

```text
php scripts/health_check.php
```

## Comportamento

- Telas da operação consultam a revisão da própria empresa a cada cinco segundos enquanto a aba está visível.
- Uma mudança detectada atualiza o conteúdo principal sem recarregar a página inteira. Abas ocultas suspendem consultas; falhas aumentam o intervalo de nova tentativa até 30 segundos.
- Se houver um modal aberto ou alterações não enviadas em um formulário, a atualização aguarda o usuário terminar.
- As triggers atualizam a revisão na mesma transação que grava a alteração. Itens da ordem são associados à empresa por `service_orders`.
- A navegação mobile usa um painel lateral; a folha de estilos mantém o layout de desktop fora do breakpoint mobile.

## Verificação recomendada

Com uma instalação conectada ao banco, abra a mesma empresa em dois navegadores, crie/edite uma ordem e confirme a atualização em até cinco segundos. Repita para status da agenda, check-in, cadastro, financeiro e serviços. Confirme também que usuários de empresas diferentes não veem as alterações uns dos outros e que uma edição ainda aberta não é substituída.

Verifique a interface em 320, 360, 390 e 430 px, em tablet e em desktop. Em todas as larguras mobile, o documento não deve ter rolagem horizontal; tabelas largas podem rolar dentro do próprio contêiner.
