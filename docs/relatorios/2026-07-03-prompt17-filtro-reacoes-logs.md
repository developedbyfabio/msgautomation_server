# Prompt 17 — Filtrar reações do histórico na página de Logs — 2026-07-03

**Status: ENTREGUE.** Baseline 605 verdes → final **606 verdes** (2264 assertions),
`TenantIsolationTest` verde (28). Sem migration, sem deleção — só filtro de leitura.

## Ponto exato do filtro
`app/Livewire/Logs.php:83` — na consulta que lista **mensagens recebidas** (ramo
`if ($quer('recebida'))`), adicionei antes do `orderByDesc`:
```php
->whereNotIn('type', IncomingMessage::REACTION_TYPES)
```
Reusa a **fonte única** `IncomingMessage::REACTION_TYPES` (`['reactionMessage', 'reaction']`)
criada no prompt 16 — não recriei a lista de tipos. Mesmo padrão das Frentes 2 e 3 do prompt 16.

As 173 reações históricas continuam no banco (não deletadas); apenas não são listadas. Reações
novas nem chegam a virar `IncomingMessage` (corte na ingestão, prompt 16).

## Escopo confirmado — só a listagem de recebidas
O filtro está **exclusivamente** no ramo de `IncomingMessage` (recebidas). As demais visões da
página de Logs são de outros models/queries e ficaram **intactas**:
- **enviadas** (`envio_ok`/`envio_falhou`): `AutoReplyLog` (`Logs.php:137,142`) — não tocado.
- **status FAILED da Meta**: `SystemEvent` — não tocado.
- **sem_match / unmatched**: `UnmatchedMessage` — não tocado.
- **eventos de canal / erro_sistema**: `SystemEvent` — não tocado.
Reação só aparecia onde `IncomingMessage` é listada, então só esse ramo precisava do filtro.

## Não tocado
Envio (`Sender`), download/rota da Fatia 2, pipeline reativo, `RuleMatcher`, `UnmatchedMessage`.
`git diff --stat`: apenas `app/Livewire/Logs.php` (+3) e `tests/Feature/LogsPageTest.php` (+30).

## Teste
`LogsPageTest::test_reacao_nao_aparece_na_listagem_de_recebidas`: cria uma mensagem de texto
normal (aparece), uma reação Evolution (`reactionMessage`) e uma reação Cloud (`reaction`) — as
duas reações NÃO aparecem na listagem (`->set('tipo', 'recebida')`), o texto continua aparecendo.
Cobre os dois tipos da fonte única. Padrão reaproveitado de `ReacaoCorteTest`/`LogsPageTest`.

**Suíte completa: 606 verdes** (605 → +1). `TenantIsolationTest`: **28 verdes**.
