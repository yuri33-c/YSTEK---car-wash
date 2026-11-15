# Arquitetura MVC — YSTEK Lava Jato

## Camadas

- **Model (`app/model`)**: mapeia entidades PostgreSQL e usa `TRecord` para persistência.
- **Controller (`app/control`)**: recebe a requisição, valida fluxo, chama Models/Services e decide a resposta.
- **View (`app/view`)**: contém a apresentação HTML compartilhada. O login e o layout autenticado já utilizam `App\Core\View`.
- **Service (`app/service`)**: concentra regras reutilizáveis, como catálogo e limites dos planos.
- **Core (`app/core`)**: infraestrutura transversal, como sessão/autenticação e renderização de views.

## Multi-tenant

Toda leitura ou alteração operacional usa `tenant_id` da sessão. Não existe `tenant_id = 1` hardcoded.

## Sessão

O `App\Core\Auth` revalida o status do tenant em cada requisição. Uma empresa suspensa perde a sessão e é encaminhada ao login.

## Planos

`PlanCatalog` é a única fonte das regras de plano. `PlanGuard` consulta o catálogo para liberar recursos e aplicar limites. O Premium possui recurso adicional de análise financeira.

## Exclusão de cliente

Antes de remover um cliente, referências das ordens de serviço são anuladas para preservar o histórico. Os veículos do cliente continuam sujeitos às regras de integridade da tabela.

## Comentários

Os controllers, models, services e classes de infraestrutura alterados nesta versão possuem docblocks/comentários explicando responsabilidade e decisões importantes.

## Segredos e instalação

Segredos não fazem parte das Views, Controllers, Models ou Services. Eles são fornecidos pelo ambiente do servidor e carregados por `App\\Core\\Environment`. O primeiro SUPER_ADMIN é criado somente por CLI e com dados digitados durante a instalação.

## Segredos por ambiente

Credenciais e segredos ficam fora das camadas MVC e fora do código-fonte. `App\Core\Environment` carrega apenas configurações fornecidas pelo servidor. A criação da primeira conta administrativa ocorre por CLI.
