<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Whatsapp\AutoReply\RuleTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * S4 — testador (dry-run). Mostra match/resposta/bloqueio SEM enviar nem mexer
 * em contadores.
 */
class RuleTesterTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 9, 0, 0, 'America/Sao_Paulo')); // bom dia
        Http::preventStrayRequests();
        $this->account = Account::create(['name' => 'Teste']);
        Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(): AutoReplyRule
    {
        $rule = AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'wifi',
            'response_text' => '{saudacao}, {nome}! A senha e 1234', 'enabled' => true, 'priority' => 0,
        ]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'wifi']);
        $rule->responses()->create(['response_text' => '{saudacao}, {nome}! A senha e 1234']);

        return $rule;
    }

    private function tester(): RuleTester
    {
        return app(RuleTester::class);
    }

    public function test_casa_e_resolve_resposta_com_placeholders(): void
    {
        $rule = $this->rule();
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);

        $r = $this->tester()->test($this->account->id, null, 'qual a senha do wifi?', $contact->id);

        $this->assertTrue($r['matched']);
        $this->assertSame($rule->id, $r['rule_id']);
        $this->assertSame('Bom dia, Joao! A senha e 1234', $r['resposta']);
        $this->assertNull($r['bloqueio']);
    }

    public function test_rotulo_de_tipo_em_pt_br(): void
    {
        $this->assertSame('Contem', \App\Whatsapp\AutoReply\RuleMatcher::typeLabel('contains'));
        $this->assertSame('Mensagem exata', \App\Whatsapp\AutoReply\RuleMatcher::typeLabel('exact'));
        $this->assertSame('Comeca com', \App\Whatsapp\AutoReply\RuleMatcher::typeLabel('starts_with'));
        $this->assertSame('Regex (avancado)', \App\Whatsapp\AutoReply\RuleMatcher::typeLabel('regex'));

        $this->rule();
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);
        $r = $this->tester()->test($this->account->id, null, 'wifi', $contact->id);
        $this->assertStringStartsWith('Contem:', (string) $r['trigger']);
    }

    public function test_sem_match(): void
    {
        $this->rule();

        $r = $this->tester()->test($this->account->id, null, 'mensagem qualquer sem gatilho', null);

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['matched']);
    }

    public function test_reporta_freio_kill_switch(): void
    {
        $this->rule();
        AutoReplySetting::where('account_id', $this->account->id)->update(['enabled' => false]);
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);

        $r = $this->tester()->test($this->account->id, null, 'wifi', $contact->id);

        $this->assertTrue($r['matched']);
        $this->assertSame('kill_switch', $r['bloqueio']);
    }

    public function test_reporta_freio_contato_nao_aprovado(): void
    {
        $this->rule();
        AutoReplySetting::where('account_id', $this->account->id)->update(['reply_policy' => 'allowlist']);
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'default']);

        $r = $this->tester()->test($this->account->id, null, 'wifi', $contact->id);

        $this->assertSame('nao_aprovado', $r['bloqueio']);
    }

    private function freio(array $r, string $labelLike): ?array
    {
        foreach ($r['freios'] ?? [] as $f) {
            if (str_contains($f['label'], $labelLike)) {
                return $f;
            }
        }

        return null;
    }

    public function test_breakdown_lista_todos_os_freios(): void
    {
        $this->rule();
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);

        $r = $this->tester()->test($this->account->id, null, 'wifi', $contact->id);

        $this->assertNotEmpty($r['freios']);
        // Cada freio esperado aparece no quadro.
        foreach (['fromMe', 'Idempotencia', 'Pular grupos', 'Aprovacao do contato', 'Politica', 'kill switch', 'Janela', 'Intervalo por contato', 'Cooldown da regra', 'Intervalo minimo', 'Teto / minuto', 'Teto / dia'] as $label) {
            $this->assertNotNull($this->freio($r, $label), "freio ausente: {$label}");
        }
        // Estruturais sempre passam.
        $this->assertSame('passa', $this->freio($r, 'fromMe')['status']);
        $this->assertSame('passa', $this->freio($r, 'Idempotencia')['status']);
    }

    public function test_breakdown_respeita_toggle_desligado(): void
    {
        $this->rule();
        AutoReplySetting::where('account_id', $this->account->id)->update(['window_enabled' => false]);
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);

        $r = $this->tester()->test($this->account->id, null, 'wifi', $contact->id);

        $this->assertSame('desligado', $this->freio($r, 'Janela')['status']);
    }

    public function test_breakdown_marca_bloqueio_do_kill_switch(): void
    {
        $this->rule();
        AutoReplySetting::where('account_id', $this->account->id)->update(['enabled' => false]);
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);

        $r = $this->tester()->test($this->account->id, null, 'wifi', $contact->id);

        $this->assertSame('bloqueia', $this->freio($r, 'kill switch')['status']);
    }

    public function test_dry_run_nao_envia_nem_cria_log(): void
    {
        $this->rule();
        $contact = Contact::create(['account_id' => $this->account->id, 'remote_jid' => 'j@s.whatsapp.net', 'push_name' => 'Joao', 'auto_reply_mode' => 'on']);

        $this->tester()->test($this->account->id, null, 'wifi', $contact->id);
        $this->tester()->test($this->account->id, null, 'wifi', $contact->id);

        // Nenhum log de envio criado, nenhum HTTP disparado (preventStrayRequests garante).
        $this->assertSame(0, AutoReplyLog::count());
    }
}
