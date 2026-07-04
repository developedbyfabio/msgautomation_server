# Fatia 4 — Catch-all no pipeline + política efetiva no gate (o modo GANHA VIDA) — 2026-07-04

**Status: ENTREGUE.** Baseline 684 → **696 verdes** (+12, 2599 assertions), `TenantIsolationTest` 28.
Zero migration. Duas mudanças cirúrgicas no hot path, **ambas atrás de `operation_mode===Auto`**;
personal byte-idêntico (toda a suíte pré-existente de ingestão/regras/fluxos verde sem alteração).

## Git no início
`working tree clean`, HEAD `f5db38f` (fatia 3).

## 3.1 — Código real re-lido (confirmações)
- Assinaturas confirmadas no código: `contactGate(int $accountId, string $jid, AutoReplySetting $settings): GuardDecision`
  (privado; consumido por `contactGatePasses` **e** por `volatileRecheck` — o re-check R2 do Sender);
  `$flows->start(int $accountId, Flow $flow, string $jid): array`; `$flows->entryFlow(accountId, text, jid)`;
  `dispatchFlowReply(Account, Channel, IncomingMessage, array $res, AntiBanGuard)` → `SendAutoReply::dispatch($message->id, null, $text, true, accountId:)` com delay.
- **Settings da conta do payload:** o job resolve `$account` pelo **canal da instância/token do payload**
  e o catch-all lê `$guard->settingsFor($account->id)` (`withoutAccountScope()->firstOrCreate` com
  `account_id` explícito) — **nunca AccountContext de request**. O relationship `defaultFlow` resolve
  sob o contexto que o próprio job seta para a conta do canal (`app(AccountContext)->set($channel->account_id)`
  no início do handle) — defesa em profundidade sobre a validação de posse da fatia 3.

### Snippet ANTES — gate (`AntiBanGuard::contactGate`, :348)
```php
$policy = $settings->reply_policy ?: 'allowlist';
if ($policy === 'allowlist' && $mode !== 'on') {
    return GuardDecision::block('nao_aprovado');
}
```

### Snippet ANTES — pipeline (ramo `$rule === null` do `avaliarAutoResposta`)
```php
$rule = $matcher->match($account->id, $channel->id, $data->text, $jid);
if ($rule === null) {
    if ($guard->aiEligible($account->id, $jid)) {
        ClassifyWithAi::dispatch($message->id, $account->id);
    } elseif ($guard->contactGatePasses($account->id, $jid)) {
        \App\Models\UnmatchedMessage::record($account->id, $jid, $data->text);
    }
    return;
}
```

## Mudança 1 — política efetiva no gate (DEPOIS)
`app/Whatsapp/AutoReply/AntiBanGuard.php::contactGate`:
```php
$policy = $settings->operation_mode === \App\Enums\OperationMode::Auto
    ? 'all'
    : ($settings->reply_policy ?: 'allowlist');
```
- **Ponto único:** `contactGate` é consumido por `contactGatePasses` (ingest: regra, fluxo-de-entrada,
  catch-all, unmatched) **e** por `volatileRecheck` (re-check R2 no Sender, na hora do envio) — a
  política efetiva vale **consistentemente em todos os caminhos**, sem duplicar nada.
- **Não duplica throttle:** os tetos não passam por esse gate (vivem em `rateOrCooldown`/tetos do
  Sender) — intocados. Mute (`off`) é checado **antes** da política — intocado.
- Em personal: expressão idêntica à anterior (`reply_policy ?: 'allowlist'`).
- Nota registrada: o `breakdown()` (display do testador de regras, :~204) segue mostrando a
  `reply_policy` **crua** — divergência **cosmética** em modo auto (o rótulo "Politica de resposta"
  não reflete o override). Não altera comportamento; refinamento futuro.

## Mudança 2 — catch-all no ramo `$rule === null` (DEPOIS)
`app/Jobs/ProcessIncomingWhatsappMessage.php::avaliarAutoResposta`:
```php
if ($rule === null) {
    $settings = $guard->settingsFor($account->id);
    if ($settings->operation_mode === \App\Enums\OperationMode::Auto) {
        $defaultFlow = $settings->defaultFlow;
        if ($defaultFlow !== null && $defaultFlow->enabled && $guard->contactGatePasses($account->id, $jid)) {
            $this->dispatchFlowReply($account, $channel, $message, $flows->start($account->id, $defaultFlow, $jid), $guard);
            return;
        }
    }
    // comportamento atual PRESERVADO (IA elegível / UnmatchedMessage / silêncio)
    ...
}
```
- **Mesmo molde do passo 3** (fluxo-de-entrada): `start` + `dispatchFlowReply` → o envio passa pelo
  Sender/freios/delay como qualquer fluxo. Nenhum caminho novo de envio.
