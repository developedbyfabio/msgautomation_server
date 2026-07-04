<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Models\Account;
use App\Models\AutoReplySetting;
use App\Models\Flow;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fatia 1 — fundacao do Modo de Operacao (schema + model), INERTE. Default =
 * comportamento atual (personal). Enum casteado, relationship e nullOnDelete.
 */
class AutoReplySettingModeTest extends TestCase
{
    use RefreshDatabase;

    private function conta(string $nome = 'T'): Account
    {
        $a = Account::create(['name' => $nome]);
        app(AccountContext::class)->set($a->id);

        return $a;
    }

    public function test_default_e_personal_e_default_flow_null(): void
    {
        $a = $this->conta();
        $s = AutoReplySetting::firstOrCreate(['account_id' => $a->id])->fresh();

        $this->assertSame(OperationMode::Personal, $s->operation_mode); // enum, nao string
        $this->assertNull($s->default_flow_id);
        $this->assertNull($s->defaultFlow);
    }

    public function test_cast_do_enum_persiste_e_recarrega_como_enum(): void
    {
        $a = $this->conta();
        $s = AutoReplySetting::firstOrCreate(['account_id' => $a->id]);
        $s->operation_mode = OperationMode::Auto;
        $s->save();

        $fresh = AutoReplySetting::withoutAccountScope()->find($s->id);
        $this->assertInstanceOf(OperationMode::class, $fresh->operation_mode);
        $this->assertSame(OperationMode::Auto, $fresh->operation_mode);
        // coluna guarda o valor backed
        $this->assertSame('auto', \Illuminate\Support\Facades\DB::table('auto_reply_settings')->where('id', $s->id)->value('operation_mode'));
    }

    public function test_relationship_default_flow(): void
    {
        $a = $this->conta();
        $flow = Flow::create(['account_id' => $a->id, 'name' => 'Atendimento', 'enabled' => true, 'scope' => 'global', 'timeout_seconds' => 300]);
        $s = AutoReplySetting::firstOrCreate(['account_id' => $a->id]);
        $s->update(['default_flow_id' => $flow->id]);

        $this->assertTrue($s->fresh()->defaultFlow->is($flow));
    }

    public function test_null_on_delete_do_fluxo_apontado(): void
    {
        $a = $this->conta();
        $flow = Flow::create(['account_id' => $a->id, 'name' => 'Atendimento', 'enabled' => true, 'scope' => 'global', 'timeout_seconds' => 300]);
        $s = AutoReplySetting::firstOrCreate(['account_id' => $a->id]);
        $s->update(['default_flow_id' => $flow->id]);

        $flow->delete();

        $this->assertNull($s->fresh()->default_flow_id); // FK nao fica orfa
    }

    public function test_modo_e_isolado_por_conta(): void
    {
        $a = Account::create(['name' => 'A']);
        $b = Account::create(['name' => 'B']);
        AutoReplySetting::create(['account_id' => $a->id, 'operation_mode' => OperationMode::Auto]);
        AutoReplySetting::create(['account_id' => $b->id, 'operation_mode' => OperationMode::Personal]);

        $this->assertSame(OperationMode::Auto, AutoReplySetting::withoutAccountScope()->where('account_id', $a->id)->first()->operation_mode);
        $this->assertSame(OperationMode::Personal, AutoReplySetting::withoutAccountScope()->where('account_id', $b->id)->first()->operation_mode);
    }
}
