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
