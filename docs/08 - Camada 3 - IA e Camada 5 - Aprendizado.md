# Camada 3 (IA classificadora) + Camada 5 (aprendizado via UI)

Status: **Fatia 1 ENTREGUE** (driver Gemini + classificador + toggles + escalar). Fatias 2-4
pendentes (ver fim). A IA e **FALLBACK** que encaixa no pipeline atual — NAO reescreve o que
funciona. Kill switch do robo (`auto_reply_settings.enabled`) intocado. **Tudo nasce OFF:** com
a Fatia 1 no ar e nada ligado, o robo se comporta EXATAMENTE como antes.

> Detalhe do que foi construido na Fatia 1: ver secao "Fatia 1 — entregue" no fim deste doc.

## Principio
A IA entra como **ultimo recurso**: so quando fluxo/regra NAO resolveram. Nao e um
`processarMensagem` novo (como a proposta de referencia sugeria) — e **um ramo novo** dentro
de `ProcessIncomingWhatsappMessage::avaliarAutoResposta()` + **um job de classificacao** na
fila. Toda resposta que a IA decidir mandar sai pelo **mesmo `SendAutoReply` -> `Sender`**
(todos os freios + R2 + idempotencia + `{senha:}` resolvido local). A IA **classifica
intencao**, nao gera texto livre.

## Nao-negociaveis (guarda desta camada)
- **IA e FALLBACK:** reusa freios/envio/log/idempotencia; nao reescreve o pipeline.
- **Valores sensiveis ficam LOCAIS:** a IA devolve rotulo/placeholder (`{senha:x}`); o `Sender`
  substitui o valor real em memoria no POST (como ja faz hoje). O valor real **nunca** vai ao
  Gemini nem a base enviada ao modelo.
- **IA respeita TODOS os freios** (janela, tetos, delays, intervalo por contato, allowlist,
  pular grupos, fromMe, idempotencia) — porque termina no `Sender`. Nunca fura.
- **Kill switch PROPRIO da IA** (`auto_reply_settings.ai_enabled`, global) + toggle por contato
  (`contacts.ai_enabled`). Ambos nascem OFF. O kill switch do robo nao e tocado.
- **Free tier do Gemini e limitado** (429/cota diaria): erro/estouro -> a IA **escala ou
  silencia**, nunca manda lixo, nunca quebra. Backoff. Chave so no `.env` (chmod 600,
  gitignored, nunca em relatorio/log/commit).

## Parte 1 — O que ja existe (reusar, nao recriar)
| Peca existente | Onde vive | Como a IA encaixa (sem duplicar) |
|---|---|---|
| Portao por contato | `contacts.auto_reply_mode` (default/on/off) | Adiciona `ai_enabled` + `ai_mode`. O `auto_reply_mode` segue governando quem recebe. |
| Regras + especificidade | `auto_reply_rules` + filhas, `RuleMatcher` | Adiciona `ai_match_enabled` por regra + frases-exemplo. Resposta continua vindo do `RuleResponder`. |
| Fluxos | `flows`/`flow_sessions`, `FlowEngine` | IA entra DEPOIS deles. Sessao ativa nunca chega na IA. |
| Cofre | `secrets` + `{senha:nome}`, `SecretVault` | Reusado inteiro. IA nunca ve o valor; `Sender` resolve no POST. |
| Freios + envio + R2 | `AntiBanGuard`, `Throttle`, `Sender`, `SendAutoReply` | Reusados sem tocar. IA termina despachando `SendAutoReply`. |
| Logs | `auto_reply_logs` | O ENVIO da IA loga aqui igual. A DECISAO da IA vai em tabela nova `ai_decisions`. |
| Config instantanea | `auto_reply_settings` (1 linha/conta) | Adiciona `ai_enabled` (switch da IA), `ai_confidence_threshold`, temas de aprovacao. Flip instantaneo. |
| Segredo em `.env` | `config/services.php`, `config/secrets.php` | `GEMINI_API_KEY` segue o mesmo padrao (bloco `gemini` em services.php; valor so no `.env`). |

