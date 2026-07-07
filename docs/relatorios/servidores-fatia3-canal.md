# Servidores — Fatia 3 (S3): auditoria pré-canal + canal WhatsApp atrás do flag — 2026-07-07

Git no início: HEAD `0e2dc7b` (relatório S2), branch `master`, working tree limpo.
**`APP_ENV=local` confirmado** (instância DEV `192.168.11.210`). Produção `187.127.24.165` /
Nextgest / nginx / Cloudflare Tunnel: **não tocados**.

**Baseline: 1012 verdes / 3987 assertions.** **Final: 1040 verdes / 4070 assertions**
(+28: 14 auditoria + 11 canal + 3 UI destinatários). Zero regressão.

**Commits (atômicos, A separado de B):**
- Parte A (auditoria): **`df4f2cc`**
- Parte B (canal): **`369950b`**

Ordem cumprida: A (auditoria + correções) → B (canal) → C (operacional/relatório). Nenhum
código de envio foi escrito antes de fechar a Parte A.

---

## PARTE A — AUDITORIA (estado real + correção, item a item)

### A1 — Isolamento multitenant · **já correto; faltava prova**
Estado real: `servers`, `server_alert_rules`, `server_incidents` (e agora
`server_alert_contacts`) usam `BelongsToAccount` + `account_id`. Regras "globais" são
`server_id = NULL` **com `account_id` próprio** — o seed itera as contas e
`ServerEvaluator::rulesFor()` filtra `where('account_id', $server->account_id)`. **Não há
compartilhamento entre tenants.** Correção: só testes. Cobertos:
- `test_a1_avaliar_servidor_de_a_nunca_toca_incidente_de_b` — avaliar A não cria incidente em B.
- `test_a1_regra_global_e_por_tenant_editar_a_nao_muda_avaliacao_de_b` — cada empresa tem
  suas 6 globais; A afrouxar CPU→50 dispara só em A (B segue no 95).
- `test_a1_tela_incidentes_de_a_nao_ve_incidente_de_b` — a tela escopada não vê o outro tenant.
- (+ `test_isolamento_nao_envia_para_contato_de_outra_empresa` na Parte B.)

### A2 — Watchdog pela hora de RECEBIMENTO · **já correto; faltava prova**
Estado real: `ServerIngestController` grava `last_seen_at = now()` e `received_at = now()`
(server-side, linha 107/88 da S1). O `ts` do agente é guardado na amostra mas **nunca decide
nada**. Correção: só testes.
- `test_a2_timestamp_mentiroso_no_payload_nao_move_last_seen` — POST com `ts` de um ano atrás
  grava `last_seen_at ≈ agora` (hora de recebimento).
- `test_a2_watchdog_le_recebimento_nao_o_ts_do_agente` — servidor mudo há 400s dispara
  watchdog mesmo com amostra "fresca" (ts) no buffer.

### A3 — For-duration em SEGUNDOS · **corrigido**
Estado real: `holds()` exigia `count >= MIN_SAMPLES (3)` **fixo** + span — a contagem fixa
acoplava a latência à cadência. Correção: **removido o `MIN_SAMPLES` fixo**; a histerese
agora é "condição verdadeira **continuamente por N segundos**", medida por:
- streak da amostra mais recente para trás enquanto viola **e** sem buraco maior que
  `MAX_GAP = cadence_s × 2.5` (config `servers.cadence_s`, default 30 → gap máx. 75s): **uma
  amostra perdida é tolerada; duas seguidas quebram** a prova de continuidade (conservador);
- span da sequência `≥ for_duration`, com `≥ 2` pontos (um span exige dois instantes).

A latência do alerta passa a seguir o `for_duration`, **independente da cadência**. Correção
correlata (bug achado na auditoria): a UI aceitava `for_s` até 86400 mas o buffer só cobre
~30 min → regra nunca dispararia; **cap em `config('servers.max_for_duration_s')` (600s)**,
coerente com A6. Testes: `test_a3_uma_amostra_perdida_e_tolerada`,
`test_a3_duas_amostras_seguidas_perdidas_quebram_a_continuidade`,
`test_a3_latencia_segue_for_duration_independente_da_cadencia` (cadência 15s),
`test_a3_pico_dentro_da_janela_nao_dispara`. Os 37 testes S2 seguem verdes com a nova lógica.