- Sessão ativa nunca colide (o passo 2 retorna antes); após o `start`, a próxima mensagem cai no
  `advance` (menu). Catch-all **precede a IA** (decisão 5); sem fluxo válido/habilitado ou gate
  bloqueado → **fall-through idêntico** ao atual (degradação graciosa).

## Personal byte-idêntico — como os testes provam
- `operation_mode` default `personal` ⇒ o gate usa a expressão antiga e o catch-all nem entra no if.
- **Toda a suíte pré-existente** (ingestão `AutoReplyWireTest`/`AutoReplySendTest`/`UnmatchedMessagesTest`,
  regras, fluxos `FlowPipelineTest`, `TenantIsolationTest`) roda em personal/default e permaneceu
  verde **sem nenhuma alteração**. + teste explícito `test_personal_sem_match_continua_silencio_total`.
- Única sobrecarga em personal: um `settingsFor` a mais no ramo unmatched (query leve, sem efeito).

## Matriz de testes (`tests/Feature/AutoModeCatchAllTest.php`, 12 — pipeline real: job inline, queue sync, Sender contra Http::fake)
| Caso | Prova |
|---|---|
| Personal sem match | silêncio total (0 sessão, 0 log, 1 unmatched) |
| Auto + fluxo padrão | sessão do fluxo criada + menu **enviado** (`auto_reply_logs sent` com o texto do root) |
| Auto + regra casa | regra responde; **0 sessão** (catch-all não dispara) |
| Auto + sessão ativa | mensagem avança a sessão da ENTRADA; nenhuma sessão do padrão |
| Auto + trigger de entrada | sessão do fluxo de ENTRADA (não o padrão) |
| Auto sem default_flow | fall-through (unmatched), sem quebrar |
| Auto + fluxo desabilitado | fall-through, sem quebrar |
| Auto + allowlist + desconhecido | **catch-all dispara** (prova o override da allowlist) |
| Auto + contato `off` | mudo (mute respeitado) |
| Auto + grupo | sem resposta (passo 1) |
| Auto + teto/minuto estourado | `start` acontece mas o envio é **blocked** no Sender (mesmo freio dos demais caminhos; nada `sent`) |
| Isolamento A(auto)/B(personal) | B silencia, A dispara; nada vaza |

**Achado do motor (registrado):** fluxo cujo root é `menu` **sem opções** é terminal — a sessão nasce
`completed` (responde uma vez e encerra). Para manter sessão ativa (menu real), o fluxo precisa de
opções. O teste de precedência foi construído com opções por isso. Implicação de produto: um fluxo
padrão sem opções vira um "auto-reply de saudação" (responde a cada mensagem sem prender sessão) —
comportamento coerente, sem mudança de código.
**Semântica do throttle (registrada):** com teto estourado, a sessão do menu ainda é criada no ingest
e o envio é bloqueado no Sender — idêntico ao que acontece com regras hoje (freio no envio, não no
ingest).

## Tetos anti-ban atuais (para a Fatia 6 — NÃO aplicados)
Defaults do schema: `min_interval=30s`, `per_minute_cap=4`, `per_day_cap=40`, `contact_rate=1800s`,
janela `08:00–20:00`, `warmup=off`. **Conta 1 (produção, ajustada pelo Fabio):** `min_interval=1s`,
`per_minute=4`, `per_day=40`, `contact_rate=3s`, janela 08–20, warmup off.
**Risco em modo auto (clínica):** `per_day=40` estoura em ~40 clientes/dia; `per_minute=4` engasga
rajadas de menu (cada navegação = 1 envio). **Recomendação p/ Fatia 6 (número oficial/Cloud, sem
risco de ban):** `per_minute 20–30`, `per_day 500–1000`, `min_interval 0–1s`, `contact_rate 0–3s`
(fluxo já isenta), janela conforme horário do estabelecimento (ou 24h), warmup off. Para Evolution
(não-oficial) manter mais conservador: `per_minute 8–10`, `per_day 150–200`. Apenas recomendação.

## Contagem
Antes: **684 verdes / 2571 assertions**. Depois: **696 verdes / 2599 assertions** (+12).
`TenantIsolationTest` 28. Suíte sequencial, zero regressão.

## Fora desta fatia (conforme instruído)
Confirmação forte no toggle (4b), tuning dos tetos (6), nó de handoff (5) — não implementados.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
