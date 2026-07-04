# Fatia 1 — Migration aditiva do Modo de Operação (schema + model, INERTE) — 2026-07-04

**Status: ENTREGUE.** Baseline 670 → **675 verdes** (+5, 2532 assertions), `TenantIsolationTest` 28.
Migration **aditiva** aplicada em produção (forward). Colunas **inertes**: nenhum ponto do pipeline
as lê. Comportamento idêntico ao atual.

## Git no início
`working tree clean`, HEAD `3f87520`. Só arquivos novos/model nesta fatia.

## Passo 3.1 — Padrão encontrado e decisão de tipagem
- **Não existe `app/Enums`** no projeto (criado agora).
- **`reply_policy`**: `string(16)` default `'allowlist'` (migration `..000007`), no `$fillable`, **sem
  cast** (string crua). **`media_autodownload`**: boolean, fillable + cast boolean.
- **Divergência registrada:** `reply_policy` é string crua (sem enum). O prompt pediu **explicitamente**
  criar `App\Enums\OperationMode` (backed string) e castear `operation_mode` a ele. Segui o prompt
  (padrão novo, mais limpo), **mantendo a coluna como `string(16)` default `'personal'`** — 100%
  compatível: o DB guarda `'personal'|'auto'`, o cast expõe o enum em PHP. Ou seja, coluna no mesmo
  estilo de `reply_policy`; o enum é só a camada de leitura no model.

## Enum
`app/Enums/OperationMode.php` — backed string, `Personal = 'personal'`, `Auto = 'auto'`, + `label()`
('Pessoal'/'Automatico', p/ a UI da fatia 2). Sem convenção prévia de enums → mínimo + label.

## Migration
`database/migrations/2026_07_04_100000_add_operation_mode_to_auto_reply_settings.php` (aditiva):
```php
// up()
$table->string('operation_mode', 16)->default('personal')->after('reply_policy');
$table->foreignId('default_flow_id')->nullable()->after('operation_mode')
    ->constrained('flows')->nullOnDelete();
// down() (existe p/ reversibilidade; NAO executado)
$table->dropForeign(['default_flow_id']);
$table->dropColumn(['operation_mode', 'default_flow_id']);
```
`flows` já existe (migration `..000016`, anterior) — FK ok. Rodei **só** `php artisan migrate --force`
(forward, foreground); **nenhum** rollback/fresh/reset.

## Model `AutoReplySetting` (diff resumido)
- `use App\Enums\OperationMode;`
- `$fillable`: + `'operation_mode'`, `'default_flow_id'` (settings de negócio da conta, como
  `reply_policy`/`media_autodownload` — fillable, não é flag de privilégio).
- `$casts`: + `'operation_mode' => OperationMode::class`.
- Relationship: `defaultFlow(): BelongsTo` → `belongsTo(Flow::class, 'default_flow_id')`.
- **Nenhum** accessor/scope que altere leitura no pipeline. Só cast + relationship.

## SHOW CREATE TABLE (trecho das colunas novas)
```
`operation_mode` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal',
`default_flow_id` bigint unsigned DEFAULT NULL,
KEY `auto_reply_settings_default_flow_id_foreign` (`default_flow_id`),
CONSTRAINT `auto_reply_settings_default_flow_id_foreign` FOREIGN KEY (`default_flow_id`)
    REFERENCES `flows` (`id`) ON DELETE SET NULL
```

## Testes
`tests/Feature/AutoReplySettingModeTest.php` (5):
- default = `OperationMode::Personal` + `default_flow_id` null (comportamento atual preservado);
- cast do enum persiste/recarrega como enum (e a coluna guarda `'auto'`);
- `defaultFlow` relationship retorna o fluxo;
- **nullOnDelete**: apagar o fluxo apontado → `default_flow_id` vira null (sem FK órfã);
- modo isolado por conta (A=Auto, B=Personal não se cruzam).

## Contagem de testes
- **Antes:** 670 verdes / 2522 assertions.
- **Depois:** 675 verdes / 2532 assertions (+5 testes). `TenantIsolationTest` 28 verde.

## Confirmação explícita
**Nenhum ponto do pipeline lê `operation_mode`/`default_flow_id` nesta fatia.** `grep` por
`operation_mode|default_flow_id|OperationMode|defaultFlow` fora do model/enum/teste = **vazio**. As
colunas estão inertes; a leitura no ingest (`$rule === null`) é a **fatia 4**. Toggle (fatia 2) e
seleção do fluxo padrão (fatia 3) também não fazem parte desta fatia.

## Commit
Commit local feito (mensagem clara); **sem `git push`** (o Fabio empurra). Hash abaixo.