### A4 — Histerese simétrica no resolve (anti-flapping) · **corrigido**
Estado real: já não resolvia na primeira amostra boa (exigia limpo por
`max(warning_for_s, 60)`), mas o debounce de descida não era configurável. Correção: coluna
**`resolve_for_s`** (nullable) em `server_alert_rules` — NULL = default seguro
(`max(warning_for_s, 60s)`); valor explícito é honrado. Campo na tela Alertas. O evaluator
usa-o na resolução. Testes: `test_a4_nao_resolve_na_primeira_amostra_boa` (limpo por 60s <
120s não resolve; 150s ≥ 120s resolve), `test_a4_resolve_for_s_e_configuravel` (30s honrado).
Subida (`warning_for_s`/`critical_for_s`) e descida (`resolve_for_s`) são independentes.

### A5 — Ack não engole escalada · **corrigido**
Estado real: `fire()` escalava warning→critical emitindo `escalated`, mas o incidente ficava
`acknowledged`. Correção: a escalada **fura o ack** — volta o status a `firing` e limpa
`acknowledged_at`/`acknowledged_by` (o dono reconheceu o **warning**, não o critical). O
critical então notifica e volta ao ciclo de re-notificação. Teste:
`test_a5_ack_em_warning_e_escalada_para_critical_notifica` (após ack, escala → firing,
ack limpo, evento `escalated` gerado). Na Parte B, `test_reconhecido_nao_renotifica_mesmo_critical`
prova o outro lado: ack de um critical silencia a repetição.

### A6 — Poda do buffer · **já existe; alinhado**
Estado real: `MetricsBuffer` já tem teto duplo (60 amostras + TTL 1h, testado na S1).
Correção: adicionado `MetricsBuffer::coverageSeconds()` (= `MAX_SAMPLES × cadence_s`) e o cap
de `for_s` em `max_for_duration_s` garante que **a janela em uso sempre cabe na cobertura +
margem** (a 30s: 1800s ≥ 600s; a 15s: 900s ≥ 600s). Testes:
`test_a6_retencao_do_buffer_cobre_o_maior_for_duration_permitido`,
`test_a6_buffer_tem_teto_de_amostras_e_ttl`. Continua **buffer de avaliação, não histórico**.

---

## PARTE B — CANAL WHATSAPP + ROTEAMENTO

### Modelo de roteamento (`server_alert_contacts`)
Escopo por conta. Campos: `name`, `phone`, `email` (nullable — fallback), `min_level`
(`warning` recebe warning+critical; `critical` só critical), **alvo**: `server_id` específico
**>** `grupo` **>** ambos NULL (todos os servidores da conta). Múltiplos destinatários =
múltiplas linhas. `AlertContact::matches($level, $server)` decide severidade + alvo. CRUD na
seção **Destinatários** da tela Alertas (owner-only; telefone normalizado para dígitos;
servidor específico zera o grupo).

### Transporte (decisão travada) — direto, sem freios de marketing
`ProviderRegistry->for($channel)->sendText($channel, $phone, $texto)` — o **mesmo transporte
cru** que as Campanhas usam por baixo, **sem** o `Sender` (que embute anti-ban/opt-out/janela
24h e poderia segurar um alerta crítico). Canal de saída: `Channel::defaultFor($accountId)`
(o mesmo das proativas). O transporte **não foi alterado** — consumido como cliente.

### Envio sempre em fila, atrás do flag
`config('servers.notifications_enabled')` (OFF por default; não está no `.env`):
- **OFF**: `AlertNotifier` mantém o modo silencioso da S2 (SystemEvent "Teria notificado" +
  marcas) — comportamento byte-idêntico, testado.
- **ON**: a transição **não envia nem loga** no notifier; o `servers:evaluate`, **ao fim do
  tick**, despacha `SendServerAlert` por conta com pendência (fila `config('servers.alert_queue')`).

