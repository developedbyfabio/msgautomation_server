# Fatia 26 — Billing Asaas: assinatura, checkout hospedado, webhook e corte de trial — 2026-07-06

Git no início: HEAD `7ecc47b` (fatia 25), working tree limpo exceto os relatórios untracked
de STOPs anteriores. Baseline: **918 verdes / 3672 assertions**.

---

## PARTE 0 — pré-requisito (cumprido)

- **`php artisan config:clear` rodado ANTES da verificação** (evita STOP falso por cache).
- As três variáveis verificadas **sem imprimir valores** (presença/comprimento):
  `ASAAS_API_KEY` (166 chars), `ASAAS_BASE_URL` (29 chars), `ASAAS_WEBHOOK_TOKEN` (49 chars).
- `ASAAS_BASE_URL` confirmada apontando para **`api-sandbox.asaas.com`** (sandbox, não produção).
- **Nenhuma chave aparece em código/commit/log** — tudo via `config/billing.php` ← `.env`.
  A suíte de testes usa chaves FAKE fixadas no `phpunit.xml` (teste nunca enxerga a real) e
  `Http::fake` (nenhum byte de teste sai pra rede).

## Doc oficial do Asaas usada (consultada durante a execução — docs.asaas.com, API v3)

- **Autenticação da API:** header **`access_token`** (o Asaas não usa Bearer); chave sandbox
  prefixo `$aact_hmlg_`.
- **Customer:** `POST /v3/customers` (`name` + `cpfCnpj` obrigatórios; `email`, `mobilePhone`,
  `address`, `addressNumber`, `complement`, `province`, `postalCode`, `externalReference`).
- **Assinatura:** `POST /v3/subscriptions` (`customer`, `billingType`, `value`, `nextDueDate`,
  `cycle`); cancelamento: `DELETE /v3/subscriptions/{id}`; cobranças: `GET
  /v3/subscriptions/{id}/payments` (cada cobrança tem **`invoiceUrl`** — a página de pagamento
  hospedada).
- **Webhook:** é **de COBRANÇA** (payment), como o prompt travou; payload = `id` (id do
  EVENTO), `event`, `dateCreated`, `payment{...}` com o atributo **`subscription`** quando a
  cobrança pertence a uma assinatura (confirmado no smoke real). Config: `POST/PUT
  /v3/webhooks` (`url`, `email`, `enabled`, `interrupted`, `apiVersion: 3`, `authToken`
  32–255 chars, `sendType`, `events[]`). O Asaas envia o authToken no header
  **`asaas-access-token`** a cada entrega. Entrega **at-least-once** (retry; após **15
  falhas consecutivas a fila é interrompida**; eventos se perdem após 14 dias) — responder
  **2xx rápido** e processar assíncrono.

## Checkout HOSPEDADO (cartão fora do sistema)

`billingType: 'UNDEFINED'` + redirecionamento para o **`invoiceUrl`** da primeira cobrança —
o cliente escolhe cartão/Pix/boleto **na página do Asaas**. O sistema guarda SÓ
`asaas_customer_id` e `asaas_subscription_id` (colunas novas em `accounts`). **Zero campo de
cartão** em form/request/log/banco — provado por teste (`assertNotSent` com
creditCard/ccv/holderName/expiryMonth em qualquer request). O retorno do cliente é
informativo; a verdade é o webhook.

## Mapa evento de cobrança → transição (tabela final, `BillingState::EVENTO_ESTADO`)

| evento | estado alvo | racional |
|---|---|---|
| `PAYMENT_CONFIRMED` | `active` | cartão aprovado (liquidação depois) |
| `PAYMENT_RECEIVED` | `active` | dinheiro recebido (Pix/boleto) |
| `PAYMENT_OVERDUE` | `overdue` | venceu sem pagar (grava `overdue_since`) |
| `PAYMENT_REFUNDED` | `suspended` | estorno desfaz o acesso pago (reversível) |
| `PAYMENT_CHARGEBACK_REQUESTED` | `suspended` | contestação |
| `PAYMENT_DELETED` / `PAYMENT_RESTORED` | (nenhum) | cobrança avulsa manipulada não decide assinatura — evento registrado como `ignored` |

Regras registradas: **idempotente** (mesmo alvo = no-op, sem efeito duplicado);
**`canceled` é terminal para evento de cobrança** (pagamento atrasado pós-cancelamento não
"descancela"; um novo checkout no painel rearma `canceled → overdue`); `active` limpa
`overdue_since`/`suspended_at`. Estados finais: `trial → active → overdue → suspended →
canceled` (+ `active` legacy), estendendo os da Fatia 25 sem tocar nos existentes.

## O webhook (as três invariantes)