## Parte 2 — Desenho da IA
1. **Driver abstrato + Gemini.** Contrato `App\Contracts\AiClassifier` (espelha `WhatsappGateway`)
   com `classify(request): AiClassification`. Impl `App\Ai\Drivers\GeminiDriver`. Config em
   `config/services.php` -> `gemini` (`api_key` do `.env`, `model`, `timeout`, `daily_cap`).
   Modelo do free tier: Flash / Flash-Lite (**id exato a confirmar** no Google AI Studio).
   429/cota/erro -> backoff exponencial com jitter, poucas tentativas; falha final -> fallback
   = escalar (modo aprovacao/sensivel) ou silenciar. Contador de chamadas/dia (estilo `Throttle`).
2. **Classificadora, nao geradora livre.** Prompt de sistema travado ("so classifica, nao
   inventa, nao responde como pessoa, nao trata dado sensivel"). Retorna JSON estrito:
   `{ intent, confidence, matched_rule_id?, should_reply, needs_approval, reason }`.
   `confidence < ai_confidence_threshold` -> escala. JSON invalido -> "nao sei" -> escala/silencia.
3. **Modos por contato (`ai_mode`), do mais seguro ao mais aberto:**
   - `rules_only` — IA nao age (so regras deterministicas). **Default.**
   - `intencao` — IA so CASA regra/intencao existente e responde com a SUA resposta (Fase 1).
   - `conhecimento` — pode responder pela base de conhecimento (so low/medium).
   - `aprovacao` — nunca envia sozinha; sugere e o Fabio aprova.
4. **Casar regra por IA (Fase 1).** Toggle `ai_match_enabled` na regra: "deixe a IA casar
   parecidas". A IA recebe as regras candidatas (gatilhos + frases-exemplo) e devolve QUAL
   intencao casou; **o sistema decide a resposta** (`RuleResponder`, com placeholders/`{senha:}`).
   Frases-exemplo por regra: filha `rule_ai_examples` OU novo tipo de gatilho `ai_intent`
   (**forma a confirmar**).
5. **Base de conhecimento (`knowledge`).** `title`, `content`, escopo de contatos, `sensitivity`
   (low/medium/high), `enabled`. CRITICO: conteudo **high nunca vai ao modelo** — vira placeholder
   local (`{senha:}`) ou aprovacao. So low/medium entram no prompt. Usada so no modo `conhecimento`.
6. **Fila de aprovacao (`pending_approvals`).** `incoming_message_id`, `remote_jid`,
   `original_text`, `suggestion_text`, `intent`, `confidence`, `reason`, `source`, `status`
   (pending/sent/edited/ignored), `decided_at`.

## Parte 3 — Precedencia (no pipeline atual)
Ordem em `avaliarAutoResposta()`, com a IA inserida onde hoje ha `return` por "sem regra"
(`ProcessIncomingWhatsappMessage.php`, apos `RuleMatcher::match` == null):
1. `fromMe` -> ignora. **(existe)**
2. Grupo -> pula. **(existe)**
3. Contato OFF -> nada; DEFAULT -> politica; ON -> segue. **(existe, `contactGate`)**
4. **Sessao de fluxo ativa -> fluxo.** **(existe, `FlowEngine::activeSession`)**
5. Fluxo de entrada / regra por especificidade. **(existe, `entryFlow` / `RuleMatcher::match`)**
6. **NADA casou + IA ligada (global) + `ai_enabled` do contato + portao passa -> despacha
   `ClassifyWithAi` (job na fila).** O job chama o Gemini com backoff e: responde por
   regra/intencao ou base -> despacha `SendAutoReply`; OU escala -> cria `pending_approval`
   (nao envia); OU silencia (baixa confianca/erro/cota).
7. **Qualquer resposta passa pelos MESMOS freios + R2** (sai pelo `Sender`).

Projeto: a chamada ao Gemini roda num **job separado** (`ClassifyWithAi`), nao inline no
recebimento — latencia/429 nao seguram o webhook nem a persistencia. Pre-checagens baratas
(switch global, `ai_enabled`, grupo, portao) ANTES de gastar chamada de API.

## Parte 4 — Camada 5 (aprendizado, configuravel na UI)
- **Pagina nova `/revisao`** (Livewire): o que a IA respondeu (`ai_decisions`) e o que escalou
  (`pending_approvals`).
- **Acoes por pendencia:** Enviar (despacha `SendAutoReply` — passa pelos freios), Editar, Ignorar.
- **"Virar regra":** promove uma resposta boa a regra deterministica (cria `AutoReplyRule` +
  `rule_triggers` + `rule_responses`). Da proxima e gratis e instantaneo (menos API com o tempo).
- **Quem aprende e o Fabio curando.** A IA nao grava regra sozinha.
- **Tudo na UI:** liga/desliga IA (global em `/configuracoes` + por contato em `/contatos`),
  `ai_mode` por contato, limiar de confianca, e temas que exigem aprovacao.

## Parte 5 — Pre-requisitos e seguranca
- **Fabio cria a chave gratis** no Google AI Studio; guardar em `GEMINI_API_KEY` no `.env`
  (chmod 600, gitignored) — nunca em relatorio/commit/log.
- **Minimizacao:** so a mensagem atual + intencoes/base low-med. Sem historico, sem sensivel,
  sem `raw_payload`, sem valores de senha. `{senha:}` nunca expandido antes do modelo.
- **Log de cada decisao** (`ai_decisions`: intent, confidence, acao, motivo) pra revisao/loop.
  O envio segue logando em `auto_reply_logs`.
- **Guarda de sensibilidade:** conteudo `high` e temas de aprovacao nunca respondidos direto —
  placeholder local ou fila.

## Parte 6 — Plano de fatias
- **Fatia 1** — driver Gemini + classificador + `ai_enabled` global + `ai_enabled`/`ai_mode` por
  contato + `ai_match_enabled` na regra + escalar + `ai_decisions`. Testada com MOCK (sem enviar),
  respeitando freios. Default `intencao` conservador.
- **Fatia 2** — base de conhecimento + sensibilidade + resolucao local de valores.
- **Fatia 3** — `pending_approvals` + painel `/revisao` (Enviar/Editar/Ignorar).
- **Fatia 4 (Camada 5)** — "virar regra" + limiar/temas finos na UI + polimento.

## Decisoes da direcao (aprovadas)
- **Modo/limiar padrao:** conservador. Ao ligar a IA num contato, ele nasce em `ai_mode=intencao`
  (so casa suas regras, responde com a sua resposta). Limiar de confianca alto (~0.75).
  Sensivel/baixa confianca -> escala.
- **Sempre exige aprovacao (IA nunca responde direto):** pagamento/PIX/valores; dados
  bancarios/senhas; compromissos/agendamentos; qualquer conteudo `high` da base.
- **Nenhum contato comeca aberto:** `ai_enabled=false` em todos; kill switch global da IA nasce
  OFF. O Fabio liga um contato de cada vez, na UI.

## Schema proposto (aditivo, nada destrutivo — so quando aprovar cada fatia)
- `contacts` += `ai_enabled` (bool, default false), `ai_mode` (default `rules_only`).
- `auto_reply_settings` += `ai_enabled` (bool false), `ai_confidence_threshold` (~0.75),
  `ai_approval_topics` (json).
- `auto_reply_rules` += `ai_match_enabled` (bool false); filha `rule_ai_examples` (a confirmar).
- Tabelas novas: `knowledge`, `pending_approvals`, `ai_decisions`.
- Defaults preservam o comportamento atual (IA OFF).

## Pontos a confirmar
- ~~Id exato do modelo free tier~~ -> **`gemini-2.5-flash-lite`** (GA; configuravel em `GEMINI_MODEL`).
- ~~Forma do "casar por IA"~~ -> **toggle `ai_match_enabled` + `rule_ai_examples`** (sem tipo de gatilho novo).
- ~~`ai_decisions` tabela nova vs. campos em auto_reply_logs~~ -> **tabela nova `ai_decisions`**.
- Transporte HTTP da LAN (mesma ressalva do cofre — a chave nao trafega, mas vale tunel/HTTPS
  quando expor).

---

## Fatia 1 — entregue

**Decisoes fechadas:** casar por IA = toggle na regra + frases-exemplo; modelo Flash-Lite
configuravel (`GEMINI_MODEL=gemini-2.5-flash-lite`); `ai_decisions` tabela nova; default `intencao`,
limiar 0.75; sem fila de aprovacao ainda -> tema sensivel/senha/baixa confianca **silencia e loga
`escalou`** (a fila e a Fatia 3).

**Schema (migrations aditivas `..._000017`.. `..._000020`):**
- `contacts` += `ai_enabled` (bool false) + `ai_mode` ('intencao'; rules_only|intencao|conhecimento|aprovacao).
- `auto_reply_settings` += `ai_enabled` (bool false, kill switch da IA) + `ai_confidence_threshold`
  (0.75) + `ai_approval_topics` (JSON; NULL = todos os 4 temas ligados).
- `auto_reply_rules` += `ai_match_enabled` (bool false); filha `rule_ai_examples` (frases-exemplo).
- `ai_decisions` (account, contact, incoming_message, matched_rule_id, remote_jid, intent,
  confidence, acao respondeu|escalou|silenciou, motivo, model, timestamps).

**Codigo:**
- Contrato `App\Contracts\AiClassifier` + DTOs `App\Ai\AiClassificationRequest` / `AiClassification`
  (`::unknown()` = "nao sei"). Driver `App\Ai\Drivers\GeminiDriver` (endpoint
  `v1beta/models/{model}:generateContent`, chave no header `x-goog-api-key`, JSON forcado por
  `responseSchema`, prompt de sistema TRAVADO, temperatura 0). Config `config/services.php` -> `gemini`.
- 429/5xx/timeout -> backoff exponencial + retry (`GEMINI_MAX_ATTEMPTS`, default 3); esgotou -> unknown.
  4xx (fora 429) -> unknown sem retry. Cota diaria (`GEMINI_DAILY_CAP`, contada em cache por dia SP)
  -> unknown('ia_cota'). Chave vazia -> unknown('sem_chave'). Parse defensivo -> unknown se invalido.
- Job `App\Jobs\ClassifyWithAi` (fila, separado do webhook). Pre-checagens ANTES da API:
  `AntiBanGuard::aiEligible` (kill switch da IA + IA no contato + nao-grupo + portao) e `ai_mode`.
  Candidatas via `RuleMatcher::aiCandidates` (regras com `ai_match_enabled` elegiveis pro contato).
  MINIMIZACAO: pro modelo so a mensagem + gatilhos/exemplos (nunca resposta/segredo). Idempotente
  por `incoming_message_id` em `ai_decisions`.
- Decisao: `respondeu` (despacha `SendAutoReply` com a resposta DA REGRA -> Sender: todos os freios +
  R2 + `{senha}` local) · `escalou` (contem_senha | modo_aprovacao | tema_aprovacao | baixa_confianca)
  · `silenciou` (unknown | sem_regra | modelo_nao_responde). **Guarda dura local: IA nunca auto-envia
  resposta com `{senha:...}`.**
- Pipeline: em `ProcessIncomingWhatsappMessage::avaliarAutoResposta`, quando `RuleMatcher::match`
  retorna null E `aiEligible`, despacha `ClassifyWithAi` (senao, silencio como antes). Fluxos/regras
  vem antes — a IA e fallback de verdade.

**UI (Camada 4):**
- `/configuracoes` — card "IA classificadora": kill switch PROPRIO (modal ao ligar; desligar
  instantaneo) + limiar e temas de aprovacao em leitura (ajuste fino na Fatia 4).
- `/contatos` — no painel do contato: liga IA + escolhe `ai_mode`; badge "IA" na lista.
- `/regras` — no modal: "Permitir a IA casar mensagens parecidas" + frases-exemplo (add/remove).
- Testador (`RuleTester`) — linha da IA no dry-run (sem chamar a API): diz se a IA atuaria e quantas
  candidatas ha quando nada casa deterministicamente.

**Chave/segurança:** `GEMINI_API_KEY` so no `.env` (chmod 600, gitignored). Vazia = a IA nao chama a
API (silencia e loga). Nunca em codigo/commit/log.

**Testes:** `AiFallbackTest` (19) + `GeminiDriverTest` (9), driver e envio MOCKADOS (nunca API/envio
real). Suite completa verde e sequencial.

## Pendente (Fatias 2-4)
- **Fatia 2:** base de conhecimento (`knowledge`) + sensibilidade + valores locais (modo `conhecimento`).
- **Fatia 3:** fila de aprovacao (`pending_approvals`) + painel `/revisao` (Enviar/Editar/Ignorar) —
  hoje o `escalou` so fica logado em `ai_decisions`.
- **Fatia 4 (Camada 5):** "virar regra" + limiar/temas editaveis na UI + polimento.
