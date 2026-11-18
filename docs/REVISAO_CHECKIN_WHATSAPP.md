# Revisão do Check-in e WhatsApp

Data: 18/11/2026

## Check-in e checklist

- A seleção de ordens recentes passou de uma tabela larga para cartões com O.S., cliente, veículo, placa, data, status e ação direta.
- A tela do check-in ganhou resumo do veículo/cliente/O.S., etapas separadas para entrada e avarias, e cartões para cada avaria registrada.
- Os campos de quilometragem e combustível mantêm entrada numérica; os itens do checklist ganharam alvos de toque maiores.
- O layout adapta a composição em três faixas: até 380 px (uma coluna), 381–780 px (resumo/checklist em duas colunas quando há espaço) e telas maiores (grade ampla; check-in em uma ou duas colunas conforme a largura disponível).
- As tabelas restantes, como histórico do veículo, continuam usando a conversão mobile para cartões já presente no `app-shell.js`.

## Mensagem de conclusão no WhatsApp

Antes desta revisão, `SettingsController` salvava `tenant_settings.whatsapp_message`, mas `OrdersController` ignorava esse campo e sempre chamava uma frase fixa. Agora:

1. Ao exibir a confirmação de uma O.S. finalizada, o controller lê o modelo de mensagem da mesma empresa (`tenant_id`).
2. Se o modelo estiver vazio, usa uma mensagem padrão.
3. Os marcadores são preenchidos com dados da O.S., cliente, veículo, serviço e empresa.
4. O telefone de destino é o telefone do cliente; se o cadastro tiver sido removido ou estiver sem telefone, usa o retrato histórico salvo na O.S.
5. O link `wa.me` abre o WhatsApp com a mensagem preparada. O envio não é automático: o usuário confere o texto e toca em Enviar no WhatsApp.

### Marcadores disponíveis

`[NOME]`, `[MARCA]`, `[MODELO]`, `[VEICULO]`, `[PLACA]`, `[SERVICO]`, `[VALOR]`, `[DATA]`, `[HORA]`, `[EMPRESA]`, `[OS]`, `[WHATSAPP_EMPRESA]` e `[ENDERECO]`.

Também é aceito o formato `{{NOME}}`. Os nomes não diferenciam maiúsculas e minúsculas.

Exemplo de modelo:

```text
Olá, [NOME]!
O serviço [SERVICO] do seu [MODELO], placa [PLACA], foi concluído.
Valor: [VALOR]. Retirada: [DATA] às [HORA].
O.S. [OS] · [EMPRESA]
```

O campo de modelo em Configurações aceita até 2.000 caracteres. Se for deixado vazio, o sistema usa a mensagem padrão.

## Banco de dados

Esta revisão não adiciona tabelas nem colunas. Usa `tenant_settings.whatsapp_message` e `tenant_settings.whatsapp_number`, que já existem no schema instalado. Não é necessário executar outro SQL no Supabase.

## Verificações executadas nesta revisão

- `php -l` nos 65 arquivos PHP do projeto: sem erros de sintaxe.
- `node --check public/js/app-shell.js`: aprovado.
- Parsing do CSS com `tinycss2`: 1.100 regras analisadas, nenhum erro de parsing.
- `php tests/WhatsAppServiceTest.php`: todos os testes do modelo, placeholders, normalização de telefone e URL `wa.me` passaram.

## Validação em dispositivos reais ainda necessária

As regras responsivas cobrem telas estreitas de 320–380 px, celulares de 381–780 px, tablets e desktop. Ainda é necessário conferir visualmente em aparelhos reais, porque emulador/headless de navegador não ficou disponível neste ambiente e não foram conectados dispositivos físicos. Recomenda-se conferir Safari iOS e Chrome Android, especialmente em 320, 360, 375, 390, 414, 430 e 768 px.

## Teste funcional após publicar

1. Entre como administrador da empresa e abra Configurações → Comunicação e operação.
2. Salve um modelo personalizado usando alguns marcadores acima.
3. Finalize uma O.S. de teste com cliente e telefone válidos.
4. Confira os dados e a mensagem na janela de conclusão.
5. Toque em Abrir WhatsApp e confirme o destinatário e o texto pré-preenchido, mas não envie a mensagem para um cliente real durante o teste.
6. Teste o check-in em um celular real, incluindo rolagem, campos numéricos, checkboxes, cadastro/remoção de avaria e histórico.
