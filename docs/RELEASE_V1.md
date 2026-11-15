# YSTEK — Release Candidate 1.0

## Objetivo

Base comercial para iniciar pilotos pagos de lava-jatos com isolamento por empresa, planos, assinatura e controle de acesso.

## O que está pronto

- Login por e-mail e senha com `password_hash()`/`password_verify()`.
- Perfis `SUPER_ADMIN`, `ADMIN_TENANT`, `MANAGER`, `CASHIER` e `WASHER`.
- Isolamento por `tenant_id` nas consultas de registros editáveis/excludentes revisadas.
- Suspensão manual bloqueando novas entradas e sessões existentes na próxima requisição.
- Vencimento + carência de 3 dias avaliados no backend.
- Logout com destruição de sessão e cabeçalhos de no-cache.
- CSRF nos POSTs e mutações principais migradas de GET para POST.
- Cadastro de empresa com administrador e senha na mesma operação.
- Planos Iniciante, Profissional e Premium SaaS com preços, limites e recursos implementados.
- Gestão de equipe em planos que possuem o recurso.
- Relatórios, financeiro avançado e auditoria de acordo com o plano.
- Renovação manual de assinatura pelo SUPER_ADMIN.
- Health check e migração idempotente.

## Para começar a vender

Pode ser usado em **piloto pago/manual**: o SUPER_ADMIN cadastra a empresa, escolhe o plano e registra a renovação manual.

A cobrança automática recorrente ainda exige um provedor de pagamentos e credenciais reais de produção. Essa integração deve ser homologada com webhooks antes de ser anunciada como cobrança automática.

## Arquitetura

A aplicação possui Front Controller, Controllers, Models, Services, Core e Views. A separação está organizada e comentada, mas algumas telas legadas ainda possuem HTML dentro dos controllers; a migração completa para arquivos de view separados é uma melhoria arquitetural posterior e não deve ser feita às pressas antes do piloto.

## Comandos locais

- Instalação inicial: `php scripts/init_db.php`
- Migração comercial: `php scripts/migrate_commercial.php`
- Usuários de demonstração: `php scripts/create_super_admin.php`
- Diagnóstico: `php scripts/health_check.php`
