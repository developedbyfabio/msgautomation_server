<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fatia A.3 — integracao no pipeline (HTTP mockado, SEM envio real). Sessao ativa
 * tem prioridade; fluxo vence regra; isencao do cooldown durante a sessao; robô
 * inalterado sem fluxo ligado. O kill switch e flipado SO neste DB de teste (sqlite).
 */
class FlowPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';
    private Account $account;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 1800, // rate ALTO de proposito (prova a isencao do fluxo)
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function flow(): Flow
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'RAIZ: 1 - Suporte']);
        $fim = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'FINAL suporte']);
        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $fim->id]);
        $flow->update(['root_node_id' => $root->id]);

        return $flow->fresh();
    }

    private function receber(string $texto, string $id): void
    {
        $payload = [
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ];
        (new ProcessIncomingWhatsappMessage($payload))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    public function test_fluxo_inicia_e_navega_no_pipeline(): void
    {
        $this->flow();

        $this->receber('quero o menu', 'M1'); // inicia
        Http::assertSent(fn ($r) => $r['text'] === 'RAIZ: 1 - Suporte');
        $this->assertDatabaseHas('flow_sessions', ['remote_jid' => self::JID, 'status' => 'active']);

        $this->receber('1', 'M2'); // navega ate o final
        Http::assertSent(fn ($r) => $r['text'] === 'FINAL suporte');
        $this->assertDatabaseHas('flow_sessions', ['remote_jid' => self::JID, 'status' => 'completed']);
        Http::assertSentCount(2); // duas respostas, apesar do contact_rate alto (isencao)
    }

    public function test_fluxo_vence_regra(): void
    {
        $this->flow();
        // Regra que tambem casaria "menu" — mas o fluxo vence.
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'menu', 'response_text' => 'RESPOSTA DE REGRA', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $rule->responses()->create(['response_text' => 'RESPOSTA DE REGRA']);

        $this->receber('menu', 'V1');

        Http::assertSent(fn ($r) => $r['text'] === 'RAIZ: 1 - Suporte');
        Http::assertNotSent(fn ($r) => $r['text'] === 'RESPOSTA DE REGRA');
    }

    public function test_sessao_ativa_nao_cai_nas_regras(): void
    {
        $this->flow();
        // Regra que casaria "ajuda" — mas com sessao ativa, "ajuda" e opcao invalida.
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'ajuda', 'response_text' => 'REGRA AJUDA', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'ajuda']);
        $rule->responses()->create(['response_text' => 'REGRA AJUDA']);

        $this->receber('menu', 'S1');     // inicia fluxo
        $this->receber('ajuda', 'S2');    // dentro da sessao -> invalida, NAO dispara a regra

        Http::assertNotSent(fn ($r) => $r['text'] === 'REGRA AJUDA');
        Http::assertSent(fn ($r) => str_contains(mb_strtolower($r['text']), 'invalida'));
    }

    public function test_opt_out_no_meio_encerra_sessao(): void
    {
        $this->flow();
        $this->receber('menu', 'O1'); // inicia
        Contact::where('remote_jid', self::JID)->update(['auto_reply_mode' => 'off']);
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]); // reseta gravacao
        $this->receber('1', 'O2'); // contato agora off -> encerra sem responder

        $this->assertDatabaseHas('flow_sessions', ['remote_jid' => self::JID, 'status' => 'cancelled']);
        Http::assertNothingSent();
    }

    public function test_sem_fluxo_ligado_usa_regra_normal(): void
    {
        // Fluxo DESLIGADO -> robô inalterado: a regra responde.
        $flow = $this->flow();
        $flow->update(['enabled' => false]);
        $rule = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'menu', 'response_text' => 'SO A REGRA', 'enabled' => true]);
        $rule->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $rule->responses()->create(['response_text' => 'SO A REGRA']);

        $this->receber('menu', 'N1');

        Http::assertSent(fn ($r) => $r['text'] === 'SO A REGRA');
        $this->assertDatabaseMissing('flow_sessions', ['remote_jid' => self::JID, 'status' => 'active']);
    }

    /**
     * BUGFIX (producao): no de fluxo com placeholder saia CRU ("{saudacao}") — o
     * caminho de texto direto nao passava pelo renderizador. Agora usa o MESMO
     * RuleResponder das regras, NO ENVIO. (10h SP -> "Bom dia"; pushName do payload.)
     */
    public function test_no_de_fluxo_renderiza_placeholders_no_envio(): void
    {
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'F', 'enabled' => true, 'timeout_seconds' => 600]);
        $flow->triggers()->create(['match_type' => 'contains', 'match_value' => 'menu']);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => "{saudacao}, {nome}! Escolha:\n1 - Suporte"]);
        $fim = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'final', 'message' => 'Ate mais, {nome}!']);
        $root->options()->create(['input' => '1', 'label' => '1 - Suporte', 'next_node_id' => $fim->id]);
        $flow->update(['root_node_id' => $root->id]);

        // No raiz (entrada no fluxo): placeholders renderizados.
        $this->receber('menu', 'PH1');
        Http::assertSent(fn ($r) => $r['text'] === "Bom dia, Cliente! Escolha:\n1 - Suporte");

        // No final (advance): idem.
        $this->receber('1', 'PH2');
        Http::assertSent(fn ($r) => $r['text'] === 'Ate mais, Cliente!');
    }
}
