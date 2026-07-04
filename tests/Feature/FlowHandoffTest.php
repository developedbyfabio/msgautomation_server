<?php

namespace Tests\Feature;

use App\Enums\OperationMode;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\FlowOption;
use App\Models\FlowSession;
use App\Models\IncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fatia 5 — no de HANDOFF no motor: mensagem enviada, robo pausado (mute reusado:
 * auto_reply_mode='off'), card -> em_atendimento (BoardEngine), sessao terminal
 * handed_off. Contato pausado NAO re-dispara o catch-all, mas as mensagens dele
 * CONTINUAM sendo recebidas/persistidas. Reativar restaura. Isolamento por conta.
 */
class FlowHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;
    private Flow $flow;
    private FlowNode $handoffNode;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']); // booted() provisiona o board default
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'operation_mode' => OperationMode::Auto,
            'window_start' => '08:00:00', 'window_end' => '20:00:00',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);

        // Fluxo padrao: menu -> opcao '1' -> HANDOFF.
        $this->flow = Flow::create(['account_id' => $this->account->id, 'name' => 'Atendimento', 'enabled' => true, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $this->flow->id, 'kind' => 'menu', 'message' => 'MENU: 1-Falar com atendente']);
        $this->handoffNode = FlowNode::create(['flow_id' => $this->flow->id, 'kind' => 'handoff', 'message' => 'Um atendente vai te responder em breve.']);
        FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'Atendente', 'next_node_id' => $this->handoffNode->id, 'ordem' => 1]);
        $this->flow->update(['root_node_id' => $root->id]);
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->update(['default_flow_id' => $this->flow->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function receber(string $texto, string $id, string $instance = 'fabio-pessoal', string $jid = self::JID): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => $instance,
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => $jid],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
    }

    /** Abre o menu (catch-all) e escolhe '1' -> handoff. */
    private function executarHandoff(): void
    {
        $this->receber('oi', 'H1');  // catch-all abre o menu (sessao ativa)
        $this->receber('1', 'H2');   // opcao 1 -> no handoff
    }

    public function test_handoff_executa_os_quatro_efeitos(): void
    {
        $this->executarHandoff();

        // 1) mensagem do no ENVIADA (a despedida nao e bloqueada pelo proprio mute).
        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'Um atendente vai te responder em breve.']);

        // 2) robo pausado pro contato (mute reusado).
        $this->assertSame('off', Contact::withoutAccountScope()->where('remote_jid', self::JID)->first()->auto_reply_mode);

        // 3) card em em_atendimento. (Nota: com as regras default do board, a regra
        //    'resposta_enviada' ja move no envio do menu — o movimento do handoff vira
        //    no-op de mesma coluna, semantica correta. O teste dedicado abaixo prova o
        //    movimento DETERMINISTICO do handoff sem depender de regra nenhuma.)
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->first();
        $col = BoardColumn::query()->where('board_id', $board->id)->where('slug', 'em_atendimento')->first();
        $card = Card::withoutAccountScope()->where('board_id', $board->id)->first();
        $this->assertNotNull($card);
        $this->assertSame((int) $col->id, (int) $card->column_id);

        // 4) sessao terminal handed_off.
        $this->assertSame('handed_off', FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->latest('id')->first()->status);
    }

    public function test_handoff_move_o_card_deterministicamente_sem_depender_de_regras(): void
    {
        // Desativa TODAS as regras do board (contas podem customizar/apagar regras):
        // o handoff ainda TEM que mover — mecanismo deterministico, nao regra.
        \App\Models\BoardRule::withoutAccountScope()->where('account_id', $this->account->id)->update(['active' => false]);

        $this->executarHandoff();

        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->where('is_default', true)->first();
        $col = BoardColumn::query()->where('board_id', $board->id)->where('slug', 'em_atendimento')->first();
        $card = Card::withoutAccountScope()->where('board_id', $board->id)->first();
        $this->assertNotNull($card); // criado pelo proprio handoff (nenhuma regra ativa)
        $this->assertSame((int) $col->id, (int) $card->column_id);
        $this->assertTrue(CardTransition::query()->where('card_id', $card->id)->where('cause', 'handoff')->exists());
    }

    public function test_apos_handoff_robo_pausado_mas_mensagens_continuam_chegando(): void
    {
        $this->executarHandoff();
        $logsAntes = AutoReplyLog::withoutAccountScope()->count();
        $sessoesAntes = FlowSession::withoutAccountScope()->count();

        // Contato pausado manda outra mensagem (em modo AUTO com fluxo padrao!).
        $this->receber('alguem me atende?', 'H3');

        // Ingestao NAO bloqueada: a mensagem foi persistida (aparece em Conversas).
        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'H3', 'text' => 'alguem me atende?']);
        // Mas SEM auto-reply e SEM catch-all (mute respeitado pelo gate da Fatia 4).
        $this->assertSame($logsAntes, AutoReplyLog::withoutAccountScope()->count());
        $this->assertSame($sessoesAntes, FlowSession::withoutAccountScope()->count());
    }

    public function test_reativar_pelo_mecanismo_existente_restaura_o_atendimento(): void
    {
        $this->executarHandoff();

        // Humano reativa (mesmo mecanismo da UI: auto_reply_mode volta pra default).
        Contact::withoutAccountScope()->where('remote_jid', self::JID)->update(['auto_reply_mode' => 'default']);

        $this->receber('oi de novo', 'H4');

        // Catch-all volta a valer: nova sessao ativa do fluxo padrao + menu enviado de novo.
        $nova = FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->latest('id')->first();
        $this->assertSame('active', $nova->status);
        $this->assertSame($this->flow->id, (int) $nova->flow_id);
        // menu enviado 2x: na abertura (H1) e apos reativar (H4).
        $this->assertSame(2, AutoReplyLog::withoutAccountScope()->where('status', 'sent')->where('response_text', 'like', 'MENU:%')->count());
    }

    public function test_isolamento_handoff_em_a_nao_pausa_contato_de_b(): void
    {
        // Conta B com contato do MESMO jid, aprovado.
        $b = Account::create(['name' => 'B']);
        Channel::create(['account_id' => $b->id, 'instance' => 'instancia-b', 'status' => 'connected']);
        Contact::create(['account_id' => $b->id, 'remote_jid' => self::JID, 'auto_reply_mode' => 'on']);

        $this->executarHandoff(); // handoff na conta A

        $this->assertSame('off', Contact::withoutAccountScope()->where('account_id', $this->account->id)->where('remote_jid', self::JID)->first()->auto_reply_mode);
        $this->assertSame('on', Contact::withoutAccountScope()->where('account_id', $b->id)->where('remote_jid', self::JID)->first()->auto_reply_mode); // B intacto
        $this->assertSame(0, FlowSession::withoutAccountScope()->where('account_id', $b->id)->count());
    }

    public function test_menu_e_final_inalterados(): void
    {
        // Regressao leve: um fluxo menu->final continua funcionando como sempre.
        $f = Flow::create(['account_id' => $this->account->id, 'name' => 'Normal', 'enabled' => true, 'timeout_seconds' => 600]);
        $root = FlowNode::create(['flow_id' => $f->id, 'kind' => 'menu', 'message' => 'NORMAL: 1-Fim']);
        $fim = FlowNode::create(['flow_id' => $f->id, 'kind' => 'final', 'message' => 'FIM NORMAL']);
        FlowOption::create(['flow_node_id' => $root->id, 'input' => '1', 'label' => 'Fim', 'next_node_id' => $fim->id, 'ordem' => 1]);
        $f->update(['root_node_id' => $root->id]);
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->account->id)->update(['default_flow_id' => $f->id]);

        $this->receber('oi', 'N1');
        $this->receber('1', 'N2');

        $this->assertDatabaseHas('auto_reply_logs', ['status' => 'sent', 'response_text' => 'FIM NORMAL']);
        $this->assertSame('completed', FlowSession::withoutAccountScope()->where('remote_jid', self::JID)->latest('id')->first()->status);
        // e o contato NAO foi mutado por um fluxo normal
        $this->assertNotSame('off', Contact::withoutAccountScope()->where('account_id', $this->account->id)->where('remote_jid', self::JID)->first()->auto_reply_mode);
    }
}