`POST /webhook/asaas` (`AsaasWebhookController`; CSRF isento pelo prefixo `webhook/*` que já
existia — Tunnel/nginx intocados):
1. **Autenticidade:** `hash_equals` (timing-safe) do header `asaas-access-token` contra
   `ASAAS_WEBHOOK_TOKEN`; token vazio no `.env` NUNCA autoriza; sem/errado → **401 sem
   processar**.
2. **Idempotência:** dedup pelo `id` do evento — `billing_webhook_events.event_id` UNIQUE; o
   banco decide a corrida entre retries simultâneos; reentrega → 200 no-op. Segunda trava no
   job (`processed_at`) e terceira na transição (mesmo estado = no-op).
3. **Rápido + assíncrono:** o endpoint só valida token, insere a linha e **enfileira
   `ProcessAsaasWebhookEvent`** — responde 200 imediato. O job resolve a conta **pelo
   atributo `subscription`** (fallback: `customer`) e aplica a máquina. Evento sem conta →
   `ignored` (é assim que assinatura alheia/smoke não toca nada).

**Registro no sandbox:** já existia uma config criada pelo Fabio apontando para
`/webhookS/asaas` (plural — **surpresa registrada**); adaptação consciente: **PUT** na config
existente (`8d6ebb88-2075-40e4-b808-7e83314067e9`) corrigindo para
**`https://painel.nextgest.com.br/webhook/asaas`**, com `authToken` do `.env`, `apiVersion 3`,
`sendType SEQUENTIALLY`, `enabled`, e os **7 eventos** da tabela. Confirmado por leitura
(url/enabled/eventos=7).

## Corte de trial + suspensão (a pendência da Fatia 25)

- **`billing:sweep`** (comando, agendado **diário 03:20** em `routes/console.php`): (1)
  `trial` com `trial_ends_at` vencido → `overdue` (+`overdue_since`); (2) `overdue` há ≥
  **`billing.overdue_grace_days` = 5 dias** (env-ável) → `suspended`. Idempotente; não
  depende do webhook para "venceu sem nunca pagar".
- **Semântica de `suspended` (travada):** owner loga e é **redirecionado para `/assinatura`**
  (middleware `account.operational` em TODO o painel; a billing fica fora do gate — único
  destino); operador → **403** com recado; platform admin passa. **O bot PARA**: gate de
  operação (`Account::podeOperar()`) no **`Sender`** — funil único de TODO envio
  (auto/manual/aprovação/proativo/handoff), barrando com log `blocked/conta_suspensa`
  auditável. **NADA é apagado** — provado por teste; pagamento confirmado (webhook) →
  `active` → acesso e envio religam sozinhos.
- **`canceled`**: mesmo acesso de `suspended`, marcado cancelado (id da assinatura preservado
  pra auditoria); reativa só com novo checkout.
- **Legacy imune por construção:** o sweep só alcança `status='trial'` com `trial_ends_at`
  (contas 1/2 são `active`/null) ou `status='overdue'`; o webhook só alcança conta com
  `asaas_subscription_id`. Provado por teste e por leitura pós-migração.

## Gate de operação — onde entrou e o que NÃO mudou

Uma checagem no topo do `Sender::send()` (após o claim do log, antes dos freios) — mesmo
espírito do kill switch: best-effort, isolado, **nenhuma linha de matching/FlowEngine/
pipeline de decisão mudou** (diff de `app/Whatsapp/` = só o bloco novo no Sender;
`app/Jobs/Process*`/`ClassifyWithAi`/`app/Kanban/` zero diff). A decisão de resposta chega
intacta ao funil e é barrada ali, auditada.

## Migrations (aditivas, aplicadas em produção, 345ms) + leitura

`2026_07_06_000006_add_asaas_billing`: `accounts` +4 (`asaas_customer_id`,
`asaas_subscription_id` com index, `overdue_since`, `suspended_at`); tabela
`billing_webhook_events` (event_id UNIQUE = dedup, payload JSON de auditoria — sem dado de
cartão por construção). **Sem backfill necessário**: contas 1/2 já eram `active` (Fatia 25) e
ficaram com Asaas/marcos null — read-back confirmou (`active`, tudo null, intactas).

## Smoke REAL no sandbox (registrado)

- Conectividade OK (listagem de webhooks); **customer `cus_000008339196`**, **assinatura
  `sub_9z8ihy0cbsi1uf7g`** (ACTIVE), primeira cobrança **`pay_ave7m81viu72xv7p`** PENDING
  **com `subscription` no payload** (vínculo confirmado na prática) e **invoiceUrl hospedada**
  `https://sandbox.asaas.com/i/ave7m81viu72xv7p`. A assinatura de fumaça foi **cancelada** em
  seguida (sem cobrança recorrente de teste pendurada).
