# Servidores — Fatia 4 (S4): agente coletor + tutorial na aba + seleção de partições — 2026-07-07

Git no início: HEAD `5190e3a` (relatório S3), branch `master`, working tree limpo.
**`APP_ENV=local` confirmado** (DEV `192.168.11.210`, host `laravel-dev`). Remote atual:
**`origin → git@github.com:developedbyfabio/msgautomation_server.git`** (o `msgautomation_server`,
como o dono avisou). **NÃO houve push.** Produção `187.127.24.165`/Nextgest/nginx/Tunnel: intocados.

**Baseline no início: VERMELHO (5 falhas).** Causa: o dono ligou `SERVERS_NOTIFICATIONS_ENABLED=true`
no `.env` (linha 97) e os testes de modo silencioso da S2/S3 liam o valor real do ambiente. Um
teste não pode depender de um toggle de produção → **fixei `SERVERS_NOTIFICATIONS_ENABLED=false`
no `phpunit.xml`** (mesmo padrão das chaves ASAAS; os testes de envio real sobrescrevem via
`config()->set`). Baseline restaurado: **1040 verdes / 4070 assertions**.
**Final: 1053 verdes / 4114 assertions** (+13: 6 coletor + 7 partições). Zero regressão.

**Commits (atômicos, sem push):**
- `8001951` — fix do baseline (phpunit.xml)
- `cd0cca2` — seleção de partição por servidor
- `5dd3142` — agente coletor + tutorial na aba

---

## Diagnóstico (antes de codar o coletor)

**Linguagem escolhida: bash puro.** Justificativa: roda em qualquer Linux sem dependência
(`/proc`, `df`, `nproc`, `curl`), pegada mínima, o servidor monitorado não precisa ter Python
na versão certa. O parsing rústico é contido (poucos campos, awk sobre `/proc` e `df`). Python
só compensaria com parsing complexo, que não é o caso. Decisão registrada; o dono delegou.

**Payload/token/schema confirmados lendo o código real da S1** (`ServerIngestController`):
- Token no header **`X-Agent-Token`** (401 sem/errado).
- Validação: `cpu_pct` (req 0-100), `cpu_count`, `load[]` (≤3), `mem.pct` (req), `swap.pct`,
  `disks[]` (req, ≤20) com `disks.*.mount` (req) + `disks.*.pct` (req) + `total_gb`/`used_gb`.
- A S2 lê `disks[].mount` + `disks[].pct` e avalia **por partição** (incidente carrega o mount).
O agente monta exatamente esse JSON (provado end-to-end: `--dry-run` do agente → POST → 200).

---

## O agente coletor (`deploy/agent/msgautomation-agent.sh`)

Requisitos da Fase 0, todos atendidos:
- **Read-only**: só lê `/proc/stat`, `/proc/meminfo`, `/proc/loadavg`, `df -PT`, `nproc`. Não
  escreve nada no host (saída vai ao journald quando rodado por systemd).
- **PUSH de saída**: uma requisição por execução (`curl` POST). **Nenhuma porta de escuta.**
- **Privilégio mínimo**: o timer roda como **`nobody`** (leitura de `/proc`/`df`/`nproc` não
  exige root). Documentado no unit.
- **Backoff**: 3 tentativas com espera curta crescente; erros definitivos (401/403/413/422) não
  insistem; transitórios (000/5xx/429) recuam e, esgotado, **desiste** (amostra perdida é
  tolerada — a avaliação central tolera gap). Não acumula, não trava o host.
- **Token**: lido de `/etc/msgautomation-agent/config` (chmod 600/640) para variável de
  ambiente do próprio processo — **nunca** em argumento (`ps`), nunca no script versionado.
- **Removível**: o instalador cria `msgautomation-agent-uninstall` (para o timer, remove units,
  cron, config e binário — sem resíduo).

### Filtragem de disco (o problema que o `df` do dono motivou)
**Critério: allowlist de tipos de FS reais (default-deny) + dedup por device.** Só passam
`ext2/3/4, xfs, btrfs, zfs, jfs, reiserfs, f2fs, vfat, exfat, ntfs, ufs`; qualquer outro tipo
(tmpfs, devtmpfs, overlay, squashfs…) é pseudo-FS e fica de fora; cada device entra **uma vez**.

O `df` real do `laravel-dev` — **15 linhas** (4 tmpfs + 8 overlays do Docker + 2 ext4) — resulta
em **exatamente 2 partições**:
```
{"mount":"/","pct":88,"total_gb":20.0,"used_gb":17.0},{"mount":"/srv","pct":5,"total_gb":79.0,"used_gb":3.5}
```
Os 8 `overlay` (mesmo device, 88%) e os `tmpfs` somem. Testado com o `df` do dono via o **script
bash real** (seam `AGENT_DF_INPUT`), não uma reimplementação.

