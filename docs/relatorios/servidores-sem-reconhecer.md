# Servidores — remoção do "reconhecer" (ack): ciclo firing → resolved — 2026-07-07

Git no início: HEAD `2257535`, branch `master`, remote `msgautomation_server`. **`APP_ENV=local`**
(DEV `192.168.11.210`, painel `:9100`). **Sem push.** Produção `187.127.24.165` / Nextgest /
nginx / Cloudflare Tunnel / 2FA: **NÃO tocados**.

Testes: **1083 → 1079 verdes** (4223 → 4207 assertions). A queda de 4 é a **remoção dos testes
específicos de ack** (reconhecer/reativar), substituídos por testes do novo comportamento; zero
regressão nos caminhos preservados. **Commit: `038782a`.** `queue:restart`: 2026-07-07 16:06:30
(tocou o job de alerta, o IncidentManager e o command). Worker/scheduler ativos.

═══════════════════════════════════════
## ⚠️ ATENÇÃO (topo) — alertas abertos voltam a notificar
═══════════════════════════════════════
A migração de dados flipou os incidentes que estavam **`acknowledged`** para **`firing`** (abertos).
No DEV isso são **2 incidentes de disco abertos** e há **1 destinatário habilitado** + o flag
`SERVERS_NOTIFICATIONS_ENABLED` **ON**. Como não há mais ack para silenciar, **no próximo tick do
`servers:evaluate` (a cada minuto) esses alertas voltam a disparar no WhatsApp** e re-avisam pela
cadência da regra de disco (hoje `repeat=60s`) até o disco normalizar. **É o comportamento
desejado** — mas fica o aviso de que, ao subir, o WhatsApp da infra recebe os avisos de imediato.
Se quiser parar um deles, o caminho agora é **resolver a causa** (o disco baixar) — não há botão
de silenciar (por decisão desta fatia).

═══════════════════════════════════════
## O que foi removido (ack, em todos os pontos)
═══════════════════════════════════════
- **Máquina de estado (`Incident`):** removida a constante `STATUS_ACKNOWLEDGED`. O ciclo agora é
  só **`firing → resolved`**. As colunas `acknowledged_at`/`acknowledged_by` **permanecem no
  schema** (migração não-destrutiva) mas o app não as escreve mais — removidas do `$fillable`/casts.
- **`IncidentManager`:** removidos `acknowledge()` e `reactivate()`. A **escalada** warning→critical
  agora só atualiza o nível + valor e avisa a mudança (não mexe mais em status/ack — não há o que
  "furar").
- **Loop de envio (`SendServerAlert`):** o gating do re-aviso passou a depender **apenas** de
  (a) incidente **aberto** (`resolved_at IS NULL`) e (b) cadência `repeat_s` do nível vencida.
  `pendingReminders` e `hasPending` **não consultam mais ack** (antes filtravam `status=firing`
  para excluir acknowledged; agora usam `resolved_at NULL`, que é "aberto").
- **Tela Incidentes:** removidos os botões **"Reconhecer"** e **"Reativar avisos"** e os métodos
  `ack()`/`reactivate()` do componente (agora **somente leitura**). Removidos o badge/label
  `acknowledged` e a exibição "reconhecido …". O incidente aberto mostra **"aberto · re-avisando
  até normalizar"**. Info-tip reescrito (estava desatualizado, falava em "modo silencioso" da S2).
- **Comentários/badge** da sidebar e da rota atualizados (não citam mais `acknowledged`).

## Como ficou o ciclo (definitivo)
`firing` (problema detectado pela histerese/watchdog → avisa) → **re-avisa a cada `repeat_s`**
enquanto persiste (sempre, sem depender de nada além de aberto + cadência) → `resolved` (normaliza
→ avisa 1 vez e para). Volta a dar problema → novo `firing`. Escalada warning→critical avisa a
mudança no mesmo incidente. Watchdog mantém precedência (servidor stale → não avalia métrica com
dado velho). "Avisar 1 vez" (repeat NULL) continua opção: avisa no início e no resolve, sem repetir.

## Migração de dados
`2026_07_07_000008_drop_acknowledge_from_incidents.php` (compatível, não-destrutiva de schema):
`UPDATE server_incidents SET status='firing', acknowledged_at=NULL, acknowledged_by=NULL WHERE
status='acknowledged'`. Confirmado no DEV: **0 `acknowledged` restantes**, 2 abertos (`firing`).
As colunas `acknowledged_*` seguem existindo (limpeza de schema pode ser feita depois com seu aval).

## Testes
- **Removidos** (ack não existe mais): `test_ack_marca_reconhecido`, `test_ack_forjado`,
  `test_reconhecido_nao_reabre_e_resolve` (IncidentesUi); `test_reconhecido_nao_reavisa_e_reativar`,
  `test_reactivate_pela_tela_owner_only` (Reaviso); `test_reconhecido_nao_renotifica_mesmo_critical`
  (Canal).
- **Novos/reescritos:**
  - `test_tela_nao_tem_reconhecer_nem_reativar`: a tela não mostra os botões e o componente não
    tem mais os métodos `ack`/`reactivate` (`method_exists` = false).
  - `test_incidente_resolve_pela_normalizacao`: incidente aberto → resolve ao normalizar (sem ack).
  - `test_a5_escalada_para_critical_notifica_no_mesmo_incidente`: warning→critical avisa a mudança,
    mesmo incidente (não abre segundo), sem ack.
  - Mantidos e verdes: **re-aviso por cadência re-envia** enquanto aberto (`repeat_s=60`),
    `avisar 1 vez` não repete, resolve notifica 1 vez, disco por partição, watchdog + precedência,
    histerese/debounce, mensagens editáveis + `{ip}`/`{grupo}`, transporte direto, isolamento por
    conta. **123 testes de servidores verdes.**
- `grep` confirma **nenhum resquício** de `acknowledge`/`reactivate`/`STATUS_ACKNOWLEDGED` em
  `app/` ou `tests/`.

## Preservado (sem regressão)
matching/FlowEngine/Sender de Campanha/billing/recebimento **sem diff**; transporte direto
(`ProviderRegistry->sendText`) intacto; conversa "Alertas de Infraestrutura" no Atendimento
continua (registro dos avisos, sem ack); disco-por-partição, watchdog, histerese em segundos,
debounce de resolve, mensagens/{ip}/{grupo} intactos.

## Confirmação
- Produção/Nextgest/nginx/Tunnel/2FA **intocados**; 100% no DEV.
- Migração aditiva/compatível aplicada e confirmada por leitura.
- `queue:restart` executado (16:06:30). Commit `038782a`, **sem push**.
