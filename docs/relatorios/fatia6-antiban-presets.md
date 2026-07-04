# Fatia 6 — Tetos anti-ban do Modo Automático (freios editáveis + presets opt-in) — 2026-07-04

**Status: ENTREGUE.** Baseline 702 → **709 verdes** (+7, 2665 assertions), `TenantIsolationTest` 28.
Zero migration. **Lógica de throttle/anti-ban INTOCADA** (`AntiBanGuard`/`Throttle`/Sender sem 1 linha
de diff — só valores/validação/UI). **Defaults inalterados.** Tetos **não** mudam ao ligar o toggle
(preset é opt-in explícito).

## Git no início
`working tree clean`, HEAD `251b715` (fatia 4b).

## O que já era editável (achado do 3.1)
**Tudo.** O card de freios do `Configuracoes` já expunha, editáveis e persistidos por conta (mesmo
resolver `firstOrCreate(['account_id' => AccountContext::id()])`):
`min_interval_seconds`, `per_minute_cap`, `per_day_cap`, `contact_rate_seconds` (inputs number com
toggles liga/desliga por freio), `window_start`/`window_end` (time), `warmup_enabled` (checkbox),
`delay_min/max`. Nada precisou ser exposto — objetivo 1 já estava atendido; só **reforcei a validação**.

## Colunas e semântica do warmup (achado IMPORTANTE, registrado)
Colunas em `auto_reply_settings`: `per_minute_cap`, `per_day_cap`, `min_interval_seconds`,
`contact_rate_seconds`, `window_start/end` (+ `*_enabled` por freio), `warmup_enabled` (boolean).
**`warmup_enabled` é flag DORMENTE:** grep global — **nenhum leitor** na lógica (`AntiBanGuard`,
`Throttle`, `Sender`); só fillable/cast e o checkbox da UI. Ou seja, "warmup ligado" no preset
Evolution **grava a intenção** mas **não tem efeito de runtime hoje** (a rampa nunca foi
implementada). Não parei a fatia por isso (setar um boolean armazenado é inofensivo e coerente), mas
**fica a decisão pro Fabio**: implementar a rampa (fatia própria) ou remover a flag da UI.

## Valores exatos dos presets (dentro das faixas aprovadas)
| Campo | Cloud API (oficial) | Evolution (WhatsApp Web) |
|---|---|---|
| `per_minute_cap` | **25** | **9** |
| `per_day_cap` | **750** | **175** |
| `min_interval_seconds` | **1** | **3** |
| `contact_rate_seconds` | **2** | **5** |
| `warmup_enabled` | **false** | **true** (dormente — ver acima) |

`Configuracoes::ANTIBAN_PRESETS` + `aplicarPreset('cloud'|'evolution')`: **grava** na
`auto_reply_settings` da conta ativa (resolver isolado) e sincroniza os inputs — que **seguem
editáveis** (ponto de partida, não trava). Preset desconhecido = no-op.

## Validação (limites de sanidade escolhidos)
Em `rules()` (só validação de entrada; defaults e throttle intocados):
- `per_minute_cap`: 1–**60** · `per_day_cap`: 1–**5000** · `min_interval_seconds`: 0–**3600** ·
  `contact_rate_seconds`: 0–**86400** · `window_end`: agora **`after:window_start`** (janela coerente).
Calibrados acima de qualquer uso real nos testes/produção (maior valor em teste de save: 25).

## UI (card de freios)
Bloco "Presets do modo automatico" acima da grade de tetos: **orientação** ("Evolution é WhatsApp
Web, mais sujeito a ban — valores conservadores; Cloud API é oficial, tolerante — valores mais
altos; os tetos valem pra conta inteira; mais alto = mais responsivo, maior risco no Evolution") +
os **2 botões** de preset + o **hint opcional** (âmbar): aparece quando `operation_mode=Auto` e
`per_day_cap ≤ 50` ("tetos baixos — considere aplicar um preset"); só aviso, não bloqueia.

## Confirmações
- **Defaults não mudaram:** teste `test_defaults_inalterados_para_conta_nova` (4/40/30/1800, warmup
  off, Personal) — nenhuma migration/`firstOrCreate` alterada.
- **Lógica de throttle não tocada:** `git status` = só `Configuracoes.php` + blade + teste novo;
  toda a suíte de anti-ban/throttle/ingestão existente verde sem alteração.
- **Toggle não mexe em tetos:** `OperationModeToggle` sem diff nesta fatia.

## Testes (`tests/Feature/AntiBanPresetTest.php`, 7)
Preset Cloud grava 25/750/1/2/warmup-off; preset Evolution 9/175/3/5/warmup-on; campos seguem
editáveis após preset (edição manual persiste por cima); validação rejeita 0/negativo, typo absurdo
(99999; 500/min) e janela invertida — **sem persistir nada**; **isolamento** (preset em A não altera
os defaults de B); defaults de conta nova inalterados; hint só em auto+tetos baixos (some em personal).

## Contagem
Antes: **702 verdes / 2627 assertions**. Depois: **709 verdes / 2665 assertions** (+7).
`TenantIsolationTest` 28. Suíte sequencial, zero regressão.

## Commit
Local, sem push (Fabio empurra). Hash reportado na resposta.
