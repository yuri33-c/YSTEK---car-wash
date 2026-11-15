# YSTEK — Próximo passo operacional

## Entrega

Esta etapa adiciona o fluxo de entrada do veículo após a Agenda:

1. Agenda abre o atendimento.
2. Check-in registra quilometragem, combustível, chave, acessórios e objetos.
3. Checklist registra avarias por área, tipo, gravidade e descrição.
4. Ao confirmar a entrada, uma O.S. Pendente passa para Em Andamento.
5. O Premium ganha histórico das inspeções da mesma placa.
6. Todas as mutações são registradas na auditoria e protegidas por CSRF/tenant.

## Planos

- Profissional: Check-in & Checklist.
- Premium SaaS: Check-in & Checklist + Histórico de inspeções do veículo.
- Iniciante: continua com Agenda e O.S., sem o módulo avançado de inspeção.

## Migração

Execute `php scripts/migrate_operational_checkin.php` em instalações existentes.
