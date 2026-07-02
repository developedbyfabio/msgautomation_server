# 10 — Canais multi-provedor (Evolution + WhatsApp Cloud API oficial)

**Status: DESENHO APROVADO (decisoes CH-D1..D5 abaixo) · CH-1 ENTREGUE (2026-07-02).**
Baseline do desenho: `7ed3ef0` (476 verdes) · Pos-CH-1: 485 verdes.

## Visao

Hoje o canal e Evolution (nao-oficial, QR, numero pessoal, risco de ban administrado
pelos freios). Na **WhatsApp Cloud API oficial**, mensagem de atendimento (cliente
inicia, resposta na janela de 24h) tem **custo zero da Meta** — e o msgautomation e
majoritariamente reativo. Visao: **provedor por canal** — cada conta/canal escolhe
Evolution (QR, numero pessoal, grupos) OU API oficial (numero de negocio verificado,
sem ban, reativo gratis). No multi-tenant, a oferta profissional.

Referencia de precos validada pelo Fabio (01/07/2026, Brasil): service **gratis**;
utility/auth ~US$0,0068; marketing ~US$0,0625 por mensagem entregue; cobranca POR
MENSAGEM desde 07/2025; janela de 24h reinicia a cada mensagem do cliente; proativa
fora da janela exige **template aprovado**.

---

## PARTE 1 — Auditoria do acoplamento Evolution (estado real do codigo)

Varredura completa em 2026-07-02. Classificacao: **[N]** neutro (ja serve pros dois
provedores) · **[A]** precisa virar adaptador/provider · **[E]** exclusivo-Evolution.

### 1.1 Envio

| Ponto | Arquivo/metodo | Classe |
|---|---|---|
| `sendText` HTTP: POST `/message/sendText/{instance}`, header `apikey` GLOBAL de config, corpo `{number, text}`, numero = digitos antes do `@` | `app/Whatsapp/Drivers/EvolutionDriver.php::sendText` | [A] |
| Binding unico pro app inteiro: `WhatsappGateway -> EvolutionDriver` (um driver global, nao por canal) | `app/Providers/AppServiceProvider.php::register` (linha 23) | [A] |
| `Sender::send` chama `$this->gateway->sendText($channel->instance, ...)` — a intencao e neutra (recebe o Channel), mas o gateway resolvido e unico e a credencial vem de env | `app/Whatsapp/AutoReply/Sender.php` (linha 114) | [A] |
| Freios, log com redacao de segredo, R2, idempotencia por incoming — nada Evolution | `Sender.php` (resto) | [N] |
| Reativo responde PELO CANAL DO INCOMING (`$incoming->channel`) — ja certo pra multi-provedor | `app/Jobs/SendAutoReply.php` (linhas 55, 90) | [N] |
| Proativa escolhe canal `oldest('id')` da conta — com multi-canal/provedor precisa escolher por CAPACIDADE (ver Parte 4) | `app/Jobs/SendProactiveMessage.php` (linha ~109) | [A] |

### 1.2 Webhook (entrada)

