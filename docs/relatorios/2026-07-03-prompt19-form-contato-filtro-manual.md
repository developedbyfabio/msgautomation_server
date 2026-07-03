# Prompt 19 — Form de adicionar contato + filtrar Contatos por "meus contatos" — 2026-07-03

**Status: ENTREGUE.** Baseline 606 verdes → final **614 verdes** (2283 assertions),
`TenantIsolationTest` verde (28). **Sem migration** — reusa o boolean `saved`. Nada deletado.

## Passo 1 — Achados (confirmados antes de codar)
1. **Campo/formato do número:** o número mora em `contacts.remote_jid` no formato JID
   `"<digitos com DDI>@s.whatsapp.net"`. É a chave de dedup do `popularContato`
   (`firstOrNew(['account_id','remote_jid'])`). O form grava no MESMO campo/formato.
2. **Helper BrWaId:** `app/Channels/CloudApi/BrWaId.php` — `comNonoDigito`/`semNonoDigito`/
   `paraEnvio` (celular BR sempre COM o 9 no envio). O form **reusa** esse helper (não
   reimplementa). A estratégia de dedup do sistema (visto em `CloudApiProvider::canonicalJid`) é
   "adotar a variante do 9º dígito que JÁ existe" — o form segue a mesma ideia.
3. **`saved`:** setado em `Conversas::saveContact` via `updateOrCreate(..., ['saved' => true])`.
   O form segue o mesmo padrão (grava `saved = true`).
4. **`popularContato` deduplica:** SIM — `Contact::firstOrNew` por `account_id + remote_jid`; mesma
   mensagem/número não cria duplicado. Confirmado.

## Passo 2 — Form de adicionar contato
- Botão **"Adicionar contato"** no cabeçalho da página de Contatos; abre um **`x-modal`** (a
  convenção do app — Flux modal é Pro) com **Nome** e **Número**.
  `app/Livewire/Contatos.php`: `openAdd`/`cancelAdd`/`saveNew` + helper privado
  `canonicalizarNumero`. Blade: `resources/views/livewire/contatos.blade.php`.
- **Canonicalização** (`canonicalizarNumero`): tira não-dígitos; se for BR local (10-11 dígitos sem
  DDI) prefixa `55`; valida BR full (12 fixo / 13 celular) — fora disso, número inválido (mensagem
  clara); aplica **`BrWaId::paraEnvio`** (celular sempre com o 9 → casa com o que a Evolution
  entrega e com o `canonicalJid` do Cloud).
- **Dedup/adoção POR CONTA:** monta as variantes do 9º dígito (`paraEnvio` + `comNono` + `semNono`),
  vira JIDs e busca no escopo da conta ativa. Se existe → **adota** (marca `saved = true`, atualiza
  o nome só se informado — nunca sobrescreve com vazio); **não duplica**. Se não existe → cria novo
  com `saved = true`, nome e JID canônico, escopado à conta.
- Validação: nome e número obrigatórios; número precisa ser canonicalizável.

## Passo 3 — Filtro da listagem
`app/Livewire/Contatos.php` (render): a query da listagem ganhou **`->where('saved', true)`**.
Passam a aparecer os criados pelo form e os auto já nomeados (ambos `saved=true`); auto
nunca-tocados (`saved=false`) não aparecem AQUI.

## GATE confirmado — `saved=false` vive fora da página de Contatos
O filtro está **exclusivamente** no `render()` da página de Contatos. Contatos `saved=false`
continuam vivos e visíveis no resto do sistema — provado por teste:
`test_6_gate_saved_false_visivel_fora_da_pagina_de_contatos` cria um contato auto (`saved=false`)
com mensagem recebida, confirma que **não** aparece em Contatos, mas **aparece** nas Conversas.
Kanban/Painel/pipeline reativo consultam `Contact` sem esse filtro — intocados. Nada deletado.

## Testes (614 verdes, +8)
`tests/Feature/ContatoManualTest.php` (8): número inédito cria saved=true e lista; número já-auto
não duplica e vira saved=true; formato solto (com/sem 9º) casa a variante (reusa BrWaId); auto
saved=false não lista; auto saved=true lista; **gate** (saved=false visível nas Conversas); ciclo
manual→mensagem = um único contato; **isolamento** (mesmo número em 2 contas, dedup por conta).

**Ajuste de testes legados** (asseravam o comportamento antigo "lista todos"): `TenantIsolationTest`
e `MultiUserTest` criavam `Cliente-da-A/B` sem `saved` e afirmavam vê-los na página de Contatos —
marquei-os `saved => true` no setup (representam "meus contatos"; `saved` não afeta nenhuma outra
lógica, a asserção de isolamento/escopo segue valendo).

**Suíte completa: 614 verdes.** `TenantIsolationTest`: **28 verdes**.

## Checklist manual (Fabio)
- [ ] Botão "Adicionar contato" abre o modal; nome+número obrigatórios.
- [ ] Número em formato solto (com/sem 9, com máscara/DDI) salva e casa com mensagens.
- [ ] Adicionar número que já conversou não duplica (adota o existente).
- [ ] A lista mostra só "meus contatos" (criados/nomeados); auto puro some da lista.
- [ ] Auto puro continua nas Conversas/Kanban normalmente.
