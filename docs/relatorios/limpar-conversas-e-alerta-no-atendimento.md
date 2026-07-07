# Limpar conversas + Alerta de servidor no Atendimento — 2026-07-07

Git no início: HEAD `2351bc3` (relatório S4), branch `master`, working tree limpo.
**`APP_ENV=local` confirmado** (DEV `192.168.11.210`). Remote: **`origin →
developedbyfabio/msgautomation_server.git`**. **NÃO houve push.** Produção
`187.127.24.165`/Nextgest/nginx/Tunnel: intocados.

**Baseline: 1053 verdes / 4114 assertions.** **Final: 1068 verdes / 4163 assertions**
(+15: 7 F1 + 8 F2). Zero regressão.

**Commits (separados):**
- Feature 1: **`4a6f4f9`** — limpar todas as conversas (hard delete).
- Feature 2: **`b767b2a`** — alerta de servidor visível no Atendimento.

---

## Inspeção prévia (o que definiu as decisões)

Dois agentes de exploração mapearam o modelo real antes de qualquer código:
- **Conversa não é um model.** É derivada: um `remote_jid` distinto dentro de `account_id`.
  A lista do Atendimento (`Conversas.php`) é montada **exclusivamente de `incoming_messages`
  + `auto_reply_logs`** (status='sent'); `contacts` só enriquecem nome/modo. Logo, apagar as
  mensagens esvazia as conversas mesmo mantendo os contatos.
- **Grafo de FK de `contacts`** (via information_schema): apagar um contato **CASCATEIA**
  para `cards` (Kanban), `campaign_targets`, `contact_tag`, `rule_contacts`,
  `knowledge_contacts`, `proactive_consents`, `contact_channel_windows`, `flow_contacts`.
- **Padrão existente de "ignorar no pipeline" = grupos (`@g.us`)**, replicado ponto a ponto.
  Reusei-o para a conversa de sistema.

---

## FEATURE 1 — Limpar todas as conversas (hard delete)

**Onde:** "Zona de perigo" no fim de **Configurações** (rota já owner-only). Botão "Limpar
conversas" → modal de confirmação com a contagem → "Apagar tudo".

**O que é apagado** (`App\Actions\ClearAccountConversations`, escopo da conta, dentro de
transação):
- `incoming_messages` + `auto_reply_logs` (as duas fontes da conversa) **exceto** o JID de
  sistema.
- Artefatos por-mensagem que ficariam órfãos: `ai_decisions`, `pending_approvals`,
  `unmatched_messages`.

**Decisão de escopo (adaptação consciente registrada):** **PRESERVA `contacts`** (e portanto
Kanban/Campanhas/Regras/opt-in). Motivo: a "conversa" no Atendimento é derivada das
mensagens — apagá-las já faz as conversas sumirem (a lista vai a zero); apagar contatos
**cascatearia** para dados de **outras features** (`cards`, `campaign_targets`, `rule_contacts`,
`knowledge_contacts`, `proactive_consents`), o que o escopo proíbe ("NÃO apagar dados de
outras features"). Resolvi o conflito das duas instruções (o prompt cita "contatos" mas
também "só o que é conversa de atendimento") a favor da restrição mais forte e segura. O modal
diz explicitamente: "os contatos/clientes são preservados". "Limpar conversas" ≠ "apagar
clientes".

**As quatro salvaguardas obrigatórias:**
1. **Confirmação** (modal com a contagem exata; clicar não apaga direto).
2. **Owner-only server-side**: `AreaAccess::authorizeOwnerAction()` no `askClearConversations`
   **e** no `clearConversationsConfirmed` — um operador forjando a ação Livewire toma **403**
   (não é só o botão escondido).
3. **Escopo de conta**: tudo filtrado por `account_id` do `AccountContext`; **nunca cruza**.
4. **Auditoria**: `SystemEvent` `type=conversas, level=warning` com `user_id`, contagem
   (recebidas/enviadas), timestamp — **sem conteúdo** das mensagens.