**Decisão de arquitetura registrada (adaptação consciente):** em vez de despachar um job por
transição, o command despacha **um job por conta ao fim do tick**; o "pendente" é o estado
persistido do incidente (`notified_level != level`; `notified_resolved_at NULL`; critical
vencido de cooldown). Por quê: (1) o envio nunca ocorre no request nem dentro do evaluate
(só o *dispatch* acontece lá; o *envio* é no job); (2) **agrupamento natural** — um rack que
cai abre N incidentes no mesmo tick e o job vê todos juntos → uma mensagem; (3) cobre os
lembretes de critical no mesmo mecanismo; (4) sem corridas de lock nem tempestade de N jobs;
(5) testável de ponta a ponta sem depender de timing de fila. As **transições** continuam
sendo o gatilho (definem o que está pendente); só o ponto de enfileiramento mudou. Notificar
só nas transições (abertura/escalada/resolução) segue garantido — o job só envia o que mudou.

### Controle de tempestade (B3)
- **Agrupamento**: o job coalesce por conta e monta **uma mensagem por destinatário** por
  balde (abertura/reincidência/resolução), listando os incidentes que casam a rota daquele
  contato.
- **Resumo acima de `storm_cap` (10)**: "N servidores afetados (M críticos)" em vez de listar.
- **Cap de rajada por janela** (`burst_cap`/`burst_window_s`, default 20/300s por conta):
  estourou → suprime novos envios e registra `SystemEvent warning` (o resumo já saiu).
- **Cooldown / re-notificação**: **só critical não-reconhecido** repete, a cada `cooldown_s`
  (coluna da regra); **warning nunca repete**; **ack silencia** a repetição (mas escalada
  fura o ack — A5). Marcas `notified_level` (unifica firing/escalada) e `last_notified_at`
  (base do cooldown).

### Falha de entrega observável (B4)
`SendServerAlert`: `$tries = 5`, `$backoff = [30,60,120,300]`. Falha de transporte
(`WhatsappSendException`, Evolution fora, sem canal) **relança** → retry da fila. Esgotado,
`failed()`: registra **`SystemEvent level=error`** "Falha ao notificar alerta por WhatsApp:
{motivo}" (observável nos Logs) **e** dispara **fallback por e-mail** (`ServersAlertFallback`
para os e-mails dos contatos + `config('servers.fallback_email')`). Alerta que falha **nunca
some calado**.

### Testes espelho (B5) — `ServersCanalTest` (11)
`Http::assertSent` confirmando **destinatário e payload** (endpoint `sendText`, número, texto
com nome do servidor + métrica) — o inverso do `assertNothingSent` da S2. Cobre: envio na
transição + **idempotência** (rodar de novo não re-envia); **roteamento por severidade**
(warning não vai para contato critical-only); **agrupamento de tempestade** (3 servidores
mudos → **1 mensagem** com os 3); **resolução também notifica**; **re-notificação só de
critical** após cooldown; **warning nunca repete**; **ack silencia** critical; **falha
relança** para retry; **`failed()` → SystemEvent error + fallback e-mail** (`Mail::assertSent`);
**isolamento** (contato de outra empresa não recebe); **flag OFF → nada enviado** e o
silencioso da S2 permanece.

---

## PARTE C — OPERACIONAL

### Dois daemons obrigatórios para rodar ao vivo (herdado S1/S2: units inativos)
Enquanto os units systemd estiverem **inativos**, **nenhum alerta dispara de fato** — o
`schedule:work` não roda o `servers:evaluate`, e os jobs de envio empilhariam sem worker. O
caminho `everyMinute` está validado **só por teste** (os testes chamam o command direto),
não por daemon. Para o ao vivo, o dono precisa ativar **ambos** no DEV:

```bash
# scheduler (dispara servers:evaluate a cada tick)
sudo systemctl enable --now msgautomation-scheduler
# worker (processa a fila — inclusive os jobs SendServerAlert)
sudo systemctl enable --now msgautomation-worker
# conferir
systemctl status msgautomation-scheduler msgautomation-worker --no-pager
```

`queue:restart` executado nesta fatia (2026-07-07 11:42:58) — o command e o job novo são
carregados por scheduler/worker; sem daemon ativo, o sinal fica no cache (como S1/S2).

**Ligar o canal de verdade** (só quando o dono decidir, após calibrar): `SERVERS_NOTIFICATIONS_ENABLED=true`
no `.env` + `php artisan config:clear`. Enquanto ausente, o default é OFF (modo silencioso).

