# Prompt 16 — Correção das reações (corte na ingestão, sem migration) — 2026-07-03

**Status: ENTREGUE.** Baseline 599 verdes → final **605 verdes** (2260 assertions),
`TenantIsolationTest` verde (28). **Nenhuma migration.** Nenhuma linha deletada. Reação deixa de
virar mensagem de primeira classe — não vira bolha, não conta métrica, não toca IA/Kanban/regras.

## As duas strings de tipo cobertas
- **Evolution:** `reactionMessage` (confirmado no banco — 173 linhas).
- **Cloud API:** `reaction` — confirmado em `CloudApiProvider::normalizeIncoming:142`
  (`$type = (string) ($msg['type'] ?? 'unknown')` passa o `type` do payload direto; a Cloud
  entrega reação como `type: "reaction"`).

Fonte única: `App\Models\IncomingMessage::REACTION_TYPES = ['reactionMessage', 'reaction']`
(`app/Models/IncomingMessage.php`), usada pelo guard, pela métrica e pela thread — um só lugar
pra manter.

## Frente 1 — Corte na ingestão (choke point único, futuro)

**Ponto exato:** `app/Jobs/ProcessIncomingWhatsappMessage.php`, início do `handle`, logo após o
`normalizeIncoming` e o `if ($data === null)` (evento não-mensagem/status), **antes** de resolver
canal/conta e **antes** de `persistir` e de qualquer consumidor:
```php
if (in_array($data->type, IncomingMessage::REACTION_TYPES, true)) {
    Log::debug('Reacao recebida descartada (nao vira mensagem).', [...]);
    return;
}
```
**Por quê aqui:** é o único caminho comum aos dois canais (a normalização já resolveu o `type`
neutro), e é o ponto mais cedo em que o tipo é conhecido — antes de `IncomingMessage::create`
(`:246`), de `popularContato`/evento Kanban (`:124`,`:129`), de `avaliarAutoResposta`/RuleMatcher/
`aiEligible`/`ClassifyWithAi` e da métrica. Cortando aqui, **nenhum consumidor** roda para reação.

**Critério é TIPO EXPLÍCITO, nunca texto vazio** — mídia (imagem/áudio) também tem `text=null` e
NÃO pode ser barrada. O teste `test_midia_com_texto_nulo_continua_criando_mensagem` prova isso.

Observabilidade: um `Log::debug` leve (sem criar `IncomingMessage`, sem `SystemEvent`). Um evento
na página de Logs foi considerado e **não** implementado (superfície extra desnecessária p/ o
corte) — anotado como possível futuro.

## Frente 2 — Métrica (passado + defesa)

`app/Metrics/PainelMetrics.php:77-84` — a base de `recebidas`/`grupos` ganhou
`->whereNotIn('type', IncomingMessage::REACTION_TYPES)`. Neutraliza as 173 linhas históricas na
contagem e defende caso alguma reação escape o corte no futuro. Aditivo, sem migration.

## Frente 3 — Front (passado)

`app/Livewire/Conversas.php`:
- `thread()` (`:494`): a query das bolhas ganhou `->whereNotIn('type', IncomingMessage::REACTION_TYPES)`
  → some as bolhas "reagiu" do histórico das conversas 1:1 antigas.
- lista de conversas (`:389`): mesmo filtro — uma reação histórica não vira "última mensagem" nem
  bumpa a conversa na ordenação (coerente com "reação some do front"). *(Extensão natural da
  Frente 3; documentada aqui.)*

Blade **não** precisou mudar — a thread apenas não inclui os itens de reação; sem espaço/artefato
(o loop só tem menos itens). Nenhuma rebuild de Vite necessária (mudanças são PHP).

## O que NÃO foi tocado (confirmado por `git diff --stat`)
- `RuleMatcher` e `UnmatchedMessage`: intactos (guard de texto vazio deles permanece como estava).
- Envio (`Sender`), download/rota da Fatia 2 (`DownloadIncomingMedia`, `media.incoming`): intactos.
- Pipeline reativo: só o guard de corte foi adicionado (antes de tudo); nada mais mudou.
- Nenhuma migration, nenhum schema, nenhuma deleção das 173 linhas (só filtradas na leitura).

Arquivos alterados: `ProcessIncomingWhatsappMessage.php`, `Conversas.php`, `PainelMetrics.php`,
`IncomingMessage.php` (+ const), e o teste legado `MessagePreviewTest.php`.

## Testes (gate)

Novo `tests/Feature/ReacaoCorteTest.php` (6):
1. Reação Evolution (`reactionMessage`) → **não cria** `IncomingMessage` (0 linhas → nenhum
   consumidor rodou).
2. Reação Cloud (`reaction`) → **não cria** `IncomingMessage`.
3. Texto normal → **continua criando** e sendo processado (não regrediu).
4. **Mídia com texto nulo (imagem) → continua criando** — teste crítico anti-regressão (prova que
   o critério é tipo-reação, não texto-vazio).
5. `PainelMetrics` **não conta** reação (1:1 nem grupo) — inclui reação no cenário e a contagem
   ignora.
6. `Conversas::thread()` **não retorna** reação.

Ajuste de teste legado: `MessagePreviewTest::test_thread_mostra_label_e_emoji` afirmava a bolha de
reação com emoji `👍` — exatamente o comportamento (bugado) que este prompt remove. Passou a
afirmar `assertDontSee('👍')`/`assertDontSee('reagiu')` (mantendo a imagem + legenda). O teste
unitário `MessagePreview::for('reactionMessage', ...)` segue válido (o helper não foi tocado — só
não é mais consumido na thread).

**Suíte completa: 605 verdes** (599 → +6). `TenantIsolationTest`: **28 verdes**.

## Nota (fora de escopo, para decisão futura)
As 173 reações históricas continuam no banco (não deletadas, conforme instruído) e ainda apareceriam
na **página de Logs** ("Mensagens recebidas"), que não filtra tipo e não estava no escopo (Frentes
2/3 eram PainelMetrics e thread). Reações **futuras** não entram lá (corte na ingestão). Se quiser
limpar o histórico dos Logs, é um filtro análogo em `Logs.php` — não aplicado aqui.
