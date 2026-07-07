# Correção — recebimento de mensagens + re-aviso e mensagens de alerta — 2026-07-07

Git no início: HEAD `e65ab8b`, branch `master`, remote `msgautomation_server`. **`APP_ENV=local`**
(DEV `192.168.11.210`, painel `:9100`). **Sem push.** Produção `187.127.24.165` /
`painel.nextgest.com.br` / Nextgest / nginx / Cloudflare Tunnel / 2FA: **NÃO tocados**.

Testes: **1077 → 1083 verdes** (4201 → 4223 assertions; +6, zero regressão).
Commits: **P1** sem código (fix runtime + `.env`); **P2** `cf92ccb`; **P3** `848c58d`.
`queue:restart`: 2026-07-07 15:30:49 (P2/P3 tocam o job de alerta e o IncidentManager).

═══════════════════════════════════════
## ⚠️ DEPENDE DO DONO / ATENÇÃO (topo)
═══════════════════════════════════════
1. **P1 — a correção do webhook assume que o painel fica na `:9100`.** O webhook da instância
   Evolution foi reapontado para `http://host.docker.internal:9100/...`. Se o painel mudar de
   porta de novo (ou for para trás de nginx/apache em porta fixa/HTTPS), **o webhook do Evolution
   precisa ser reapontado junto** — senão o recebimento quebra outra vez pelo mesmo motivo. Deixei
   o `EVOLUTION_WEBHOOK_URL` no `.env` alinhado com a `:9100` para re-provisionamentos futuros.
2. **P2 — não há bug: os alertas de disco não repetiam porque foram RECONHECIDOS.** Os dois
   incidentes de disco reais (#8 critical, #10 warning) foram reconhecidos por você às 14:58, e
   ack silencia re-aviso (comportamento pedido para manter). Além disso, o repeat=1min foi salvo
   às 15:02 (depois do ack). **Para retomar os re-avisos deles, clique "Reativar avisos"** na tela
   Incidentes (botão novo). Não reativei ao vivo de propósito — reativar dispara WhatsApp real
   imediatamente (flag ON + contatos reais), então deixei a decisão com você.
3. **`.env` mudado** (`EVOLUTION_WEBHOOK_URL` → `:9100`) — `.env` não vai pro git; registro aqui.

═══════════════════════════════════════
## PROBLEMA 1 — recebimento quebrado desde 02/07 (RESOLVIDO)
═══════════════════════════════════════
**Elo quebrado: o webhook da instância Evolution apontava para uma porta morta.**

Investigação (cada elo):
- **Evolution (container):** o app usa `evolution_msg` em `127.0.0.1:8090`, instância
  `fabio-pessoal` (`state: open`). O webhook configurado **na instância** estava:
  `http://host.docker.internal:8080/webhook/evolution/{token}` — `updatedAt: 2026-07-02` (bate
  com o "parou em 02/07"). Mas **nada escuta na `:8080`** — o painel migrou para `:9100`. De
  dentro do container: `host.docker.internal:9100` → alcançável; `:8080` → falha. Ou seja, a
  Evolution vinha fazendo POST de todo `MESSAGES_UPSERT` para uma porta morta desde 02/07 →
  100% das mensagens recebidas perdidas.
- **Endpoint do app:** a rota `POST /webhook/evolution/{token}` está ativa e correta (o token na
  URL bate com `channels.webhook_token`; `VerifyWebhookSecret` resolve o canal). Não era o problema.
- **Persistência:** o handler grava normal — provado abaixo.
- **Tempo real:** `BROADCAST_CONNECTION=log` (sem Reverb); a tela `conversas` usa `wire:poll.5s`
  → atualiza sozinha em ~5s por polling, **não depende de websocket**. Não era o problema.

**Correção (runtime, via API do Evolution):** reapontei o webhook da instância de
`:8080` → **`http://host.docker.internal:9100/webhook/evolution/{token}`** (mesmo token, mesmo
evento `MESSAGES_UPSERT`). Confirmado por `GET /webhook/find/fabio-pessoal`. Alinhei também o
`EVOLUTION_WEBHOOK_URL` do `.env` para `:9100`.

**Prova ponta-a-ponta:** POST de **dentro do container** (`host.docker.internal:9100`, envelope
`MESSAGES_UPSERT` real) → app respondeu `{"status":"queued"}` → o worker daemon
(`msgautomation-worker`, ativo) processou `ProcessIncomingWhatsappMessage` → **`IncomingMessage`
id=3374 gravado** (jid/texto corretos). A tela `conversas` (poll 5s) exibiria em ~5s. A linha de
teste (id=3374) foi **removida** depois (artefato do teste numa conversa de cliente real).

Sem mudança de código — o código estava correto; a configuração viva da instância Evolution
estava errada (porta antiga).

═══════════════════════════════════════
## PROBLEMA 2 — re-aviso de métrica (RESOLVIDO — não era bug de mecanismo)
═══════════════════════════════════════
**Causa real:** os incidentes de disco estavam **`acknowledged`** (reconhecidos por você às
14:58); ack silencia re-aviso por design. E o `repeat=1min` foi salvo na regra às **15:02**
(depois do ack). O que parecia "watchdog reavisa e métrica não" era ilusão: os "Sem reportar
warning" repetidos eram **incidentes NOVOS** (o Laravel Dev oscila stale↔normal e abre um
watchdog a cada vez — `srv-alert:6:firing→resolved`, `srv-alert:7:firing→resolved`), não
re-avisos do mesmo incidente. O disco #8 tinha só `srv-alert:8:firing` e **nenhum `reaviso`**.