| Ponto | Arquivo/metodo | Classe |
|---|---|---|
| Rotas `/webhook/evolution` e `/webhook/evolution/{token}` | `routes/web.php` (64-69) | [A] (rota por provedor) |
| Controller so enfileira e responde 200 — neutro em essencia, nome/rota Evolution | `app/Http/Controllers/EvolutionWebhookController.php` | [N] (renomear/rotear) |
| Verificacao: token por canal (MT-0) + secret global DEPRECADO. Cloud API exige OUTRO ritual: GET challenge (`hub.verify_token`/`hub.challenge`) + assinatura HMAC `X-Hub-Signature-256` no POST (**A CONFIRMAR na doc oficial da Meta**) | `app/Http/Middleware/VerifyWebhookSecret.php` | [A] |
| Normalizacao do payload: evento `messages.upsert` (aceita `MESSAGES_UPSERT`), campos `instance`, `data.key.{id,remoteJid,fromMe}`, `pushName`, `messageType`, `message.*`, `messageTimestamp`; catch-all de tipo (nada descartado por tipo) | `EvolutionDriver::normalizeIncoming` + `inferirTipo` + `extrairTexto` | [A] (JA e um adaptador puro — so falta ser "um de dois") |
| Resolucao do canal POR `instance` (campo do payload Evolution). Cloud API identifica por `metadata.phone_number_id` | `app/Jobs/ProcessIncomingWhatsappMessage.php::handle` (linha 57) | [A] |
| DTO com campo `evolutionMessageId`; coluna `incoming_messages.evolution_message_id`; idempotencia por (`instance`,`evolution_message_id`) | `app/Whatsapp/IncomingMessageData.php`, `app/Models/IncomingMessage.php`, `ProcessIncomingWhatsappMessage::persistir` | [A] (semanticamente e `provider_message_id`; ver CH-D4) |
| Pipeline de dominio inteiro (fromMe -> grupo -> gate de contato -> fluxo -> regra -> IA -> silencio; kanban; tags; opt-out por palavra) | jobs/dominio | [N] |

### 1.3 Conexao / administracao da instancia

| Ponto | Arquivo/metodo | Classe |
|---|---|---|
| Cliente de gerencia: `fetchInstances`, `createInstance` (WHATSAPP-BAILEYS), `setWebhook`, `connect` (QR), `connectionState`, `logout`, `groupInfo` | `app/Whatsapp/EvolutionApi.php` (inteiro) | [E] |
| Tela de conexao: QR + polling de `connectionState`; mapeia `open/connecting/close -> connected/connecting/disconnected` | `app/Livewire/Conexao.php` | [E] (vira condicional por provedor) |
| Status na barra: `connectionState` + logout + abrir QR | `app/Livewire/StatusConexao.php` | [E] (idem) |
| Middleware de rota usa `channels.status` (abstrato — quem escreve sao as telas Evolution) | `app/Http/Middleware/EnsureWhatsappConnected.php` | [N] |
| Comandos: `EvolutionQr`, `EvolutionStatus`, `EvolutionSetup` | `app/Console/Commands/` | [E] |
| `WhatsappSend` (usa o gateway) | `app/Console/Commands/WhatsappSend.php` | [N] (via provider) |

### 1.4 Grupos

| Ponto | Arquivo/metodo | Classe |
|---|---|---|
| Metadados de grupo (subject) via `EvolutionApi::groupInfo` | `app/Whatsapp/Groups/GroupNameResolver.php`, `app/Jobs/ResolveGroupName.php` | [E] |
| Deteccao de grupo por sufixo `@g.us`: guard (2x), ProactiveGuard (2x), AudienceResolver, PainelMetrics (3x), Conversas | `AntiBanGuard::isGroup` e usos diretos de `str_ends_with(..., '@g.us')` | [N] com ressalva (ver 1.6) |

### 1.5 Config / schema

| Ponto | Onde | Classe |
|---|---|---|
| `services.evolution.{base_url, api_key, instance, webhook_url}` — credencial GLOBAL em env; MT-2 (doc 09) ja previa "EvolutionApi por canal", nao construido | `config/services.php` (45-51) | [A] (credencial vai pro CANAL, cifrada) |
| `channels`: `instance`, `webhook_token` (MT-0), `status`, `remote_jid`, timestamps — SEM `provider`, SEM credenciais | `app/Models/Channel.php` / migration | [A] |
| Secret global de webhook (deprecado desde MT-0) | `services.webhook.*` | [N] (morre na migracao de URL) |

### 1.6 Premissas implicitas (o perigo silencioso)

1. **Formato de JID**: `@s.whatsapp.net` / `@g.us` permeia dominio (contatos,
   isGroup, metricas, cofre por contato). Cloud API usa `wa_id` (numero puro).
   Proposta: o formato interno canonico CONTINUA sendo JID; o adaptador Cloud
   converte `wa_id -> {wa_id}@s.whatsapp.net` na entrada e extrai digitos na
   saida (o EvolutionDriver JA extrai digitos no send). Dominio intocado.