- **E2E em produção** (endpoint real, via loopback): sem token → **401**; token errado →
  **401**; token certo → **200**; reentrega do MESMO event id → **200 com UMA linha**
  (dedup); worker processou o job → `ignored` (assinatura de ninguém), contas 1/2 intactas.

## Testes (31 novos, 3 arquivos)

- `AsaasWebhookTest` (11): 401 sem token/errado (nada processado, nada enfileirado); 200 +
  registro + job enfileirado (Queue::fake prova o assíncrono); mesmo event id 2x = 1 linha e
  1 job; CONFIRMED→active; OVERDUE→overdue+marco; reativação suspended→active limpando
  marcos; **vínculo pelo `subscription` muda SÓ a conta certa (A/B)**; assinatura
  desconhecida → ignored sem tocar conta; retry do próprio job idempotente (sem efeito
  duplicado, nem SystemEvent extra); PAYMENT_DELETED não muda estado; canceled terminal.
- `BillingSuspensionTest` (12): sweep — trial vencido→overdue, idempotente, +6d→suspended;
  trial vigente intacto; **legacy imune** (30 dias depois, segue active); owner suspenso →
  redirect `/assinatura` (única tela, com o aviso "nenhum dado foi apagado"); operador → 403
  (e billing segue owner-only); conta operante sem redirect; canceled bloqueia como suspensa;
  **bot não responde** (blocked/conta_suspensa, provider NUNCA chamado, contatos/regras
  intactos); manual também para; **reativação religa o envio**; regressão: conta operante
  envia normal; ação `assinar` forjada por operador → 403 sem nada criado.
- `BillingCheckoutTest` (8): assinar cria customer (CPF da conta, base sandbox, header
  access_token) + assinatura (UNDEFINED/MONTHLY/venc. no fim do trial/valor do config) e
  redireciona pro invoiceUrl; **nenhuma request com dado de cartão**; segunda chamada reusa
  ids (1 POST de cada); conta sem documento não chama o Asaas; cancelar → canceled sem apagar
  nada (id preservado); página renderiza com plano/status/aviso PCI; rota owner-only.

## Ajustes deliberados

1. `NavegacaoSidebarTest::MENU` — **+1 entrada** `'billing' => 'Assinatura'` (item NOVO de
   menu; nenhuma rota/rótulo existente mudou).
2. `phpunit.xml` — 3 envs `ASAAS_*` FAKE (garante que teste nunca vê chave real).

Nenhum teste de envio/matching/pipeline existente precisou mudar (o gate só age em conta
suspensa; contas de teste são `active`).

## Contagem de testes

| | testes | assertions |
|---|---|---|
| Antes | 918 | 3672 |
| Depois | **949** | **3775** |

Suíte inteira **sequencial**, tudo verde. Build Vite/Tailwind **foreground**.

## Confirmações explícitas

- Tunnel/nginx/Nextgest/2FA **intocados** (a rota do webhook é da aplicação).
- Pipeline/matching/fluxo **sem mudança de decisão** (só o gate no funil de envio).
- Nenhum DELETE físico em lugar nenhum; suspensão 100% reversível.
- **`queue:restart` executado após o commit** — worker reciclado pid 442066 (21:06) →
  **pid 454641 (21:25)**; workers do Nextgest intocados (sinal por app).

## Checklist de GO-LIVE para o Fabio (sandbox → produção, quando for vender)

1. Criar/usar a conta **de produção** do Asaas; gerar a **chave de produção** (`$aact_prod_...`).
2. No `.env`: `ASAAS_BASE_URL=https://api.asaas.com`, `ASAAS_API_KEY=<prod>`, novo
   `ASAAS_WEBHOOK_TOKEN` forte de produção.
3. **Registrar o webhook DE PRODUÇÃO** (UI ou API) para
   `https://painel.nextgest.com.br/webhook/asaas` com o token de produção, `apiVersion 3` e
   os 7 eventos da tabela.
4. **`php artisan config:clear`** (e `queue:restart`) após trocar o `.env`.
5. Preço real: `BILLING_PLAN_VALUE` (numérico — vira o `value` da assinatura E o texto da
   tela; substitui o BILLING_PLAN_PRICE da fatia 25). Carência: `BILLING_OVERDUE_GRACE_DAYS`.
6. Pendências da Fatia 25 que continuam: `MAIL_*` (transporte real +
   `MAIL_FROM_ADDRESS`), texto real dos Termos/Política.
7. Conferir que o scheduler do sistema roda (`billing:sweep` diário 03:20).

## Commit
Local, sem push (Fabio empurra). Hash na resposta.
