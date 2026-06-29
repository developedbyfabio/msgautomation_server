# Decisoes de Arquitetura (msgautomation)

## Auditoria (Fase 0) — resumo e veredito

**Stack confirmada no servidor:** PHP 8.5.7, Composer 2.9.8, Node 22 / npm 10, MySQL 8.0.46,
Docker 29.6.1 / Compose v5.2.0. Laravel 13.17, Livewire 4.3, Flux 2.15, Tailwind v4 / Vite 8
(mesmas versoes do Nextgest). Nenhuma versao alvo precisou de substituta.

**Redis:** **nao existe no host** (so o container interno da Evolution). Por isso o app ganhou
um Redis **dedicado** (ver D5), com cliente **predis** (nao ha extensao phpredis no host).

**Gate de recursos — GO:**
- RAM: 9,7 GiB total, ~7,8 GiB disponivel. Stack atual da Evolution idle ~338 MiB; 2a stack
  medida ~270 MiB. Folga grande.
- Disco: o `df` global mostrava os overlays do Docker em 20G/3.8G (parecia critico), mas o
  **data-root e `/srv/docker` em `/dev/xvda3` (`/srv`), com 73 GB livres** (confirmado por
  `findmnt`/`df` diretos e `daemon.json`). Imagens ja em cache. `>= 15 GB` -> GO.
  - A raiz `xvda2` esta em 80% (3,8 GB), mas **nao recebe** dados do Docker nem do projeto.
- CPU: 6 nucleos, load ~0,2. GO.

---

## Decisoes

### D1 — Segunda Evolution ISOLADA (nao reusar a do Nextgest)
Sobe-se uma **2a instancia** da Evolution, separada. Motivo: o msgautomation vai **responder
automaticamente** (camadas futuras) — perfil de **alto risco de ban**; o Nextgest so envia pra
clientes reais. Misturar criaria risco cruzado, confusao de webhook e consumo somado.

### D2 — Topologia da 2a Evolution
- Imagem `evoapicloud/evolution-api:v2.3.7` (namespace oficial; `atendai/*` foi descontinuado).
  Imagem ja estava em cache no servidor.
- **Postgres + Redis proprios**, na rede `evonet_msg`. **So a API publica porta**, em
  `127.0.0.1:8090` (a do Nextgest fica em 8088).
- Compose com `name: msgautomation_evo` (o diretorio se chama "evolution", igual ao do Nextgest;
  o name explicito evita cruzamento de projeto/volumes/redes).
- Arquivo: `docker/evolution/docker-compose.yml`. Segredos no `.env` proprio (chmod 600, gitignored).

### D3 — Banco MySQL proprio
Database **`msgautomation`** + usuario dedicado **`msgautomation`** (senha forte so no `.env`).
Nada compartilhado com o Nextgest (`nextgest_central`/`tenant_*` sao intocaveis).

### D4 — Modelo de dados (multi-tenant em mente, sem implementar tenancy agora)
- `accounts` — ancora de conta/usuario. Single-user na Camada 1, mas uma linha.
- `channels` — instancia da Evolution + estado da conexao. **Sem segredos** (token fica no `.env`).
- `incoming_messages` — mensagens recebidas, com `raw_payload` (JSON) integral.
- **Idempotencia:** indice unico `(instance, evolution_message_id)`. Re-entrega -> ignora (sem erro).
Detalhe completo em `02 - Modelo de Dados.md`.

### D5 — Redis do app DEDICADO (isolado)
Nao ha Redis no host. O app usa um container **`msgautomation_redis`** (no compose, rede separada
`appnet_msg`), publicado em `127.0.0.1:6380`, com `REDIS_DB`/`REDIS_PREFIX=msgauto_` proprios.
O Redis da Evolution-msg fica **a parte** (interno, rede `evonet_msg`). Cliente: **predis**.
`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`. Fallback documentado: driver `database`.

### D6 — Webhook: validacao de origem + alcance host/container
- A Evolution (container) alcanca o app no host via `extra_hosts: host.docker.internal:host-gateway`.
  URL configurada: `http://host.docker.internal:8190/webhook/evolution`.
- Evento assinado: **`MESSAGES_UPSERT`** (webhook **por instancia**; `WEBHOOK_GLOBAL_ENABLED=false`).
- **Validacao de origem:** header secreto `X-Webhook-Secret` (`WEBHOOK_SECRET` no `.env`), comparado
  em **tempo constante** (`hash_equals`). A v2.3.7 **aceita header custom** no webhook (confirmado).
- App servido em `0.0.0.0:8190` pra o container alcancar via gateway. Em dev, localhost/LAN, sem internet.

### D7 — Abstracao de driver (porta aberta pra trocar de provedor)
Contrato `App\Contracts\WhatsappGateway` + `App\Whatsapp\Drivers\EvolutionDriver`. Na Camada 1 so o
**lado de ENTRADA** (normalizar payload -> DTO `IncomingMessageData`). **Envio e stub** (`sendText`
lanca excecao) — sera implementado na Camada 2. Gerencia da instancia (criar/webhook/QR/estado) fica
em `App\Whatsapp\EvolutionApi`, separada do driver.

### D8 — Fluxo do webhook (assincrono)
`webhook -> valida origem (middleware) -> enfileira ProcessIncomingWhatsappMessage -> responde 200`.
O **job** (fora do request) normaliza via driver e persiste com idempotencia. Nada processado no request.

### D9 — Escopo NEGATIVO (limites da Camada 1)
Sem responder, sem enviar, **sem IA**, **sem chat/UI de conversas**. Nao reusar a Evolution do
Nextgest. Nao expor nada na internet.
