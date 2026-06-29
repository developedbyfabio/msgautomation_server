# msgautomation — Visao Geral

Automacao do WhatsApp **pessoal** do Fabio. Sistema **novo e separado** do Nextgest.

O coracao e **RECEBER** mensagens via **webhook da Evolution** e registrar. Em camadas
futuras o sistema vai **reagir** (responder, IA, etc.).

## Camada 1 (estado atual)
**Somente RECEBER e REGISTRAR.** Nesta camada o sistema **nao responde nada**:
- Nao responde, nao envia, **sem IA**, **sem chat/UI de conversas**.
- So: webhook recebe -> valida origem -> enfileira -> job normaliza -> persiste (idempotente).

## Ambiente
- Dev: `192.168.11.210` (Ubuntu), projeto em `/srv/www/msgautomation`.
- Tudo em **localhost/LAN**, **nada exposto a internet** (sem Nginx publico, sem HTTPS publico).
- Servidor **compartilhado** com Nextgest e outros projetos — mexer so no escopo do msgautomation.

## Stack (confirmada na auditoria — igual a que o Nextgest ja roda)
- Laravel **13.17**, Livewire **4** + Flux **2**, Tailwind **v4** + Vite **8**.
- PHP **8.5.7**, MySQL **8.0.46** (host), Redis via **predis** (cliente PHP; nao ha extensao phpredis no host).
- Fila **Redis** (`QUEUE_CONNECTION=redis`). `APP_TIMEZONE=America/Sao_Paulo`.
- **2a Evolution** `evoapicloud/evolution-api:v2.3.7`, **isolada** (Docker), em `127.0.0.1:8090`.

## Mapa rapido de portas (dev)
| Servico | Porta | Bind |
|---|---|---|
| App Laravel (artisan serve) | 8190 | 0.0.0.0 (LAN) |
| 2a Evolution (API) | 8090 | 127.0.0.1 |
| Redis do app | 6380 | 127.0.0.1 |
| MySQL (host, compartilhado) | 3306 | 127.0.0.1 |
| Evolution do Nextgest (NAO usar) | 8088 | 127.0.0.1 |

## Documentos
- `01 - Decisoes de Arquitetura.md` — decisoes numeradas (D1..D7) e veredito da auditoria.
- `02 - Modelo de Dados.md` — tabelas e idempotencia.
- `03 - Operacao em Dev.md` — como subir, servir, worker, comandos.
- `04 - Riscos e Conformidade.md` — anti-ban, LGPD, exposicao futura.