**O mecanismo de re-aviso de métrica funciona** — provado ao vivo: criei um incidente de disco
**não-reconhecido**, `critical_repeat_s=60`, último aviso há 120s → `hasPending`=SIM → o job
`SendServerAlert` **re-avisou** (`notify_count` 1→2). E o valor da cadência **salva** (a regra
#4 estava com `warn_repeat=60, crit_repeat=60`). Coberto por teste
(`test_disco_nao_reconhecido_reavisa_no_intervalo`).

**Correção entregue (para você retomar os re-avisos sem quebrar "ack silencia"):**
`IncidentManager::reactivate()` + botão **"Reativar avisos"** na tela Incidentes (owner-only):
des-reconhece o incidente (`acknowledged → firing`, limpa `acknowledged_*`) sem re-abrir → os
re-avisos por cadência voltam a disparar. O incidente reconhecido agora mostra **"re-avisos
pausados"** para a relação ack↔re-aviso ficar clara. Mantido: "avisar 1 vez" não repete; ack
silencia o mesmo nível; escalada fura o ack.

Precedência do watchdog: um incidente de disco aberto **não-reconhecido** re-avisa mesmo com o
servidor stale (o `pendingReminders` olha o estado do incidente, não re-avalia dado velho) — não
é "engolido" por nada.

═══════════════════════════════════════
## PROBLEMA 3 — mensagens: padrão editável + rotação + {ip}/{grupo} (FEITO)
═══════════════════════════════════════
- **Mensagem padrão editável e VISÍVEL:** a tela Alertas agora **pré-preenche** a 1ª mensagem de
  cada nível (warning/critical) e a de resolução com o **template padrão real** (com as variáveis),
  num campo editável — nada de "sem mensagem própria". O dono vê e edita o texto que sai.
- **Rotação clara:** a 1ª mensagem é a **"padrão"** (rótulo na UI, vai no disparo); "Adicionar
  mensagem (rotação)" acrescenta as próximas — a cada re-aviso avança, **repete a última** ao
  acabar. A 1ª não tem botão de remover (é a padrão).
- **Variáveis** (documentadas na própria tela, lista completa): **`{servidor}`** (nome),
  **`{ip}`** (campo Host/IP do servidor — confirmado: ex. `10.40.132.19`), **`{grupo}`** (grupo,
  ex. `YELLL`), **`{metrica}`**, **`{valor}`**, **`{nivel}`**, **`{particao}`** (só disco).
  Substituídas no envio **e** no registro da conversa "Alertas de Infraestrutura". `{grupo}` e
  `{ip}` já existem no model do servidor (`grupo` e `host`) — **sem migration**.
- **Resolução** editável (1 vez) — mantida, também pré-preenchida com o template padrão.
- Exemplo que passou a funcionar (teste): `🔴 {servidor} ({ip}, grupo {grupo}): {metrica}
  ({particao}) em {valor}` → `🔴 DEV-YELLL (10.40.132.19, grupo YELLL): Disco (/) em 97%`.

═══════════════════════════════════════
## Testes
═══════════════════════════════════════
`ServersReavisoEVariaveisTest` (6, novos): disco não-reconhecido re-avisa no intervalo; ack não
re-avisa e **reativar retoma**; reactivate na tela é owner-only (operador 403); `{ip}`/`{grupo}`
substituídos no texto enviado; UI pré-preenche a mensagem padrão editável (com `{ip}`/`{particao}`)
e salva a edição; variáveis documentadas incluem `{ip}`/`{grupo}`. Os testes de servidores
existentes (canal, cadência, avaliação, incidentes, alertas) seguem verdes.

## Confirmação
- Produção/Nextgest/nginx/Tunnel/2FA **intocados**; trabalho 100% no DEV.
- matching/FlowEngine/Sender de Campanha/billing: **sem diff**. Transporte do alerta inalterado
  (continua `ProviderRegistry->sendText` direto).
- P1: fix runtime (webhook Evolution) + `.env`. P2: `cf92ccb`. P3: `848c58d`. Sem push.
- `queue:restart` executado (15:30:49); worker e scheduler ativos.
