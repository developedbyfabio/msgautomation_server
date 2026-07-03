# Prompt 18B — Contatos só manuais — INVESTIGAÇÃO / PARADA — 2026-07-03

**Status: PARADO na investigação (regra de parada acionada).** Nenhuma migration, nenhum filtro,
nenhuma mudança de código. Baseline permanece 606 verdes.

## Regra de parada acionada
O passo 4 pergunta: **existe hoje um jeito de adicionar contato manualmente na UI?**
**Resposta: NÃO.** Logo, conforme a instrução ("Se não existir criação manual de contato na UI,
PARE aqui"), não apliquei migration nem filtrei a listagem — filtrar `manual = true` deixaria a
página de Contatos **vazia** e sem como popular (todos os contatos hoje são auto). Adicionar o form
de criação é a fatia seguinte.

## Achados da investigação (só leitura)

**1. Model/tabela:** `App\Models\Contact` / tabela `contacts`. Campos relevantes (fillable):
`account_id, remote_jid, push_name, auto_reply_mode, notes, saved, ai_enabled, ai_mode,
proactive_opt_in`.

**2. Onde o contato "auto" nasce:** confirmado — `ProcessIncomingWhatsappMessage::popularContato`
(`app/Jobs/ProcessIncomingWhatsappMessage.php:278`, `Contact::firstOrNew([...])` + `save()`), a
partir de mensagem individual recebida (exclui grupo/fromMe). **Todo** contato do sistema origina-se
daqui — não há outra porta de entrada de contato novo.

**3. Campo de origem:** o Contact **não tem** um campo de origem próprio (tipo `manual`/`origin`).
Existe um boolean **`saved`** ("true = nomeado/adicionado pelo usuario (S4)"), mas ele é setado só
pelo painel de Conversas em contato **já existente** (auto) — ver item 4; não marca "criado
manualmente do zero".
O **padrão de origin tracking** que já existe é nas **tags** (pivô `contact_tag` com
`origin`/`origin_ref`: `manual | board_rule | ai_intent` — `app/Models/Contact.php:41-45`). É o
padrão a seguir SE/quando formos marcar origem do contato (ex.: coluna `manual` boolean default
false, backfill automático = todos auto). Mas isso fica para a próxima fatia.

**4. Criação manual de contato na UI — NÃO existe.** Varredura completa:
- **Página de Contatos** (`Contatos.php` + `contatos.blade.php`): métodos são `setMode`,
  `confirmMute`, `startEdit`/`saveEdit` (editar existente), `openTags`/`saveTags`,
  `deleteTag`, `tagUsage`. **Nenhum** método de criação; o blade só tem "Editar", "Silenciar" e
  "Gerenciar tags" — **sem** botão/form "Adicionar contato" / "Novo contato".
- **Painel de Conversas** (`Conversas::saveContact`, `:78/92/128/201`): usa `updateOrCreate` por
  `remote_jid` de uma conversa que **já existe** (veio de mensagem recebida) — marca `saved=true`,
  nome e notas. É **edição de um contato auto**, não criação de contato novo do zero.
- `popularContato` é a única criação real (auto).

Conclusão: não há fluxo "digitar número/nome e criar contato" em lugar nenhum da UI.

## O que NÃO foi feito (por decisão da regra)
- Nenhuma migration (o campo `manual` proposto NÃO foi criado).
- `popularContato` intocado.
- Listagem de Contatos NÃO filtrada.
- `saved`/`origin` das tags: apenas documentados como padrão a reaproveitar na próxima fatia.

## Recomendação para a próxima fatia (adicionar o form + filtro)
1. Form de criação na página de Contatos (digitar número + nome), gravando `manual = true`.
2. Migration aditiva `contacts.manual` boolean default false (backfill: todos existentes viram
   `false`/auto — correto). Seguir o espírito do origin tracking das tags.
3. `popularContato` continua sem tocar (default false = auto).
4. Filtrar a listagem por `manual = true` **só na página de Contatos**; Kanban/Conversas/Painel/
   reativo continuam enxergando todos.
Opcional a discutir: se "curado pelo usuário" (o `saved=true` já existente) for aceitável como
critério de exibição, dá pra reaproveitar `saved` — mas semanticamente é "nomeado", não "criado
manualmente"; a decisão fica para o Fabio.

Baseline intacto: **606 verdes** (nada mudou nesta fatia).