2. **Mensagem livre sempre pode**: nenhuma nocao de janela no codigo. No oficial,
   texto livre so DENTRO da janela de 24h (Parte 3).
3. **Grupos existem**: skip_groups, kanban, metricas de grupo. No oficial nao ha
   eventos de grupo — vira capacidade declarada; caminho de grupo simplesmente
   nunca dispara (e a UI esconde o que nao se aplica).
4. **Um provedor por processo**: binding global do gateway. Vira registry por canal.
5. **Conexao = QR**: as telas assumem o ritual Evolution. Vira condicional.

---

## PARTE 2 — Contrato `ChannelProvider` (o coracao)

### 2.1 Schema (aditivo, em CH-1)

```
channels
  + provider      string(16) default 'evolution'   -- 'evolution' | 'cloud_api'
  + credentials   text NULL, cast 'encrypted:array' -- cifrado em repouso (padrao do cofre S5)
  + provider_ref  string NULL                       -- chave de roteamento do webhook:
                                                    --   evolution: instance name (espelha `instance`)
                                                    --   cloud_api: phone_number_id
```

Credenciais POR CANAL (nunca em env global; env vira so default de bootstrap dev):
- **evolution**: `{base_url, apikey, instance}`
- **cloud_api**: `{access_token (permanente), phone_number_id, waba_id, verify_token, app_secret}`

Regras do cofre valem: valor cifrado, NUNCA em log (redacao), UI mostra mascarado,
teste de conexao nunca ecoa a credencial.

### 2.2 Interface (nomes indicativos)

```php
interface ChannelProvider
{
    public function key(): string;                          // 'evolution' | 'cloud_api'
    public function capabilities(): ChannelCapabilities;    // declaradas, NUNCA assumidas

    // Envio (transporte puro; freios ficam no Sender, como hoje)
    public function sendText(Channel $channel, string $to, string $text): SentMessageData;
    // CH-3 (so oficial): sendTemplate(Channel, to, TemplateRef, params) — capacidade separada

    // Webhook: verificacao + adaptacao do payload pro MESMO IncomingMessageData
    public function verifyWebhook(Request $request): bool|Response; // evolution: token (MT-0)
                                                                    // cloud: GET challenge + HMAC X-Hub-Signature-256
    public function normalizeIncoming(array $payload): ?IncomingMessageData;
    public function resolveChannel(array $payload): ?Channel;       // instance | phone_number_id -> canal

    // Conexao (estado por provedor; a tela vira condicional)
    public function connectionState(Channel $channel): string;     // connected|connecting|disconnected
}
```

Resolucao: `ProviderRegistry::for($channel->provider)` (container). O `Sender`
continua UNICO — so troca `$this->gateway->sendText($channel->instance, ...)` por
`$registry->for($channel)->sendText($channel, ...)`. NADA envia fora do Sender
(invariante mantida).

O dominio nao sabe de onde a mensagem veio: os DOIS adaptadores alimentam o MESMO
`IncomingMessageData` -> mesmo normalizador catch-all -> mesma `incoming_messages`
-> mesmo pipeline. So o adaptador conhece o formato do provedor.

Mapeamento Cloud API -> DTO (**formato A CONFIRMAR na doc oficial; validar com o
numero de teste antes de codar CH-2**):

| DTO | Evolution | Cloud API |
|---|---|---|
| instance/provider_ref | `payload.instance` | `entry[].changes[].value.metadata.phone_number_id` |
| providerMessageId | `data.key.id` | `messages[].id` (wamid) |
| remoteJid | `data.key.remoteJid` | `messages[].from` (wa_id) + `@s.whatsapp.net` |
| fromMe | `data.key.fromMe` | nao vem em `messages` (echo proprio nao e entregue; statuses a parte) -> `false` |
| pushName | `data.pushName` | `contacts[].profile.name` |
| type/text | `messageType`/`message.*` (catch-all) | `messages[].type` + `text.body`/caption (mesmo catch-all) |
| receivedAt | `messageTimestamp` | `messages[].timestamp` |