**A conversa de sistema (F2) é preservada**: o delete exclui `remote_jid = SystemConversation::JID`.

Trecho essencial (`ClearAccountConversations::handle`):
```php
$enviadas = AutoReplyLog::withoutAccountScope()->where('account_id', $accountId)
    ->where('remote_jid', '!=', SystemConversation::JID)->delete();
$recebidas = IncomingMessage::withoutAccountScope()->where('account_id', $accountId)
    ->where('remote_jid', '!=', SystemConversation::JID)->delete();
SystemEvent::withoutAccountScope()->create([... 'detail' => ['user_id'=>..., 'mensagens_apagadas'=>$total] ...]);
```

---

## FEATURE 2 — Alerta de servidor visível no Atendimento

**A conversa de sistema** (`App\Whatsapp\SystemConversation`, introduzida na F1 como
fundação): um contato único por conta "Alertas de Infraestrutura", `remote_jid` **sintético**
`alertas-infra@sistema.msgauto`, marcado `is_system=true` (coluna aditiva em `contacts`,
espelhando `saved`). `ensureContact` é idempotente; `record` grava a mensagem.

**Onde a gravação foi adicionada:** `AlertNotifier::transition` (chamado em **toda** transição
firing/escalada/resolved pela máquina de estado). **Antes** do check do flag → grava em **ambos**
os estados:
```php
public function transition(Incident $incident, string $transition): void {
    $this->registrarNaConversa($incident, $transition); // F2: SEMPRE (mudo ou não)
    if (config('servers.notifications_enabled')) return; // ON: o job SendServerAlert envia
    // OFF: SystemEvent silencioso + marcas (S3 intacto)
}
```
A mensagem vira um `IncomingMessage` `from_me=false, type='conversation'`, texto humano
(severidade, servidor, métrica, partição, valor, disparo/resolução — ex.: `🔴 srv-a: CPU
critical (97%)`), `ref` idempotente `srv-alert:{incident}:{transition}` (unique
instance+evolution_message_id → não duplica). Renderiza como bolha recebida no Atendimento.

**Decisão registrada (gravar com flag OFF):** grava na conversa em **toda transição, mesmo
mudo** (vira histórico visível do que teria sido enviado); o WhatsApp só sai quando o flag
está ON. Recomendação do prompt seguida.

**Envio intacto:** o caminho de envio (`SendServerAlert` → transporte direto
`ProviderRegistry->sendText`) **não mudou**. A F2 só **adiciona** a gravação no notifier.
Provado por teste: com flag ON, o ciclo real ainda faz `Http::assertSent` para o transporte
**e** grava na conversa.

**Isolamento do pipeline (o que protege o produto):**
- **Por construção**: o JID sintético `@sistema.msgauto` nunca chega por um webhook real
  (Evolution só entrega `@s.whatsapp.net`/`@g.us`); e a mensagem é inserida **direto** (sem
  disparar evento de domínio). Logo **matching/FlowEngine/robô nunca a avaliam** — não toquei
  `AntiBanGuard`/`RuleMatcher`/`FlowEngine`/`Sender` (a conversa não chega lá).
- **Exclusão nos pontos que CONSULTAM contatos/mensagens** (espelhando a exclusão de grupos):
  | Ponto | Arquivo | Exclusão |
  |---|---|---|
  | Público de campanha | `AudienceResolver::candidatos` | `->where('is_system', false)` na query base |
  | Seleção de contatos (campanha) | `Campanhas::render` (contactOptions) | `->where('is_system', false)` |
  | Clientes | `Contatos::render` | `->where('is_system', false)` |
  | Métricas (recebidas + mediana) | `PainelMetrics` | `->where('remote_jid', '!=', SystemConversation::JID)` |
  | Kanban | `BoardEngine::apply` + `moveToColumnSlug` | `|| SystemConversation::isSystemJid($jid)` ao lado do `@g.us` |
