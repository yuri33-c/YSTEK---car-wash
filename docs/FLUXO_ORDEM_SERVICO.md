# Fluxo profissional de Ordem de Serviço

A tela de Nova Ordem de Serviço centraliza cliente, veículo, serviço, lavador, comissão, pagamento e observações.

## Cliente
É possível selecionar um cliente já cadastrado. Ao selecionar, telefone/e-mail são exibidos e a lista de veículos é filtrada para aquele cliente. Também é possível criar o cliente diretamente na O.S.

## Veículo
O veículo pode ser selecionado entre os veículos do cliente ou criado no mesmo atendimento. A placa é validada para evitar duplicidade na empresa.

## Serviço
O preço é lido do cadastro do serviço no PostgreSQL. O navegador não decide o preço final.

## Lavador e comissão
Os usuários com perfil `WASHER` possuem `commission_percent`. Ao escolher um lavador, a O.S. registra nome, percentual e valor calculado como snapshot, preservando o histórico mesmo que a comissão do usuário mude depois. Ao concluir, a comissão pode ser registrada como despesa no financeiro.

## Conclusão e WhatsApp
Ao concluir a O.S., o sistema abre a ficha de atendimento concluído com cliente, veículo, serviço, lavador, comissão e uma mensagem pronta. O botão abre o WhatsApp do cliente com a mensagem preenchida. Esse fluxo usa `wa.me`; uma API oficial do WhatsApp exige credenciais/provedor e não deve ter token exposto no navegador.