### 2.3 Capacidades declaradas (o resto do sistema CONSULTA, nunca assume)

```php
ChannelCapabilities {
    bool $grupos;                    // evolution SIM | cloud_api NAO
    bool $mensagemLivreForaDaJanela; // evolution SIM | cloud_api NAO (24h)
    bool $proativaLivre;             // evolution SIM | cloud_api NAO (so template, CH-3)
    bool $qr;                        // evolution SIM | cloud_api NAO (credenciais)
    bool $template;                  // evolution NAO | cloud_api SIM (CH-3)
}
```

Consumidores: ProactiveGuard (freio novo), Sender (janela), telas (esconder QR/
grupos/skip_groups onde nao se aplica), onboarding (formulario por provedor).

### 2.4 Webhook: rotas e verificacao

- `/webhook/evolution/{token}` — existente (MT-0). Continua identico.
- `/webhook/cloud/{token}` — novo em CH-2. GET = challenge (`hub.verify_token` do
  canal); POST = HMAC `X-Hub-Signature-256` com o `app_secret` do canal
  (**header/algoritmo A CONFIRMAR**), comparacao em tempo constante.
- O token por canal (MT-0) segue sendo a chave de ROTEAMENTO nas duas rotas; a
  verificacao criptografica do Cloud vem POR CIMA. Payload confere com o canal
  (`phone_number_id` == `provider_ref`) — divergiu, descarta com contador de
  diagnostico (mesmo padrao da "instancia desconhecida").

---

## PARTE 3 — A janela de 24h (o problema novo do oficial)

- **Rastreio**: `contacts.last_inbound_at` (gravado no upsert de contato que o
  webhook JA faz — barato, provider-neutro, util pra metricas mesmo sem Cloud).
  Janela aberta = `now - last_inbound_at < 24h`; reinicia a CADA mensagem do
  cliente. Fase 1: por contato (1 canal/conta). Generalizacao contato+canal so
  se um mesmo contato falar por 2 canais da MESMA conta (horizonte, com MT-2).
- **Onde NAO morde**: reativo (regras/fluxos/IA) responde em segundos — sempre
  dentro da janela por construcao.
- **Onde MORDE**: fila de aprovacao (aprovar 30h depois) e envio manual tardio.
  Proposta: no canal oficial (capacidade `mensagemLivreForaDaJanela = false`), o
  Sender bloqueia com motivo claro (`janela_24h`) e a UI explica: "janela de 24h
  fechada — este canal exige template pra iniciar conversa". A pendencia em
  /revisao mostra a janela RESTANTE (countdown discreto) quando o canal e oficial.
- **Proativas no oficial, fase 1: BLOQUEADAS por capacidade.** ProactiveGuard
  ganha o freio nomeado `canal_sem_proativa_livre` (mesmo padrao dos 9 existentes).
  Templates aprovados sao a CH-3 — esqueleto desenhado:
  - tabela `wa_templates` (canal, nome, idioma, categoria service|utility|auth|
    marketing, status de aprovacao na Meta, corpo com variaveis posicionais,
    custo estimado por categoria);
  - fase 1 da CH-3: REGISTRO MANUAL (template ja aprovado no Business Manager —
    o app so guarda nome/idioma/categoria e envia); submissao via API e fase 2;
  - campanha em canal oficial = escolher template (custo mostrado no preview:
    marketing ~US$0,0625/msg em 07/2026) em vez de texto livre; rodape P-4 vale
    onde texto livre valer (dentro da janela), template ja carrega opt-out proprio.

---

## PARTE 4 — Freios e semantica por provedor

- **Evolution**: TUDO como hoje. Anti-ban e vital (numero pessoal, nao-oficial).
- **Cloud API**: sem ban por automacao em si, mas existem **quality rating** do
  numero e **limites de tier** de conversas iniciadas/24h (**valores atuais A
  CONFIRMAR na doc da Meta**). Os tetos/janela/cadencia existentes CONTINUAM
  disponiveis como protecao de REPUTACAO — mesmo motor, tooltip diferente
  ("aqui protege sua quality rating, nao contra ban").