### Latência de detecção — recomendação (NÃO implementada, aguardando ok)
Pior caso hoje: cadência (30s) + `for_duration` do nível + tick do scheduler (60s). Para CPU
critical (for 120s) ≈ **~3,5 min**; watchdog critical (300s) ≈ ~6 min. **Recomendação:
`servers:evaluate` a cada 30s** (`everyThirtySeconds()` — o `schedule:work` suporta sub-minuto
e o command é barato: withoutOverlapping + lock já protegem). Corta ~30s do pior caso sem
complexidade nova. **Não** recomendo avaliar critical no ingest (quebraria o "ingestão grava
e responde" da S1 — a porta pública tem que ser leve). Trago a recomendação; **não alterei o
agendamento** (segue `everyMinute`) até seu ok.

---

## Ajustes deliberados (um a um)
1. **Dispatch por conta ao fim do tick** (não por transição individual) — justificado acima
   (agrupamento/testabilidade/sem corridas); o envio segue 100% na fila, nas transições.
2. **`notified_level` + `last_notified_at`** (novas colunas) unificam firing/escalada e dão
   base ao cooldown; `notified_firing_at`/`notified_resolved_at` seguem para a trilha
   silenciosa S2.
3. **Escalada des-reconhece** (A5) — o ack era do warning; o critical precisa furar.
4. **`resolve_for_s` NULL = default** (`max(warning_for_s, 60)`); explícito honrado.
5. **Cap de `for_s` em 600s** (A3/A6) — acima disso o buffer não cobriria e a regra nunca
   dispararia; a UI valida e explica.
6. **Fallback via Mailable** `ServersAlertFallback` (não `Mail::raw`) — testável com
   `Mail::assertSent`. `MAIL_MAILER=log` no DEV: o fallback "envia" para o log até o SMTP
   ser configurado.
7. **Marcação após a rodada inteira** de envio; exceção relança antes de marcar (retry
   re-envia). Trade-off aceito: numa falha parcial, o retry pode duplicar para quem já
   recebeu — duplicar alerta de infra é melhor que perder; registrado.
8. **Sem canal conectado** = falha observável (throw → retry → failed → e-mail+log), não
   silêncio.
9. **Contato de smoke desabilitado** (não removido): criei um destinatário de teste
   (`5511900000000`) para validar o wiring ao vivo (flag ON resolve canal `fabio-pessoal` e
   `hasPending`); **não disparei envio real** (mandaria WhatsApp de verdade a um número fake
   pela instância conectada — as 11 provas de envio usam `Http::fake`). Deixei-o
   `enabled=false` (via UPDATE com WHERE) para um flip do flag não enviar a um número
   inválido; o dono pode removê-lo na tela Alertas. O incidente watchdog de `srv-smoke-dev`
   (S2) segue aberto — resolve com nova ingestão ou por ack.
10. **Pint** aplicado; **assets rebuildados** (classes novas das telas).

## Confirmações finais
- **Produção/Nextgest/nginx/Tunnel intocados**; trabalho 100% no DEV.
- **Pipeline/matching/FlowEngine/Kanban/billing: zero diff.** O **transporte de WhatsApp não
  foi alterado** — `ProviderRegistry->sendText` é consumido como cliente. Arquivos existentes
  tocados na S3: `routes/console.php` (mantido everyMinute), `AlertNotifier`, `Incident`
  (fillable/casts), `ServersEvaluate` (dispatch), `Alertas` (destinatários), `config/servers.php`.
- **100% mudo por default**: flag OFF; nenhum WhatsApp sai sem `SERVERS_NOTIFICATIONS_ENABLED=true`.
- Migrations aditivas (`resolve_for_s`, `server_alert_contacts` + `notified_level`/
  `last_notified_at`) aplicadas e confirmadas por leitura (`db:table`).
- `queue:restart` executado (11:42:58); units inativos → sinal fica no cache (Parte C).
- Suíte inteira **sequencial**: 1012 → 1040 verdes (3987 → 4070 assertions), zero falha.
- Commits `df4f2cc` (A) e `369950b` (B), **sem push** (repo da instância DEV; Fabio empurra).

## Pergunta em aberto para o dono
- **Latência**: aprovo mudar `servers:evaluate` para `everyThirtySeconds()` (Parte C)? Corta
  ~30s do pior caso; sem custo relevante. Aguardo o ok antes de alterar o agendamento.
