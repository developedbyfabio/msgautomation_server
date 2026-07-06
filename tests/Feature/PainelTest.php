<?php

namespace Tests\Feature;

use App\Livewire\Painel;
use App\Metrics\PainelMetrics;
use App\Models\Account;
use App\Models\AiDecision;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\CampaignTarget;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Flow;
use App\Models\FlowSession;
use App\Models\IncomingMessage;
use App\Models\ProactiveCampaign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * M-1 — /painel: numeros exatos de um cenario SEMEADO (nenhum envio real; leitura
 * pura). Fronteiras de periodo em SP, mediana com 3 casos, cache de 60s provado
 * por contagem de queries, sanidade sem N+1, isolamento entre contas.
 */
class PainelTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 1, 12, 0, 0, 'America/Sao_Paulo'));

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
        AutoReplySetting::create(['account_id' => $this->account->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function incoming(string $jid, Carbon $em, string $id): IncomingMessage
    {
        return IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => 'fabio-pessoal', 'evolution_message_id' => $id,
            'remote_jid' => $jid, 'from_me' => false, 'type' => 'conversation',
            'text' => 'msg', 'raw_payload' => [], 'received_at' => $em->copy()->utc(),
        ]);
    }

    private function sent(string $jid, string $mode, Carbon $em, array $extra = []): AutoReplyLog
    {
        return AutoReplyLog::create(array_merge([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'remote_jid' => $jid, 'mode' => $mode, 'response_text' => 'resp',
            'status' => 'sent', 'sent_at' => $em->copy()->utc(),
        ], $extra));
    }

    /** Cenario conhecido dentro das ultimas 24h (cabe em 'hoje' parcialmente e em 7d). */
    private function semear(): void
    {
        $tz = 'America/Sao_Paulo';
        $ontem = Carbon::create(2026, 6, 30, 15, 0, 0, $tz); // dentro de 7d, fora de hoje
        $hoje = Carbon::create(2026, 7, 1, 9, 0, 0, $tz);

        // 3 recebidas individuais (2 hoje, 1 ontem) + 1 de grupo + 1 fora do periodo (8 dias atras).
        $m1 = $this->incoming('5541111110001@s.whatsapp.net', $hoje, 'M1');
        $m2 = $this->incoming('5541111110002@s.whatsapp.net', $hoje->copy()->addMinutes(10), 'M2');
        $m3 = $this->incoming('5541111110003@s.whatsapp.net', $ontem, 'M3');
        $this->incoming('999@g.us', $hoje, 'MG');
        $this->incoming('5541111110009@s.whatsapp.net', Carbon::create(2026, 6, 22, 10, 0, 0, $tz), 'MFORA');

        // Respostas: 1 regra deterministica (auto+rule), 1 fluxo (auto sem rule),
        // 1 IA-regra, 1 IA-base, 1 manual, 1 aprovacao, 1 proativa = 7 enviadas.
        $rule = \App\Models\AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'x', 'response_text' => 'r', 'enabled' => true]);
        // m1: respondida 30s depois (mediana par 1).
        $this->sent($m1->remote_jid, 'auto', $hoje->copy()->addSeconds(30), ['rule_id' => $rule->id, 'incoming_message_id' => $m1->id]);
        // m2: DUAS respostas (90s e 300s) -> mediana usa a PRIMEIRA (90s).
        $this->sent($m2->remote_jid, 'auto', $hoje->copy()->addMinutes(10)->addSeconds(90), ['incoming_message_id' => $m2->id]); // fluxo (sem rule)
        $this->sent($m2->remote_jid, 'manual', $hoje->copy()->addMinutes(15));
        // m3: SEM resposta (fora da mediana).
        // IA-regra e IA-base (com decisoes casadas):
        $m4 = $this->incoming('5541111110004@s.whatsapp.net', $hoje->copy()->addHour(), 'M4');
        $m5 = $this->incoming('5541111110005@s.whatsapp.net', $hoje->copy()->addHours(2), 'M5');
        AiDecision::create(['account_id' => $this->account->id, 'incoming_message_id' => $m4->id, 'remote_jid' => $m4->remote_jid, 'acao' => 'respondeu', 'origem' => 'regra', 'intent' => 'horario']);
        AiDecision::create(['account_id' => $this->account->id, 'incoming_message_id' => $m5->id, 'remote_jid' => $m5->remote_jid, 'acao' => 'respondeu', 'origem' => 'base', 'intent' => 'horario']);
        AiDecision::create(['account_id' => $this->account->id, 'remote_jid' => $m5->remote_jid, 'acao' => 'escalou', 'origem' => 'regra', 'intent' => 'preco']);
        $this->sent($m4->remote_jid, 'auto', $hoje->copy()->addHour()->addMinute(), ['rule_id' => $rule->id, 'incoming_message_id' => $m4->id]);
        $this->sent($m5->remote_jid, 'auto', $hoje->copy()->addHours(2)->addMinute(), ['incoming_message_id' => $m5->id]);
        // aprovacao + proativa:
        $this->sent($m3->remote_jid, 'aprovacao', $hoje->copy()->addMinutes(90));  // 10h30 SP
        $this->sent($m3->remote_jid, 'proactive', $hoje->copy()->addMinutes(150)); // 11h30 SP

        // Fluxos: 2 sessoes (1 completed, 1 expired).
        $flow = Flow::create(['account_id' => $this->account->id, 'name' => 'Menu Principal', 'enabled' => true, 'timeout_seconds' => 600]);
        $node = \App\Models\FlowNode::create(['flow_id' => $flow->id, 'kind' => 'menu', 'message' => 'M']);
        foreach (['completed', 'expired'] as $st) {
            FlowSession::create(['account_id' => $this->account->id, 'flow_id' => $flow->id, 'remote_jid' => $m1->remote_jid, 'current_node_id' => $node->id, 'status' => $st, 'started_at' => $hoje, 'last_activity_at' => $hoje, 'expires_at' => $hoje->copy()->addMinutes(10)]);
        }

        // Kanban: 2 cards + 1 transicao pra Em atendimento.
        $board = Board::withoutAccountScope()->where('account_id', $this->account->id)->first();
        $novo = $board->columns()->where('slug', 'novo')->first();
        $emAt = $board->columns()->where('slug', 'em_atendimento')->first();
        $c1 = Contact::create(['account_id' => $this->account->id, 'remote_jid' => $m1->remote_jid]);
        $c2 = Contact::create(['account_id' => $this->account->id, 'remote_jid' => $m2->remote_jid]);
        $card1 = Card::create(['account_id' => $this->account->id, 'board_id' => $board->id, 'contact_id' => $c1->id, 'column_id' => $emAt->id]);
        Card::create(['account_id' => $this->account->id, 'board_id' => $board->id, 'contact_id' => $c2->id, 'column_id' => $novo->id]);
        CardTransition::create(['card_id' => $card1->id, 'from_column_id' => $novo->id, 'to_column_id' => $emAt->id, 'cause' => 'regra']);

        // Proativas: 1 sent + 2 skipped (motivos distintos) + 1 failed.
        $camp = ProactiveCampaign::create(['account_id' => $this->account->id, 'name' => 'C', 'message' => 'oi', 'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => []], 'status' => 'running']);
        CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c1->id, 'status' => 'sent', 'sent_at' => $hoje->copy()->utc()]);
        CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c2->id, 'status' => 'skipped', 'skip_reason' => 'sem_opt_in']);
        $c3 = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541111110006@s.whatsapp.net']);
        CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c3->id, 'status' => 'skipped', 'skip_reason' => 'opt_out_revogado']);
        $c4 = Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541111110007@s.whatsapp.net']);
        CampaignTarget::create(['campaign_id' => $camp->id, 'contact_id' => $c4->id, 'status' => 'failed', 'skip_reason' => 'erro_envio']);
    }

    private function dados(string $periodo = '7d'): array
    {
        return app(PainelMetrics::class)->dados($this->account->id, $periodo);
    }

    // ---- numeros exatos ---------------------------------------------------------

    public function test_resumo_com_numeros_exatos_em_7d(): void
    {
        $this->semear();
        $d = $this->dados('7d');

        $this->assertSame(5, $d['resumo']['recebidas']);   // 5 individuais (a de 8 dias fora)
        $this->assertSame(1, $d['resumo']['grupos']);
        $this->assertSame(7, $d['resumo']['enviadas']);
        // 4 auto de 7 enviadas = 57%.
        $this->assertSame(57, $d['resumo']['pct_automatico']);
    }

    public function test_origens_classificadas_exatas(): void
    {
        $this->semear();
        $o = $this->dados('7d')['origens'];

        $this->assertSame(1, $o['Regra deterministica']);
        $this->assertSame(1, $o['Fluxo']);
        $this->assertSame(1, $o['IA (casou regra)']);
        $this->assertSame(1, $o['IA (base)']);
        $this->assertSame(1, $o['Aprovacao humana']);
        $this->assertSame(1, $o['Manual']);
        $this->assertSame(1, $o['Proativa']);
    }

    public function test_mediana_primeira_resposta_com_os_tres_casos(): void
    {
        $this->semear();
        // Pares por contato: m1=30s (unica), m2=90s (pega a PRIMEIRA de duas),
        // m4=60s, m5=60s, m3=~19.5h (recebida ontem; 1a resposta = aprovacao hoje).
        // Ordenado [30,60,60,90,~70200] -> mediana = 60 (imune ao outlier).
        $d = $this->dados('7d');

        $this->assertSame(60, $d['resumo']['mediana_primeira_resposta']);
    }

    public function test_blocos_ia_fluxos_kanban_proativas_exatos(): void
    {
        $this->semear();
        $d = $this->dados('7d');

        $this->assertSame(2, $d['ia']['por_acao']['respondeu']);
        $this->assertSame(1, $d['ia']['por_acao']['escalou']);
        $this->assertEquals(['horario' => 2, 'preco' => 1], $d['ia']['top_intents']);

        $this->assertSame(2, $d['fluxos']['iniciadas']);
        $this->assertSame(1, $d['fluxos']['concluidas']);
        $this->assertSame(1, $d['fluxos']['expiradas']);
        $this->assertSame(['Menu Principal' => 2], $d['fluxos']['top']);

        $this->assertSame(2, $d['kanban']['criados']);
        $this->assertSame(['Em atendimento' => 1], $d['kanban']['transicoes']);
        $this->assertSame(1, $d['kanban']['agora']['Novo']);
        $this->assertSame(1, $d['kanban']['agora']['Em atendimento']);

        $this->assertSame(1, $d['proativas']['enviadas']);
        $this->assertEquals(['sem_opt_in' => 1, 'opt_out_revogado' => 1], $d['proativas']['puladas']);
        $this->assertSame(1, $d['proativas']['falhadas']);
        $this->assertSame(1, $d['proativas']['campanhas_ativas']);
    }

    // ---- fronteira de periodo (timezone SP) -----------------------------------------

    public function test_periodo_hoje_exclui_ontem_na_virada_sp(): void
    {
        $this->semear();
        $d = $this->dados('hoje');

        // 'Hoje' em SP comeca 2026-07-01 00:00 SP: m3 (ontem 15h SP) fica FORA.
        $this->assertSame(4, $d['resumo']['recebidas']); // m1, m2, m4, m5
    }

    // ---- cache -----------------------------------------------------------------------

    public function test_cache_60s_segunda_leitura_sem_queries_e_expira_por_periodo(): void
    {
        $this->semear();
        $this->dados('7d'); // popula o cache

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->dados('7d');
        $this->assertSame(0, $queries); // 2a leitura: cache puro

        // Trocar o periodo = chave nova = re-consulta.
        $this->dados('hoje');
        $this->assertGreaterThan(0, $queries);
    }

    public function test_sanidade_de_queries_sem_n_mais_1(): void
    {
        $this->semear();
        Cache::forget('painel:' . $this->account->id . ':7d');

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->dados('7d');

        // Numero LIMITADO e estavel de queries agregadas (sem N+1 por linha).
        $this->assertLessThanOrEqual(30, $queries);
    }

    // ---- vazio elegante ------------------------------------------------------------------

    public function test_periodo_sem_dados_mostra_zeros_sem_quebrar(): void
    {
        $d = $this->dados('7d');

        $this->assertSame(0, $d['resumo']['recebidas']);
        $this->assertNull($d['resumo']['mediana_primeira_resposta']);

        Livewire::test(Painel::class)
            ->assertSee('Inicio') // Fatia 23 (ajuste deliberado): h1 em linguagem de negocio
            ->assertSee('mediana 1a resposta')
            ->assertSee('Nenhuma atividade proativa');
    }

    // ---- isolamento ------------------------------------------------------------------------

    public function test_dados_da_conta_b_nao_alteram_numeros_da_a(): void
    {
        $this->semear();
        $antes = $this->dados('7d');

        // Conta B com movimento proprio (mesmos jids).
        $b = Account::create(['name' => 'B']);
        $chB = Channel::create(['account_id' => $b->id, 'instance' => 'inst-b', 'status' => 'connected']);
        IncomingMessage::create([
            'account_id' => $b->id, 'channel_id' => $chB->id, 'instance' => 'inst-b',
            'evolution_message_id' => 'B1', 'remote_jid' => '5541111110001@s.whatsapp.net',
            'from_me' => false, 'type' => 'conversation', 'text' => 'msg', 'raw_payload' => [],
            'received_at' => now(),
        ]);
        AutoReplyLog::create(['account_id' => $b->id, 'channel_id' => $chB->id, 'remote_jid' => '5541111110001@s.whatsapp.net', 'mode' => 'auto', 'response_text' => 'r', 'status' => 'sent', 'sent_at' => now()]);

        Cache::forget('painel:' . $this->account->id . ':7d');
        $depois = $this->dados('7d');

        $this->assertSame($antes['resumo'], $depois['resumo']); // nada da B vazou
    }
}