- **Identicos (dominio, nao provedor)**: fromMe descartado, idempotencia por
  mensagem, opt-out (palavra e trilha LGPD), rodape P-4, cofre S5, gates humanos.
- **Grupos**: capacidade. No oficial nao existem eventos de grupo — o caminho de
  grupo nunca dispara; UI esconde skip_groups/aba de grupos no canal oficial.
- **Proativa multi-canal**: quando a conta tiver 2 canais (MT-2), a proativa
  escolhe canal por CAPACIDADE (proativa_livre ou template), nao mais `oldest(id)`.

---

## PARTE 5 — Multi-tenant e onboarding (amarra com MT-2/MT-3)

- `channels.provider` entra no desenho do MT-2 (CRUD de canal por conta) e do
  MT-3 (onboarding): criar conta -> **ESCOLHER PROVEDOR** ->
  - Evolution: gera instancia + QR (fluxo atual);
  - Cloud API: formulario de credenciais (access token permanente,
    phone_number_id, WABA id, verify_token, app_secret) + botao "testar conexao"
    (chamada de sanity readonly na Graph API — endpoint **A CONFIRMAR**) + envio
    de teste.
- **Caminho de teste SEM CUSTO (dev)**: app Meta em modo dev + numero de TESTE da
  WABA (envia gratis pra ate 5 numeros verificados — limite **A CONFIRMAR**) +
  webhook publico HTTPS.
