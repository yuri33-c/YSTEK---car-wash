# Camada de View

As views PHP ficam em `app/view` e são carregadas por `App\Core\View`.

As funcionalidades novas e as telas de autenticação usam a camada de view dedicada. Algumas telas operacionais legadas ainda geram parte do HTML dentro dos controllers; essa migração é incremental para preservar a estabilidade do sistema durante a fase comercial.