- **Opt-out/proativa**: o contato de sistema nunca tem opt-in e é barrado no público de
  campanha; não vira opt-out (não aparece na UI de mute nem entra na jaula proativa).
- **Inbox mostra**: `Conversas` NÃO foi filtrada — a conversa de sistema **aparece** no
  Atendimento (é o objetivo). O isolamento é do pipeline, não da visualização.

---

## Testes

**`LimparConversasTest`** (7): owner confirma → mensagens da conta vão a zero (read-back);
**cancelar não apaga**; **isolamento entre contas** (apagar conta A não toca conta B);
**operador 403 na ação** (server-side, nada apagado); **auditoria** `SystemEvent` com user/
contagem e **sem conteúdo**; **preserva contatos + conversa de sistema**; action isolada
(count + handle).

**`ServersAlertaNoAtendimentoTest`** (8): transição **grava mensagem** na conversa de sistema
com texto correto (servidor/métrica/nível) e a resolução também; **contato de sistema
idempotente** (dois alertas → um contato, `is_system=true`); **robô NÃO responde** (grava a
mensagem mas zero `AutoReplyLog` e `Queue::assertNotPushed(SendAutoReply)`); **isolada de
campanha e clientes** (não entra no público nem na lista Clientes); **isolada do Kanban** (não
gera card); **não conta nas métricas** de recebidas; **flag ON envia pelo transporte
(`Http::assertSent`) e grava**; **flag OFF grava mas `assertNothingSent`**.

---

## Ajustes deliberados (um a um)
1. **F1 preserva contatos** (apaga só mensagens + artefatos por-mensagem) — apagar contatos
   cascatearia para outras features (proibido); a lista de conversas é derivada das mensagens.
   Registrado; o modal deixa explícito.
2. **Botão em Configurações** (não Atendimento) — Configurações já é owner-only por rota e é o
   lar natural de uma ação destrutiva ("Zona de perigo").
3. **`is_system` como marcador** (coluna aditiva, espelha `saved`), + JID sintético
   `@sistema.msgauto` para o check barato de hot path (espelho do `@g.us`). Dois mecanismos
   consistentes: flag para queries de contato, sufixo de JID para pontos que só têm o JID.
4. **F2 grava em toda transição, mesmo mudo** (histórico); envio só com flag ON.
5. **NÃO toquei matching/FlowEngine/Sender/ProactiveGuard/AntiBanGuard** — a conversa de
   sistema não chega neles (JID sintético + inserção direta sem evento). Excluí apenas nos
   pontos de **seleção/consulta** (campanha/clientes/métricas/Kanban), que é o que o escopo
   pede ("exclui a de sistema em cada ponto") sem alterar a lógica de automação.
6. **`ref` idempotente** por incidente+transição na gravação da conversa (unique
   instance+evolution_message_id) — reavaliar não duplica a bolha.

## Confirmações finais
- **Produção/Nextgest/nginx/Tunnel intocados.** **matching/FlowEngine/Sender de Campanha/
  billing/Servidores-avaliação: sem diff** (F2 só adiciona a gravação no `AlertNotifier` e
  exclusões nos pontos de seleção; F1 é isolada). O transporte de alerta (`SendServerAlert`)
  **não** foi alterado.
- Migration aditiva (`is_system` em contacts) aplicada e confirmada por leitura (`db:table`).
- **`queue:restart`** executado (2026-07-07 13:40:01) — a F2 toca o `AlertNotifier`, carregado
  pelo worker/scheduler; daemons ativos → recicla.
- Isolamento por conta respeitado nas duas (F1 escopo + teste cross-conta; F2 `account_id`
  explícito na conversa de sistema).
- Suíte **sequencial**: 1053 → 1068 verdes (4114 → 4163 assertions), zero falha.
- Commits `4a6f4f9` (F1) e `b767b2a` (F2), **sem push** (remote `msgautomation_server`).