### Agendamento e transporte
- **systemd timer** a cada 30s (`OnUnitActiveSec=30`, `AccuracySec=1s`), oneshot como `nobody`;
  **fallback cron** de 1 min onde não há systemd (granularidade mínima do cron).
- **HTTPS em produção**: o endpoint deve ser HTTPS quando o tráfego sair da LAN (o dono decide a
  exposição; **não** tocamos Tunnel/nginx). No DEV a LAN é http — aceito para validação; ressalva
  registrada. Em produção o `AGENT_URL` do one-liner deve ser `https://…`.

---

## Tutorial de instalação na aba

- **Rota pública** `GET /servidores/agente/instalar.sh` serve o **instalador com o agente
  embutido verbatim** (o controller injeta `deploy/agent/msgautomation-agent.sh` no heredoc do
  `install.sh`). **Sem segredo**: `AGENT_URL`/`AGENT_TOKEN` vêm por env na hora de instalar e
  param no config 600. Rota `GET /servidores/agente/coletor.sh` serve o agente cru (para
  inspecionar antes — alternativa segura ao pipe).
- **Comando de uma linha** (modal de token ao criar/regenerar, com o token embutido):
  ```
  curl -fsSL http://192.168.11.210:8080/servidores/agente/instalar.sh \
    | sudo AGENT_URL=http://192.168.11.210:8080/webhook/servers/ingest AGENT_TOKEN=<token> sh
  ```
  Passos legíveis ("1. Copie. 2. Cole no servidor como root. 3. Aparece como Recebendo dados em
  ~30s"), **desinstalação** (`sudo msgautomation-agent-uninstall`) e nota de segurança (read-only,
  PUSH de saída, sem porta) visível. A ação **"Instalação"** no dropdown reabre o tutorial; se o
  token não estiver em claro (padrão do Cofre: exibido uma vez), mostra `<SEU_TOKEN>` + oferta de
  **regenerar** para um comando pronto.
- **Ressalva `curl | sh`** (registrada): executa script remoto — aceitável porque o script vem
  **do próprio app** do dono e, em produção, por HTTPS. Alternativa mais segura oferecida no
  tutorial: baixar e inspecionar (`curl …/coletor.sh`) antes de executar. O token passado por env
  no install é brevemente visível em `ps eww` do processo de instalação (ação única de root) e
  não fica em lugar nenhum além do config 600 — ressalva menor registrada.

---

## Seleção de partições por servidor (no painel, não no coletor)

**Achado da inspeção (adaptação consciente):** a S2 tinha **uma** regra `disk` por servidor,
aplicada a todas as partições — `server_alert_rules` **não** tinha coluna `mount`. Para escolher
quais partições alertar e o limiar de cada uma, adicionei **`mount` (nullable, aditiva)**.
- **Resolução por partição no evaluator** (`diskRuleFor`): mais específica primeiro —
  **(servidor, mount) > (servidor, NULL) > (global, NULL)**. `mount NULL` = "todas as partições"
  (comportamento da S2, intacto). Regra de partição **desligada silencia só aquela partição**.
- **UI (subseção "Partições reportadas" em Alertas, quando um servidor está selecionado):**
  descobre os mounts do `last_sample` do servidor e, por mount, mostra a regra efetiva (padrão/
  sobrescrita) + **Alertar/Silenciar** + **Limiar** (edita) + **voltar ao padrão**. Default: cada
  partição segue o padrão global de disco (85/95%); o dono refina por clique.
- **Por que no painel:** "parar de vigiar /srv" ou "vigiar /boot a 50%" é um clique — **não**
  exige reinstalar o coletor. O coletor manda todas as partições reais; o painel decide o que
  vira alerta. Reusa a sobrescrita por servidor da S2 (agora com granularidade de mount).

---

## Validação viva no `laravel-dev` (ao vivo, com o agente REAL)

Estado antes: `srv-smoke-dev` com incidente **watchdog critical firing** (mudo desde a S2),
flag `.env` **ON**, **0 destinatários habilitados** (desabilitei o de smoke na S3 → mesmo com
flag ON, o job não tem para quem enviar: **zero WhatsApp**, seguro). Canal `fabio-pessoal` presente.

Como validei sem instalar daemon persistente: emiti um token fresco para `srv-smoke-dev` e rodei
o **agente bash real** (one-shot, read-only) contra o app real (via `php artisan serve` temporário
→ MySQL/Redis reais), 6 amostras ao longo de ~90s. Não deixei timer systemd na máquina do app
(evita daemon root + POSTs perpétuos); o one-liner de instalação está validado por testes
(composição da rota, `sh -n`, filtro de disco, payload aceito) e pronto para o dono rodar.

