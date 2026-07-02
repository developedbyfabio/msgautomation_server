<?php

namespace Tests\Feature;

use App\Ai\Drivers\GeminiDriver;
use App\Jobs\ClassifyWithAi;
use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Conhecimento;
use App\Livewire\Contatos;
use App\Livewire\Regras;
use App\Livewire\Revisao;
use App\Livewire\Senhas;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Models\Knowledge;
use App\Models\PendingApproval;
use App\Tenancy\AccountContext;
use App\Tenancy\MissingAccountContextException;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\Throttle;
use App\Whatsapp\Secrets\SecretVault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MT-0 — GATE PERMANENTE de isolamento entre contas (doc 09): toda fatia futura
 * roda e ESTENDE este teste. Duas contas ESPELHADAS (mesmo remote_jid, mesmos
 * gatilhos, mesmo nome de secret) provam que vazamento cruzado e impossivel por
 * construcao: webhook resolve pela instancia, telas listam so o contexto, jobs
 * nao cruzam, freios/cota/kill switch por conta, e query sem contexto FALHA ALTO.
 * Driver/HTTP sempre MOCKADOS.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net'; // MESMO jid nas duas contas

    private Account $a;
    private Account $b;
    private Channel $chA;
    private Channel $chB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 2, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        // Contas ESPELHADAS: dados identicos onde importa, valores distintos pra
        // qualquer vazamento aparecer nos asserts.
        $this->a = Account::create(['name' => 'Conta A']);
        $this->b = Account::create(['name' => 'Conta B']);
        $this->chA = Channel::create(['account_id' => $this->a->id, 'instance' => 'inst-a', 'webhook_token' => 'token-aaaa', 'status' => 'connected']);
        $this->chB = Channel::create(['account_id' => $this->b->id, 'instance' => 'inst-b', 'webhook_token' => 'token-bbbb', 'status' => 'connected']);

        foreach ([$this->a, $this->b] as $acc) {
            AutoReplySetting::create([
                'account_id' => $acc->id, 'enabled' => true, 'reply_policy' => 'all',
                'window_start' => '08:00:00', 'window_end' => '20:00:00',
                'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
                'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
                'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
            ]);
        }

        Contact::create(['account_id' => $this->a->id, 'remote_jid' => self::JID, 'push_name' => 'Cliente-da-A', 'auto_reply_mode' => 'on']);
        Contact::create(['account_id' => $this->b->id, 'remote_jid' => self::JID, 'push_name' => 'Cliente-da-B', 'auto_reply_mode' => 'on']);

        // Regras com o MESMO gatilho e respostas diferentes.
        foreach ([[$this->a, 'RESPOSTA-DA-CONTA-A'], [$this->b, 'RESPOSTA-DA-CONTA-B']] as [$acc, $resp]) {
            $r = AutoReplyRule::create(['account_id' => $acc->id, 'match_type' => 'contains', 'match_value' => 'horario', 'response_text' => $resp, 'enabled' => true]);
            $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'horario']);
            $r->responses()->create(['response_text' => $resp]);
        }

        // Secrets com o MESMO nome e valores diferentes.
        app(SecretVault::class)->put($this->a->id, 'wifi', 'VALOR-WIFI-A');
        app(SecretVault::class)->put($this->b->id, 'wifi', 'VALOR-WIFI-B');

        Knowledge::create(['account_id' => $this->a->id, 'title' => 'Base-da-A', 'content' => 'conteudo A', 'sensitivity' => 'low', 'active' => true]);
        Knowledge::create(['account_id' => $this->b->id, 'title' => 'Base-da-B', 'content' => 'conteudo B', 'sensitivity' => 'low', 'active' => true]);

        // Contexto limpo: cada teste define (ou usa o fallback = conta A, oldest).
        app(AccountContext::class)->clear();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function webhook(string $instance, string $texto, string $id): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => $instance,
            'data' => [
                'key' => ['id' => $id, 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(AntiBanGuard::class),
        );
    }

    // ---- webhook: a conta vem da INSTANCIA ------------------------------------

    public function test_webhook_da_instancia_a_processa_e_responde_so_com_dados_da_a(): void
    {
        $this->webhook('inst-a', 'qual o horario?', 'W1');

        // Mensagem arquivada NA CONTA A; resposta e a REGRA DA A (a da B, com o
        // MESMO gatilho, nao casa).
        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'W1', 'account_id' => $this->a->id]);
        Http::assertSent(fn ($r) => $r['text'] === 'RESPOSTA-DA-CONTA-A');
        $this->assertDatabaseMissing('auto_reply_logs', ['account_id' => $this->b->id]);
    }

    public function test_webhook_da_instancia_b_responde_com_a_regra_da_b(): void
    {
        $this->webhook('inst-b', 'qual o horario?', 'W2');

        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'W2', 'account_id' => $this->b->id]);
        Http::assertSent(fn ($r) => $r['text'] === 'RESPOSTA-DA-CONTA-B');
        $this->assertDatabaseMissing('auto_reply_logs', ['account_id' => $this->a->id]);
    }

    public function test_webhook_instancia_desconhecida_descarta_e_conta_no_diagnostico(): void
    {
        $antes = (int) Cache::get('webhook:instancia_desconhecida:' . now()->format('Y-m-d'), 0);

        $this->webhook('inst-fantasma', 'oi', 'W3');

        // NUNCA cai em outra conta: nada persistido, nada enviado, contador visivel.
        $this->assertDatabaseCount('incoming_messages', 0);
        Http::assertNothingSent();
        $this->assertSame($antes + 1, (int) Cache::get('webhook:instancia_desconhecida:' . now()->format('Y-m-d')));
        $this->assertSame('inst-fantasma', Cache::get('webhook:instancia_desconhecida:ultima'));
    }

    // ---- secret: mesmo nome, valor da conta certa -------------------------------

    public function test_secret_de_mesmo_nome_resolve_o_valor_da_conta_do_envio(): void
    {
        // Regra da A que devolve a senha (escopo contatos com o contato da A).
        $contatoA = Contact::withoutAccountScope()->where('account_id', $this->a->id)->where('remote_jid', self::JID)->first();
        $r = AutoReplyRule::create(['account_id' => $this->a->id, 'match_type' => 'contains', 'match_value' => 'senha do wifi', 'response_text' => 'Senha: {senha:wifi}', 'enabled' => true, 'scope' => 'contatos']);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'senha do wifi']);
        $r->responses()->create(['response_text' => 'Senha: {senha:wifi}']);
        $r->contacts()->attach($contatoA->id);

        $this->webhook('inst-a', 'senha do wifi', 'W4');

        // Resolve o valor DA CONTA A — nunca o da B (mesmo nome no cofre da B).
        Http::assertSent(fn ($req) => $req['text'] === 'Senha: VALOR-WIFI-A');
    }

    // ---- telas: so a conta do contexto ------------------------------------------

    public function test_telas_listam_so_a_conta_do_contexto(): void
    {
        PendingApproval::create([
            'account_id' => $this->b->id, 'remote_jid' => self::JID,
            'suggested_response' => 'SUGESTAO-DA-B', 'origin' => 'regra', 'status' => 'pending',
        ]);

        // Contexto default (fallback) = conta A (oldest).
        Livewire::test(Contatos::class)->assertSee('Cliente-da-A')->assertDontSee('Cliente-da-B');
        Livewire::test(Regras::class)->assertSee('RESPOSTA-DA-CONTA-A')->assertDontSee('RESPOSTA-DA-CONTA-B');
        Livewire::test(Conhecimento::class)->assertSee('Base-da-A')->assertDontSee('Base-da-B');
        Livewire::test(Senhas::class)->assertSee('wifi'); // nome (da A); valor nunca aparece
        Livewire::test(Revisao::class)->assertDontSee('SUGESTAO-DA-B');
    }

    // ---- jobs: contexto explicito, sem cruzar ------------------------------------

    public function test_job_de_ia_com_contexto_da_conta_a_nao_toca_a_b(): void
    {
        foreach ([$this->a, $this->b] as $acc) {
            AutoReplySetting::withoutAccountScope()->where('account_id', $acc->id)->update(['ai_enabled' => true]);
        }
        Contact::withoutAccountScope()->where('account_id', $this->a->id)->where('remote_jid', self::JID)
            ->update(['ai_enabled' => true, 'ai_mode' => 'conhecimento']);

        $im = IncomingMessage::create([
            'account_id' => $this->a->id, 'channel_id' => $this->chA->id, 'instance' => 'inst-a',
            'evolution_message_id' => 'IA1', 'remote_jid' => self::JID, 'from_me' => false,
            'type' => 'conversation', 'text' => 'pergunta livre', 'raw_payload' => [], 'received_at' => now(),
        ]);

        $fake = new FakeTenantAi();
        (new ClassifyWithAi($im->id, $this->a->id))->handle(
            $fake,
            app(AntiBanGuard::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(SecretVault::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
        );

        // MINIMIZACAO cruzada: o payload do modelo so tem a base DA CONTA A.
        $enviado = json_encode($fake->lastAnswerRequest?->entries ?? []);
        $this->assertStringContainsString('conteudo A', $enviado);
        $this->assertStringNotContainsString('conteudo B', $enviado);
        // Decisao gravada NA A; B intocada.
        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'account_id' => $this->a->id]);
        $this->assertDatabaseMissing('ai_decisions', ['account_id' => $this->b->id]);
    }

    // ---- freios / cota / kill switch por conta -------------------------------------

    public function test_teto_de_volume_da_conta_a_nao_consome_o_da_b(): void
    {
        foreach ([$this->a, $this->b] as $acc) {
            AutoReplySetting::withoutAccountScope()->where('account_id', $acc->id)->update(['per_day_cap' => 1, 'per_day_enabled' => true]);
        }

        app(Throttle::class)->recordSend($this->a->id); // consome o teto DA A

        $this->assertFalse(app(AntiBanGuard::class)->check('auto', $this->a->id, self::JID)->allowed);
        $this->assertTrue(app(AntiBanGuard::class)->check('auto', $this->b->id, self::JID)->allowed);
    }

    public function test_cota_de_ia_da_conta_a_nao_consome_a_da_b(): void
    {
        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.daily_cap' => 1,
            'services.gemini.retry_sleep_ms' => 0,
        ]);
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"answer":"","grounded":false,"confidence":0.1,"needs_approval":false}']]]]]], 200)]);
        $ctx = app(AccountContext::class);

        // Conta A gasta a franquia do dia (cap 1)...
        $r1 = $ctx->runAs($this->a->id, fn () => app(GeminiDriver::class)->answer(new \App\Ai\AiAnswerRequest('x', [])));
        $r2 = $ctx->runAs($this->a->id, fn () => app(GeminiDriver::class)->answer(new \App\Ai\AiAnswerRequest('x', [])));
        // ...e a B segue com a franquia dela intacta.
        $r3 = $ctx->runAs($this->b->id, fn () => app(GeminiDriver::class)->answer(new \App\Ai\AiAnswerRequest('x', [])));

        // O que importa aqui e a COTA (chaves por conta): a 1a chamada da A passa
        // pela cota, a 2a estoura ('ia_cota'), e a da B NAO foi consumida pela A.
        $this->assertNotSame('ia_cota', $r1->reason);
        $this->assertSame('ia_cota', $r2->reason);
        $this->assertNotSame('ia_cota', $r3->reason);
    }

    public function test_kill_switch_da_conta_a_nao_desliga_a_b(): void
    {
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->a->id)->update(['enabled' => false]);

        $this->assertSame('kill_switch', app(AntiBanGuard::class)->check('auto', $this->a->id, self::JID)->reason);
        $this->assertTrue(app(AntiBanGuard::class)->check('auto', $this->b->id, self::JID)->allowed);
    }

    // ---- guarda estrutural -----------------------------------------------------------

    public function test_query_sem_contexto_falha_alto_quando_fallback_desligado(): void
    {
        config(['tenancy.single_account_fallback' => false]);
        app(AccountContext::class)->clear();

        $this->expectException(MissingAccountContextException::class);
        Contact::query()->count(); // NUNCA retorna dados de todas as contas em silencio
    }

    public function test_creating_injeta_a_conta_do_contexto_quando_ausente(): void
    {
        app(AccountContext::class)->set($this->b->id);

        $c = Contact::create(['remote_jid' => '5541777770000@s.whatsapp.net']); // sem account_id

        $this->assertSame($this->b->id, (int) $c->account_id);
    }

    public function test_bypass_nomeado_e_o_unico_caminho_cross_account(): void
    {
        app(AccountContext::class)->set($this->a->id);

        // Com escopo: so a conta do contexto. Bypass NOMEADO: todas.
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, Contact::withoutAccountScope()->count());
    }

    // ---- Kanban (K-1): cards/boards/regras por conta ------------------------------------

    public function test_kanban_contas_espelhadas_geram_cards_separados(): void
    {
        // MESMO jid nas duas contas: cada webhook cria o card NA SUA conta.
        $this->webhook('inst-a', 'qual o horario?', 'K1');
        $this->webhook('inst-b', 'qual o horario?', 'K2');

        $cardsA = \App\Models\Card::withoutAccountScope()->where('account_id', $this->a->id)->get();
        $cardsB = \App\Models\Card::withoutAccountScope()->where('account_id', $this->b->id)->get();
        $this->assertCount(1, $cardsA);
        $this->assertCount(1, $cardsB);
        // Cada card aponta pro CONTATO da propria conta (mesmo jid, ids distintos).
        $this->assertNotSame((int) $cardsA[0]->contact_id, (int) $cardsB[0]->contact_id);

        // Evento da A moveu SO o card da A (resposta -> Em atendimento); o da B idem.
        $emAtendA = \App\Models\Board::withoutAccountScope()->where('account_id', $this->a->id)->first()
            ->columns()->where('slug', 'em_atendimento')->value('id');
        $this->assertSame((int) $emAtendA, (int) $cardsA[0]->fresh()->column_id);
    }

    public function test_kanban_boards_e_cards_da_b_invisiveis_no_contexto_a(): void
    {
        $this->webhook('inst-b', 'oi', 'K3'); // cria card na B

        app(AccountContext::class)->clear(); // contexto default = A (fallback)

        // No contexto da A: nada da B aparece (boards default provisionados = 1 visivel).
        $this->assertSame(0, \App\Models\Card::query()->count());
        $this->assertSame(1, \App\Models\Board::query()->count());
        $this->assertSame(4, \App\Models\BoardRule::query()->count()); // so as 4 default da A
        // Cross-account SO pelo bypass nomeado.
        $this->assertSame(1, \App\Models\Card::withoutAccountScope()->count());
        $this->assertSame(2, \App\Models\Board::withoutAccountScope()->count());
    }

    public function test_kanban_tela_da_a_nao_mostra_nem_move_cards_da_b(): void
    {
        $this->webhook('inst-a', 'oi', 'K4');
        $this->webhook('inst-b', 'oi', 'K5');
        // O webhook atualiza push_name ('Cliente'); restaura os nomes espelhados
        // pra distinguir as contas nos asserts da tela.
        Contact::withoutAccountScope()->where('account_id', $this->a->id)->update(['push_name' => 'Cliente-da-A']);
        Contact::withoutAccountScope()->where('account_id', $this->b->id)->update(['push_name' => 'Cliente-da-B']);
        $cardB = \App\Models\Card::withoutAccountScope()->where('account_id', $this->b->id)->firstOrFail();
        $colunaB = (int) $cardB->column_id;
        $boardB = \App\Models\Board::withoutAccountScope()->where('account_id', $this->b->id)->first();
        $resolvidoB = (int) $boardB->columns()->where('slug', 'resolvido')->value('id');

        app(AccountContext::class)->clear(); // contexto default = A

        Livewire::test(\App\Livewire\Kanban::class)
            ->assertSee('Cliente-da-A')
            ->assertDontSee('Cliente-da-B')
            // Acao sobre card da B: no-op (card fora do board da conta A).
            ->call('moveCard', $cardB->id, $resolvidoB)
            ->call('showHistory', $cardB->id)
            ->assertSet('historyCardId', null);

        $this->assertSame($colunaB, (int) $cardB->fresh()->column_id); // B intocado
    }

    // ---- Tags (T-1): homonimas nao se cruzam; escopo/acoes por conta ---------------------

    public function test_tags_homonimas_em_contas_espelhadas_nao_se_cruzam(): void
    {
        // MESMO nome de tag nas duas contas (unique e POR CONTA).
        $tagA = \App\Models\Tag::create(['account_id' => $this->a->id, 'name' => 'vip']);
        $tagB = \App\Models\Tag::create(['account_id' => $this->b->id, 'name' => 'vip']);

        // Regra por tag na A (gatilho identico ao da B nao existe — usa outro texto).
        $contatoA = Contact::withoutAccountScope()->where('account_id', $this->a->id)->where('remote_jid', self::JID)->first();
        $contatoB = Contact::withoutAccountScope()->where('account_id', $this->b->id)->where('remote_jid', self::JID)->first();
        $regraA = \App\Models\AutoReplyRule::create(['account_id' => $this->a->id, 'match_type' => 'contains', 'match_value' => 'promo', 'response_text' => 'PROMO-DA-A', 'enabled' => true, 'scope' => 'tags']);
        $regraA->triggers()->create(['match_type' => 'contains', 'match_value' => 'promo']);
        $regraA->responses()->create(['response_text' => 'PROMO-DA-A']);
        $regraA->tags()->attach($tagA->id);

        // Contato da B tem a tag "vip" DA B — nao pode casar a regra da A.
        $contatoB->tags()->attach($tagB->id, ['origin' => 'manual']);
        $this->webhook('inst-b', 'promo', 'TG1');
        Http::assertNothingSent();

        // Contato da A com a tag "vip" DA A: casa.
        $contatoA->tags()->attach($tagA->id, ['origin' => 'manual']);
        $this->webhook('inst-a', 'promo', 'TG2');
        Http::assertSent(fn ($r) => $r['text'] === 'PROMO-DA-A');
    }

    public function test_acao_de_tag_do_board_da_a_nao_toca_contato_da_b(): void
    {
        $tagA = \App\Models\Tag::create(['account_id' => $this->a->id, 'name' => 'lead']);
        $boardA = \App\Models\Board::withoutAccountScope()->where('account_id', $this->a->id)->first();
        \App\Models\BoardRule::create([
            'account_id' => $this->a->id, 'board_id' => $boardA->id,
            'event_type' => 'mensagem_recebida', 'conditions' => null,
            'action_type' => 'add_tag', 'tag_id' => $tagA->id, 'active' => true, 'position' => -1,
        ]);

        // MESMO jid nas duas contas: o webhook da A tagueia SO o contato da A.
        $this->webhook('inst-a', 'oi', 'TG3');
        $this->webhook('inst-b', 'oi', 'TG4');

        $contatoA = Contact::withoutAccountScope()->where('account_id', $this->a->id)->where('remote_jid', self::JID)->first();
        $contatoB = Contact::withoutAccountScope()->where('account_id', $this->b->id)->where('remote_jid', self::JID)->first();
        // Pivo direto (sem scope): prova crua de que so o contato da A foi tagueado.
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('contact_tag')->where('contact_id', $contatoA->id)->count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('contact_tag')->where('contact_id', $contatoB->id)->count());
    }

    // ---- Proativas (P-1): jaula por conta ------------------------------------------------

    public function test_proativas_freios_consentimentos_e_contadores_por_conta(): void
    {
        $guard = app(\App\Whatsapp\Proactive\ProactiveGuard::class);
        $contatoA = Contact::withoutAccountScope()->where('account_id', $this->a->id)->where('remote_jid', self::JID)->first();
        $contatoB = Contact::withoutAccountScope()->where('account_id', $this->b->id)->where('remote_jid', self::JID)->first();

        // Kill switch proativo da A ligado NAO liga o da B (mesmo jid nas duas).
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->a->id)->update(['proactive_enabled' => true]);
        $contatoA->update(['proactive_opt_in' => true]);
        $contatoB->update(['proactive_opt_in' => true]);

        $meioDia = \Illuminate\Support\Carbon::create(2026, 7, 1, 12, 0, 0, 'America/Sao_Paulo');
        $this->assertTrue($guard->allows($this->a->id, $contatoA->id, 'oi', $meioDia)->allowed);
        $this->assertSame('proactive_off', $guard->allows($this->b->id, $contatoB->id, 'oi', $meioDia)->reason);

        // Claim da A nao consome contador da B.
        $this->assertTrue($guard->claim($this->a->id, $contatoA->id, $meioDia));
        $this->assertSame(1, $guard->dayCount($this->a->id, $meioDia));
        $this->assertSame(0, $guard->dayCount($this->b->id, $meioDia));
        $this->assertSame(0, $guard->weekCount($this->b->id, $contatoB->id, $meioDia));

        // Opt-out por PALAVRA na B revoga SO o contato da B (trilha por conta).
        $this->webhook('inst-b', 'parar', 'P1');
        $this->assertFalse((bool) $contatoB->fresh()->proactive_opt_in);
        $this->assertTrue((bool) $contatoA->fresh()->proactive_opt_in);
        $this->assertSame(1, \App\Models\ProactiveConsent::withoutAccountScope()->where('account_id', $this->b->id)->count());
        $this->assertSame(0, \App\Models\ProactiveConsent::withoutAccountScope()->where('account_id', $this->a->id)->count());
    }

    // ---- Campanhas (P-2): resolucao/targets por conta --------------------------------------

    public function test_campanhas_publico_e_targets_nao_cruzam_contas(): void
    {
        // Tags HOMONIMAS ("vip") nas duas contas; contatos com MESMO jid e opt-in.
        $tagA = \App\Models\Tag::create(['account_id' => $this->a->id, 'name' => 'vip']);
        $tagB = \App\Models\Tag::create(['account_id' => $this->b->id, 'name' => 'vip']);
        $contatoA = Contact::withoutAccountScope()->where('account_id', $this->a->id)->where('remote_jid', self::JID)->first();
        $contatoB = Contact::withoutAccountScope()->where('account_id', $this->b->id)->where('remote_jid', self::JID)->first();
        $contatoA->update(['proactive_opt_in' => true]);
        $contatoB->update(['proactive_opt_in' => true]);
        $contatoA->tags()->attach($tagA->id, ['origin' => 'manual']);
        $contatoB->tags()->attach($tagB->id, ['origin' => 'manual']);

        // Resolver da conta A com a tag da A: SO o contato da A (a B tem tag homonima).
        $res = app(\App\Whatsapp\Proactive\AudienceResolver::class)
            ->resolve($this->a->id, 'tags', ['tag_ids' => [$tagA->id]]);
        $this->assertSame([$contatoA->id], $res['eligiveis']->pluck('id')->all());

        // Resolver da A com o ID da tag DA B: vazio (join valida a conta da tag).
        $res = app(\App\Whatsapp\Proactive\AudienceResolver::class)
            ->resolve($this->a->id, 'tags', ['tag_ids' => [$tagB->id]]);
        $this->assertCount(0, $res['eligiveis']);

        // Campanha da B invisivel na tela da conta A (contexto default).
        \App\Models\ProactiveCampaign::create([
            'account_id' => $this->b->id, 'name' => 'CAMPANHA-SECRETA-DA-B', 'message' => 'oi',
            'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => [$contatoB->id]], 'status' => 'draft',
        ]);
        app(AccountContext::class)->clear();
        Livewire::test(\App\Livewire\Campanhas::class)->assertDontSee('CAMPANHA-SECRETA-DA-B');
        Http::assertNothingSent();
    }

    // ---- Proativas (P-3): tick/dispatch por conta -------------------------------------------

    public function test_tick_da_conta_a_nao_enfileira_targets_da_b(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 7, 1, 10, 0, 0, 'America/Sao_Paulo'));

        // SO a conta A liga o interruptor; as duas tem campanha aprovada vencida.
        AutoReplySetting::withoutAccountScope()->where('account_id', $this->a->id)->update(['proactive_enabled' => true]);
        foreach ([[$this->a, true], [$this->b, false]] as [$conta, $ligada]) {
            $contato = Contact::withoutAccountScope()->where('account_id', $conta->id)->where('remote_jid', self::JID)->first();
            $contato->update(['proactive_opt_in' => true]);
            $camp = \App\Models\ProactiveCampaign::create([
                'account_id' => $conta->id, 'name' => 'C', 'message' => 'oi',
                'audience_type' => 'contatos', 'audience_config' => ['contact_ids' => [$contato->id]],
                'status' => 'approved',
            ]);
            \App\Models\CampaignTarget::create([
                'campaign_id' => $camp->id, 'contact_id' => $contato->id,
                'status' => 'pending', 'scheduled_at' => now()->subMinute(),
            ]);
        }

        $this->artisan('proactive:tick')->assertSuccessful();

        // UM job (da A); NENHUM da B (switch OFF).
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendProactiveMessage::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendProactiveMessage::class,
            fn ($job) => $job->accountId === $this->a->id);
        \Illuminate\Support\Carbon::setTestNow();
    }

    // ---- Painel (M-1): agregados por conta ------------------------------------------------

    public function test_painel_agrega_so_os_dados_da_conta_do_contexto(): void
    {
        // Movimento SO na conta A (webhook processa e responde).
        $this->webhook('inst-a', 'qual o horario?', 'PM1');

        $metrics = app(\App\Metrics\PainelMetrics::class);
        $a = $metrics->dados($this->a->id, '7d');
        $b = $metrics->dados($this->b->id, '7d');

        $this->assertSame(1, $a['resumo']['recebidas']);
        $this->assertSame(1, $a['resumo']['enviadas']);
        // Conta B (mesmo jid espelhado): ZERO em tudo.
        $this->assertSame(0, $b['resumo']['recebidas']);
        $this->assertSame(0, $b['resumo']['enviadas']);
        $this->assertNull($b['resumo']['mediana_primeira_resposta']);
    }

    // ---- Variaveis (V-1): homonimas nao se cruzam; saudacao por conta ----------------------

    public function test_variaveis_homonimas_em_contas_espelhadas_nao_se_cruzam(): void
    {
        // Cada conta ganhou a SUA {saudacao} de sistema no provisionamento.
        $this->assertSame(2, \App\Models\Variable::withoutAccountScope()->where('name', 'saudacao')->where('is_system', true)->count());

        // Variavel HOMONIMA ("empresa") nas duas contas, valores distintos.
        $writer = app(\App\Variables\VariableWriter::class);
        $writer->save($this->a->id, ['name' => 'empresa', 'type' => 'static', 'config' => ['valor' => 'EMPRESA-A'], 'active' => true]);
        $writer->save($this->b->id, ['name' => 'empresa', 'type' => 'static', 'config' => ['valor' => 'EMPRESA-B'], 'active' => true]);

        // Regras espelhadas (MESMO gatilho) usando a variavel: cada webhook
        // renderiza com o valor DA PROPRIA conta.
        foreach ([$this->a, $this->b] as $acc) {
            $r = AutoReplyRule::create(['account_id' => $acc->id, 'match_type' => 'contains', 'match_value' => 'quem e voce', 'response_text' => 'Sou a {empresa}', 'enabled' => true]);
            $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'quem e voce']);
            $r->responses()->create(['response_text' => 'Sou a {empresa}']);
        }
        $this->webhook('inst-a', 'quem e voce?', 'VA1');
        Http::assertSent(fn ($r) => $r['text'] === 'Sou a EMPRESA-A');
        $this->webhook('inst-b', 'quem e voce?', 'VB1');
        Http::assertSent(fn ($r) => $r['text'] === 'Sou a EMPRESA-B');

        // Editar a {saudacao} da B NAO muda a da A (cache e config por conta).
        $sB = \App\Models\Variable::withoutAccountScope()->where('account_id', $this->b->id)->where('name', 'saudacao')->first();
        $writer->save($this->b->id, ['name' => 'saudacao', 'type' => 'horario', 'config' => [
            'faixas' => [['inicio' => '05:00', 'fim' => '11:59', 'valor' => 'SAUDACAO-DA-B']],
            'valor_padrao' => 'Boa noite',
        ]], $sB->id);
        $ctx = app(AccountContext::class);
        $responder = app(\App\Whatsapp\AutoReply\RuleResponder::class);
        $this->assertSame('SAUDACAO-DA-B', $ctx->runAs($this->b->id, fn () => $responder->render('{saudacao}')));
        $this->assertSame('Bom dia', $ctx->runAs($this->a->id, fn () => $responder->render('{saudacao}'))); // 10h, default intacto

        // Tela /variaveis no contexto A: so os valores da A.
        $ctx->clear(); // fallback = conta A
        Livewire::test(\App\Livewire\Variaveis::class)
            ->assertSee('EMPRESA-A')
            ->assertDontSee('EMPRESA-B')
            ->assertDontSee('SAUDACAO-DA-B');
    }

    // ---- token de webhook por canal (retrocompat) --------------------------------------

    public function test_token_por_canal_autentica_e_o_secret_global_segue_valendo(): void
    {
        config(['services.webhook.secret' => 'segredo-global', 'services.webhook.header' => 'X-Webhook-Secret']);
        $payload = ['event' => 'messages.upsert', 'instance' => 'inst-a', 'data' => [
            'key' => ['id' => 'T1', 'fromMe' => false, 'remoteJid' => self::JID],
            'messageType' => 'conversation', 'message' => ['conversation' => 'oi'], 'messageTimestamp' => 1782699162,
        ]];

        // 1. Token por canal na URL (novo): aceito SEM header.
        $this->postJson('/webhook/evolution/token-aaaa', $payload)->assertOk();

        // 2. Token invalido: 401.
        $this->postJson('/webhook/evolution/token-invalido', $payload)->assertUnauthorized();

        // 3. URL antiga sem header: 401 (como sempre).
        $this->postJson('/webhook/evolution', $payload)->assertUnauthorized();

        // 4. Retrocompat (DEPRECADO): URL antiga + secret global no header segue OK —
        //    a Evolution em producao continua funcionando sem reconfiguracao.
        //    (por ultimo: withHeaders persiste os headers default do TestCase)
        $this->withHeaders(['X-Webhook-Secret' => 'segredo-global'])
            ->postJson('/webhook/evolution', $payload)->assertOk();
    }
}

/** Driver falso do gate — "nenhuma regra casou" / answer nao-fundamentado; guarda o request. */
class FakeTenantAi implements \App\Contracts\AiClassifier
{
    public ?\App\Ai\AiAnswerRequest $lastAnswerRequest = null;

    public function classify(\App\Ai\AiClassificationRequest $request): \App\Ai\AiClassification
    {
        return new \App\Ai\AiClassification('', 0.9, null, false, false, 'nenhuma');
    }

    public function answer(\App\Ai\AiAnswerRequest $request): \App\Ai\AiAnswer
    {
        $this->lastAnswerRequest = $request;

        return new \App\Ai\AiAnswer('', false, 0.1, false, [], 'nao achei', null);
    }
}