- **Pre-requisito HTTPS (pendencia real)**: a Meta EXIGE HTTPS valido no webhook.
  Hoje o app atende HTTP local (8080) e a Evolution roda em container local.
  Opcoes (SEM implementar):
  1. **RECOMENDADA (producao): Caddy como reverse proxy com TLS automatico**
     (Let's Encrypt) num subdominio -> proxy pra 127.0.0.1:8080. Um binario,
     config de ~4 linhas, renovacao automatica. Requer dominio + porta 443.
  2. nginx + certbot: equivalente, mais passos de manutencao.
  3. Cloudflare Tunnel: sem porta aberta; bom pra dev/homologacao; adiciona
     dependencia externa no caminho do webhook de producao.
  4. ngrok/tunel efemero: SO desenvolvimento.
  O Evolution NAO e afetado (webhook dele e local). HTTPS e pre-requisito de
  CH-2 EM PRODUCAO; pra validar com numero de teste, tunel de dev basta.

---

## PARTE 6 — Plano de fatias

| Fatia | Conteudo | Risco | Gate |
|---|---|---|---|
| **CH-1** Contrato + Evolution vira provider | `channels.provider/credentials/provider_ref` (aditivo, default evolution, backfill do env pro canal com fallback de leitura), `ProviderRegistry`, mover normalize/send/conexao pra dentro do `EvolutionProvider`, DTO `providerMessageId`, rotas intactas. **ZERO mudanca de comportamento — disciplina do MT-0** (suite antiga intacta, TenantIsolationTest estendido) | Medio (mexe no caminho vivo sem mudar nada) | Suite verde + webhook de producao intocado |
| **CH-2** Cloud API reativo-only | Webhook GET challenge + HMAC, adaptador de payload, sendText na janela, `last_inbound_at` + bloqueio `janela_24h` (Sender) + countdown na /revisao, freio `canal_sem_proativa_livre`, tela de conexao condicional, teste com numero de teste da Meta | Medio | Conversa real de teste no canal oficial (dev, custo zero) |
| **CH-3** Templates oficiais | `wa_templates` (registro manual fase 1; submissao via API fase 2), categorias/custos no preview, campanhas em canal oficial via template | Medio | Primeiro template real aprovado + campanha de teste |
| **CH-4** Onboarding com provedor | Escolha de provedor no criar-conta (junto do MT-3), form + teste de conexao | Baixo | Conta nova conectada nos dois provedores |

### Decisoes numeradas pro Fabio

- **CH-D1 — Ordem dos arcos.** Recomendo: **CH-1 ja** (paga a divida de
  acoplamento com risco controlado e destrava tudo) -> MT-1/MT-2 (canal por
  conta ja nasce com `provider`) -> **CH-2** -> MT-3+CH-4 juntos -> CH-3.
  Alternativa: MT-1..3 inteiros antes de CH-1, se a conta 2 (Evolution) for
  mais urgente que o canal oficial.
- **CH-D2 — HTTPS. DECIDIDO (Fabio): Cloudflare Tunnel**, com ingress SO na
  rota do webhook (o resto do app continua local). Sem porta aberta no servidor;
  o tunel publica apenas `/webhook/cloud/*`. Implementacao na CH-2.
- **CH-D3 — Oficial reativo-only na fase 1.** Recomendo **SIM**: e onde o custo
  e zero e o produto ja e forte; proativa oficial espera a CH-3 (templates).
- **CH-D4 — Coluna `evolution_message_id`.** Recomendo **manter o nome da coluna**
  (rename em producao e risco cosmetico); o DTO renomeia pra `providerMessageId`
  em CH-1 e a coluna fica documentada como legado semantico.
- **CH-D5 — Horizonte (nao agora).** Midia nos dois provedores; statuses
  (delivered/read) pro funil; janela por contato+canal; submissao de template
  via API; roteamento de proativa por capacidade quando houver 2 canais na conta.

### O que fica explicitamente A CONFIRMAR na doc da Meta antes de CH-2
1. Header/algoritmo exato da assinatura (`X-Hub-Signature-256`, HMAC-SHA256 do corpo com app secret).
2. Shape atual do payload de mensagens (entry/changes/value/messages) e dos statuses.
3. Limites do numero de teste (quantos destinatarios verificados).
4. Tiers atuais de conversas iniciadas e mecanica da quality rating.
5. Endpoint de sanity pra "testar conexao" sem custo.


---

## CH-1 — ENTREGUE (2026-07-02)

Contrato + Evolution como primeiro provider, com ZERO mudanca de comportamento
(disciplina do MT-0): os 476 testes anteriores passaram INTACTOS (ajuste de
setup em 2 arquivos que instanciavam o driver antigo; NENHUMA expectativa
mudou) + 9 testes novos = 485 verdes. Smoke do webhook vivo pos-restart:
endpoint 200 com o secret retrocompat, worker processou via provider, evento
nao-mensagem descartado sem persistir.

O que entrou:
- `channels.provider` (default evolution, backfill) + `channels.credentials`
  (cifrado, encrypted:array, NULL — MT-2 preenche; accessor
  `EvolutionProvider::credentialsFor` le canal -> fallback env, nunca loga);
- `App\Channels\ChannelProvider` (key, capabilities, sendText(Channel,...),
  verifyWebhook, normalizeIncoming, connectionState) + `ChannelCapabilities`
  + `ProviderRegistry` (map extensivel; `for(Channel)`; desconhecido = excecao
  alta) + `UnknownChannelProviderException`;
- `EvolutionProvider` absorveu: sendText (ex-EvolutionDriver), adaptador
  messages.upsert catch-all, verificacao do token do webhook (MT-0), estado de
  conexao normalizado; `EvolutionApi` MOVIDO pra `App\Channels\Evolution` como
  detalhe interno (`provider->api(canal)`); EvolutionDriver REMOVIDO;
- consumidores rewired: Sender (registry POR CANAL do envio), middleware do
  webhook (canal resolvido delega verify ao provider), Conexao/StatusConexao,
  GroupNameResolver (consulta `capabilities()->grupos`), 3 comandos de console,
  proativa com `Channel::defaultFor()` explicito (mesma semantica oldest-id);
- capacidades CONSULTADAS: freio novo `canal_sem_proativa_livre` no
  ProactiveGuard (10o freio; nunca dispara com Evolution) e gancho
  `mensagemLivreForaDaJanela` no Sender pros modos manual/aprovacao (motivo
  `janela_24h`; no-op com Evolution — CH-2 troca o "assume fechada" pelo
  `last_inbound_at` real);
- DTO renomeado (CH-D4): `IncomingMessageData::providerMessageId`; coluna
  `evolution_message_id` MANTIDA (legado semantico documentado);
- `WhatsappGateway` reduzido a alias DEPRECADO do webhook (so
  normalizeIncoming; binding -> EvolutionProvider) — morre quando a rota
  resolver o provider (CH-2);
- UI: badge somente-leitura do provedor no card do canal em /configuracoes.

**JID canonico (registrado):** o formato interno e o JID atual
(`@s.whatsapp.net`/`@g.us`) em todo o dominio; adaptadores convertem NA BORDA
(Cloud API: `wa_id` -> jid na entrada, digitos na saida). Nenhum codigo de
dominio muda por provedor.

**Proximo da ordem CH-D1:** MT-1/MT-2 pelo prompt estagiado
`msgautomation-mt123-futuro.md` — **COM o Fabio presente (tem gates)**; CH-2
(Cloud API reativo-only, com o Cloudflare Tunnel do CH-D2) depois.


---

## CH-2 PARTE A — ENTREGUE (2026-07-02) — CloudApiProvider mockado completo

**Verificacao da doc da Meta (passo obrigatorio): NAO FOI POSSIVEL deste
ambiente.** WebFetch (harness) e o proprio servidor nao alcancam dominios Meta
— a rede corporativa BLOQUEIA developers/graph.facebook.com (DNS resolve, TCP
nao completa; Google/Cloudflare normais). Mitigacao: implementado pelo desenho
CH-0 + contrato publico consolidado da Cloud API, com a VERSAO do Graph
CONFIGURAVEL (`services.cloud_api.graph_version`, default v21.0) e os 5 pontos
marcados pra VALIDACAO REAL na Parte B. **Pre-passo NOVO da Parte B: liberar
os dominios da Meta no firewall da rede** (sem isso o servidor nem envia).

O que entrou (tudo mockado, 518 -> 530 verdes, anteriores intactos):
- `CloudApiProvider` (segunda implementacao do contrato CH-1): challenge GET
  (hub.verify_token do canal), HMAC X-Hub-Signature-256 sobre o CORPO CRU com
  app secret do canal (tempo constante; invalida = 401 + log, nunca processa);
  adaptador Meta -> MESMO DTO (wa_id -> JID canonico NA BORDA; wamid na chave
  de idempotencia legada; statuses ignorados com log leve — D5; nao-texto no
  catch-all); sendText no Graph API com erros mapeados sem vazar token;
  connectionState leve sob demanda. Capacidades: TUDO false (grupos, mensagem
  livre, proativa, qr, template).
- `channels.instance` = phone_number_id no canal cloud (mesma chave de
  roteamento da instancia Evolution — provider_ref do desenho dispensado).
- Rota `GET|POST /webhook/cloud/{token}` + `ChannelWebhookController`
  (provider-agnostico; enfileira com HINT do canal — o provider certo
  normaliza). A rota da Evolution segue no controller antigo, INTOCADA.
- Janela de 24h POR CONTATO+CANAL: `contact_channel_windows` (touch em todo
  inbound); Sender consulta a janela REAL quando o provider nega mensagem
  livre (manual/aprovacao bloqueados com `janela_24h` SO no cloud); countdown
  na pendencia do /revisao (aberta = resta Xh; fechada = aviso de bloqueio).
- `msg:channel:create-cloud` (segredos por prompt oculto, cifrados);
  /configuracoes lista TODOS os canais com "verificar conexao" no cloud.
- Gate de isolamento estendido: canal cloud da B roteia/responde/grava janela
  SO na B (mesmo wa_id espelhado nas duas contas).

**Parte B (setup vivo, INTERATIVA) — aguardando o Fabio.**
