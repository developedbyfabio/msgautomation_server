<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowSession;
use App\Models\FlowTrigger;
use App\Models\UnmatchedMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fatia 4 — o modo GANHA VIDA: catch-all no ramo $rule===null + politica efetiva
 * 'all' no gate quando operation_mode=auto. Matriz completa: personal byte-identico,
 * catch-all funciona, precedencia (sessao/regra/fluxo-entrada vencem), degradacao
 * graciosa, semantica do gate (allowlist override, mute, grupo, throttle) e
 * isolamento entre contas. Pipeline exercitado de verdade (job inline, queue sync,
 * envio via Sender contra Http::fake).
 */
class AutoModeCatchAllTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo')); // dentro da janela
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $over = []): AutoReplySetting
    {
        return AutoReplySetting::create(array_merge([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ], $over));
    }

    private function fluxo(string $nome, string $raiz = 'MENU: 1-Consulta 2-Horario', bool $enabled = true, ?Account $conta = null): Flow
    {
        $conta ??= $this->account;
        $flow = Flow::create(['account_id' => $conta->id, 'name' => $nome, 'enabled' => $enabled, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => $raiz]);
        $flow->update(['root_node_id' => $root->id]);

        return $flow;
    }

    private function receber(string $texto, string $id, string $jid = self::JID, string $instance = 'fabio-pessoal'): void
    {
        $payload = [
            'event' => 'messages.upsert', 'instance' => $instance,
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
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

    // ---- regressao / personal (byte-identico) -----------------------------------

    public function test_personal_sem_match_continua_silencio_total(): void
    {
        $this->settings(); // personal (default) + reply_policy all
        $this->fluxo('Atendimento'); // fluxo existe mas NAO e default e modo e personal

        $this->receber('mensagem sem match nenhum', 'P1');

        $this->assertSame(0, FlowSession::withoutAccountScope()->count());           // nenhum fluxo iniciado
        $this->assertDatabaseMissing('auto_reply_logs', ['remote_jid' => self::JID]); // nenhuma resposta
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->count());       // so o registro de silencio
    }

    // ---- auto: catch-all funciona ------------------------------------------------

    public function test_auto_com_fluxo_padrao_dispara_o_catchall(): void
    {
        $flow = $this->fluxo('Atendimento');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id]);

        $this->receber('mensagem sem match nenhum', 'A1');

        // Sessao do fluxo padrao criada e o menu ENVIADO (Sender real contra Http::fake).
        $sess = FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->first();
        $this->assertNotNull($sess);
        $this->assertSame($flow->id, $sess->flow_id);
        $this->assertDatabaseHas('auto_reply_logs', ['remote_jid' => self::JID, 'status' => 'sent', 'response_text' => 'MENU: 1-Consulta 2-Horario']);
        $this->assertSame(0, UnmatchedMessage::withoutAccountScope()->count()); // respondeu, nao e silencio
    }

    // ---- auto: precedencia preservada ---------------------------------------------

    public function test_auto_regra_vence_o_catchall(): void
    {
        $flow = $this->fluxo('Atendimento');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id]);
        AutoReplyRule::create([
            'account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'preco',
            'response_text' => 'RESPOSTA-DA-REGRA', 'enabled' => true, 'priority' => 0,
        ]);

        $this->receber('qual o preco?', 'A2');

        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'RESPOSTA-DA-REGRA']);
        $this->assertSame(0, FlowSession::withoutAccountScope()->count()); // catch-all NAO disparou
    }

    public function test_auto_sessao_ativa_vence_o_catchall(): void
    {
        $default = $this->fluxo('Padrao');
        // Fluxo de entrada com OPCOES (menu sem opcoes e terminal: a sessao nasce
        // 'completed' — achado do motor). Com opcao, a sessao fica ATIVA apos a msg1.
        $entrada = $this->fluxo('Entrada', raiz: 'ENTRADA: 1-Suporte');
        $fim = FlowNode::create(['flow_id' => $entrada->id, 'kind' => 'final', 'message' => 'FIM']);
        \App\Models\FlowOption::create(['flow_node_id' => $entrada->rootNode()->id, 'input' => '1', 'label' => 'Suporte', 'next_node_id' => $fim->id, 'ordem' => 1]);
        FlowTrigger::create(['flow_id' => $entrada->id, 'match_type' => 'exact', 'match_value' => 'suporte', 'precision' => 'tolerante', 'fuzzy_level' => 'media']);
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $default->id]);

        $this->receber('suporte', 'A3a'); // abre sessao ATIVA do fluxo de ENTRADA
        $this->assertSame('active', FlowSession::withoutAccountScope()->where('flow_id', $entrada->id)->first()->status);

        $this->receber('texto qualquer sem match', 'A3b'); // sessao ativa: AVANCA (nao catch-all)

        $sessoes = FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->get();
        $this->assertSame([$entrada->id], $sessoes->pluck('flow_id')->unique()->values()->all()); // SO a sessao da entrada
        $this->assertSame(0, FlowSession::withoutAccountScope()->where('flow_id', $default->id)->count());
    }

    public function test_auto_fluxo_de_entrada_vence_o_catchall(): void
    {
        $default = $this->fluxo('Padrao');
        $entrada = $this->fluxo('Entrada', raiz: 'ENTRADA: escolha');
        FlowTrigger::create(['flow_id' => $entrada->id, 'match_type' => 'exact', 'match_value' => 'suporte', 'precision' => 'tolerante', 'fuzzy_level' => 'media']);
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $default->id]);

        $this->receber('suporte', 'A4');

        $sess = FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->first();
        $this->assertSame($entrada->id, $sess->flow_id); // entrada, NAO o padrao
    }

    // ---- auto: degradacao graciosa -------------------------------------------------

    public function test_auto_sem_fluxo_padrao_cai_no_comportamento_atual(): void
    {
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => null]);

        $this->receber('sem match', 'A5');

        $this->assertSame(0, FlowSession::withoutAccountScope()->count());
        $this->assertDatabaseMissing('auto_reply_logs', ['remote_jid' => self::JID]);
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->count()); // fall-through identico
    }

    public function test_auto_fluxo_padrao_desabilitado_fall_through(): void
    {
        $flow = $this->fluxo('Desligado', enabled: false);
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id]);

        $this->receber('sem match', 'A6');

        $this->assertSame(0, FlowSession::withoutAccountScope()->count());
        $this->assertSame(1, UnmatchedMessage::withoutAccountScope()->count());
    }

    // ---- auto: semantica do gate ----------------------------------------------------

    public function test_auto_allowlist_e_ignorada_politica_efetiva_all(): void
    {
        // reply_policy=allowlist + contato DESCONHECIDO (nao 'on'): em personal o gate
        // barraria; em AUTO a politica efetiva e 'all' -> catch-all dispara.
        $flow = $this->fluxo('Atendimento');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id, 'reply_policy' => 'allowlist']);

        $this->receber('oi, queria marcar horario', 'A7');

        $this->assertSame(1, FlowSession::withoutAccountScope()->where('flow_id', $flow->id)->count());
        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'MENU: 1-Consulta 2-Horario']);
    }

    public function test_auto_contato_off_continua_mudo(): void
    {
        $flow = $this->fluxo('Atendimento');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id]);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'off']);

        $this->receber('sem match', 'A8');

        $this->assertSame(0, FlowSession::withoutAccountScope()->count());              // mute respeitado
        $this->assertDatabaseMissing('auto_reply_logs', ['remote_jid' => self::JID]);
    }

    public function test_auto_grupo_continua_sem_resposta(): void
    {
        $flow = $this->fluxo('Atendimento');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id]);

        $this->receber('sem match', 'A9', jid: '120363000000000000@g.us');

        $this->assertSame(0, FlowSession::withoutAccountScope()->count()); // grupo excluido no passo 1
        $this->assertSame(0, \App\Models\AutoReplyLog::withoutAccountScope()->count());
    }

    public function test_auto_throttle_no_limite_bloqueia_o_envio_do_catchall(): void
    {
        $flow = $this->fluxo('Atendimento');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flow->id, 'per_minute_cap' => 1]);
        app(\App\Whatsapp\AutoReply\Throttle::class)->recordSend($this->account->id); // teto ja consumido

        $this->receber('sem match', 'A10');

        // O envio passa pelo MESMO Sender/freios dos demais caminhos: bloqueado, nada 'sent'.
        $this->assertDatabaseMissing('auto_reply_logs', ['status' => 'sent']);
        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'blocked']);
    }

    // ---- isolamento -------------------------------------------------------------------

    public function test_isolamento_auto_de_a_nao_vaza_pra_b_personal(): void
    {
        // Conta A (setUp) em AUTO com fluxo padrao; conta B em personal.
        $flowA = $this->fluxo('Atendimento-A');
        $this->settings(['operation_mode' => OperationMode::Auto, 'default_flow_id' => $flowA->id]);

        $b = Account::create(['name' => 'B']);
        Channel::create(['account_id' => $b->id, 'instance' => 'instancia-b', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $b->id, 'enabled' => true, 'reply_policy' => 'all',
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]); // personal (default)

        $this->receber('sem match', 'I1b', instance: 'instancia-b'); // pra B -> silencio
        $this->receber('sem match', 'I1a', instance: 'fabio-pessoal'); // pra A -> catch-all

        $this->assertSame(0, FlowSession::withoutAccountScope()->where('account_id', $b->id)->count()); // B mudo
        $this->assertSame(1, FlowSession::withoutAccountScope()->where('account_id', $this->account->id)->where('flow_id', $flowA->id)->count()); // A respondeu
    }
}