**Resultados (ponta a ponta):**
1. **Coletor reporta → ingestão → buffer → `last_seen_at`**: buffer encheu com `/` (88%) e
   `/srv` (5%); `last_seen_at` atualizou.
2. **Watchdog RESOLVE (prova viva do resolve):** o incidente #1 (watchdog critical firing) passou
   a **`resolved`** (`resolved_at` setado) assim que o coletor começou a reportar.
3. **Disco por partição:** incidente #2 = **`disk [/] warning firing, valor=88`** — identifica a
   partição **`/`** especificamente (não "disco geral"); **`/srv` (5%) não gerou incidente**.
   Primeira detecção real de disco por partição.
4. **Estado do flag no teste:** `SERVERS_NOTIFICATIONS_ENABLED` no `.env` permaneceu **ON**
   (não alterei). O `evaluate` do proof 1 rodou com override one-shot OFF; o do proof 2 rodou com
   o **flag real ON** — em ambos, **0 destinatários ⇒ nenhum WhatsApp enviado**. Nada saiu.

Observação: como não há agente persistente, o `srv-smoke-dev` voltará a ficar mudo e o scheduler
(ativo) reabrirá o watchdog em avaliações futuras — comportamento correto (reflete "sem coletor").
O token do `srv-smoke-dev` foi regenerado para o teste (o anterior, sem agente instalado, ficou
void). O incidente de disco de `/` a 88% é uma condição **real** do host.

---

## Arquivos de teste (o que cada um cobre)

- **`ServersColetorTest`** (6): filtro de disco do `df` do dono → só `/` e `/srv` (via o **bash
  real**), nenhum overlay/tmpfs vazou; payload do agente aceito pela ingestão S1 (200) e cai no
  buffer com os dois mounts; token ausente → 401; rota do instalador compõe o agente sem
  placeholder e com heredoc íntegro; rota do coletor serve o agente cru.
- **`ServersParticoesTest`** (11): default global vale para todas as partições; **partição
  desligada silencia só ela** (o `/` alerta, `/srv` não); limiar por partição sobrescreve o
  global (`/boot` a 50%); partição do servidor vence a global; precedência de `diskRuleFor`
  (partição > servidor > global); UI lista as partições reportadas; **silenciar pela UI** cria
  sobrescrita desligada e só ela para de alertar; operador é barrado (403).

---

## Ajustes deliberados (um a um)
1. **`phpunit.xml` pina o flag** `SERVERS_NOTIFICATIONS_ENABLED=false` — teste não pode depender
   do `.env` (o dono ligou o flag em produção-DEV); restaura o baseline. Padrão das chaves ASAAS.
2. **Coluna `mount` em `server_alert_rules`** (aditiva) — a S2 não tinha granularidade por
   partição; sem isso a seleção por partição seria impossível. `mount NULL` preserva a S2.
3. **Instalador serve o agente embutido** (um fetch, um arquivo versionado por baixo) em vez de
   dois downloads — mais simples para o usuário; ambos os scripts versionados em `deploy/agent/`.
4. **Timer roda como `nobody`** (não root) — privilégio mínimo; a coleta não exige root.
5. **Validação viva por one-shot do agente real** (não pelo timer systemd persistente) — evita
   deixar daemon root + POSTs perpétuos no host do app; a instalação persistente fica a cargo do
   dono (one-liner validado).
6. **`efetiva('disk')` na tela filtra `mount NULL`** — a lista principal mostra a regra "de todas
   as partições"; as sobrescritas por partição vivem na subseção Partições.
7. **Flag não alterado no `.env`** — proof 2 rodou com o flag real ON, seguro pelos 0
   destinatários (nenhum envio). Documentado, não mexido.

## Confirmações finais
- **Produção/Nextgest/nginx/Tunnel intocados.** Pipeline/matching/FlowEngine/Kanban/billing:
  **zero diff**. **Ingestão da S1 e transporte/sender: intocados** (o coletor é cliente do
  endpoint; a UI de partições reusa a regra da S2). Arquivos existentes tocados: `routes/web.php`
  (2 rotas GET públicas do instalador/coletor), `ServerEvaluator` (disco por-mount), `AlertRule`
  (fillable), `Alertas`/`Inventario` (UI), `phpunit.xml`.
- Migration aditiva (`mount`) aplicada e confirmada por leitura (`db:table`).
- **`queue:restart`** executado (2026-07-07 12:17:59) — os daemons agora estão ativos; o sinal
  recicla os workers.
- Suíte inteira **sequencial**: 1040 → 1053 verdes (4070 → 4114 assertions), zero falha.
- Commits `8001951`, `cd0cca2`, `5dd3142` — **sem push** (remote `msgautomation_server`; o dono empurra).
