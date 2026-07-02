<?php

namespace Tests\Feature;

use App\Channels\CloudApi\CloudApiProvider;
use App\Livewire\Revisao;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactChannelWindow;
use App\Models\PendingApproval;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CH-2 — CloudApiProvider (canal oficial da Meta, reativo-only). MOCK TOTAL —
 * nunca API real. Provas: challenge GET + HMAC no POST (corpo cru, app secret
 * do canal); adaptador Meta -> MESMO DTO (wa_id -> JID na borda, wamid na
 * idempotencia); resposta SEMPRE pelo canal de ENTRADA (cloud e Evolution no
 * mesmo teste); janela de 24h POR CONTATO+CANAL (Evolution nao abre a do cloud;
 * manual/aprovacao bloqueados so no cloud com janela fechada + countdown na
 * pendencia); capacidades (proativa = 10o freio); MATCH-1 valendo no cloud.
 */
class CloudApiProviderTest extends TestCase
{
    use RefreshDatabase;

    private const PNID = '111222333444555';
    private const WA_ID = '554188887777';
    private const JID = self::WA_ID . '@s.whatsapp.net';
    private const APP_SECRET = 'app-secret-teste';
    private const VERIFY = 'verif-123';

    private Account $account;
    private Channel $evo;
    private Channel $cloud;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'America/Sao_Paulo'));
        config([
            'services.evolution.base_url' => 'http://evo-host:8090',
            'services.evolution.api_key' => 'chave-evo',
            'services.cloud_api.graph_base' => 'https://graph.facebook.com',
            'services.cloud_api.graph_version' => 'v21.0',
        ]);
        Http::fake([
            'evo-host:8090/*' => Http::response(['key' => ['id' => 'EVOMSG']], 201),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SAIDA1']]], 200),
        ]);

        $this->account = Account::create(['name' => 'T']);
        $this->evo = Channel::create([
            'account_id' => $this->account->id, 'instance' => 'fabio-pessoal',
            'provider' => 'evolution', 'webhook_token' => 'tok-evo', 'status' => 'connected',
        ]);
        $this->cloud = Channel::create([
            'account_id' => $this->account->id, 'instance' => self::PNID,
            'provider' => 'cloud_api', 'webhook_token' => 'tok-cloud', 'status' => 'connected',
            'credentials' => [
                'access_token' => 'token-meta-teste', 'phone_number_id' => self::PNID,
                'waba_id' => '999888777', 'verify_token' => self::VERIFY, 'app_secret' => self::APP_SECRET,
            ],
        ]);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
        $this->contact = Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => self::JID,
            'auto_reply_mode' => 'on', 'push_name' => 'Cliente',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    private function payloadMeta(string $texto, string $wamid, string $from = self::WA_ID): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '999888777',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550000000', 'phone_number_id' => self::PNID],
                        'contacts' => [['profile' => ['name' => 'Cliente Cloud'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from, 'id' => $wamid,
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text', 'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function postAssinado(array $payload, ?string $secret = self::APP_SECRET, string $token = 'tok-cloud')
    {
        $raw = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', $raw, $secret);
        }

        return $this->call('POST', '/webhook/cloud/' . $token, [], [], [], $server, $raw);
    }

    // ---- challenge (GET) --------------------------------------------------------------

    public function test_challenge_get_responde_o_hub_challenge_e_recusa_verify_token_errado(): void
    {
        $this->get('/webhook/cloud/tok-cloud?hub.mode=subscribe&hub.verify_token=' . self::VERIFY . '&hub.challenge=424242')
            ->assertOk()->assertSee('424242', false);

        // Parte B: verify_token errado em canal CLOUD = 403 (contrato da Meta).
        $this->get('/webhook/cloud/tok-cloud?hub.mode=subscribe&hub.verify_token=errado&hub.challenge=1')
            ->assertForbidden();

        // Token de rota DESCONHECIDO: 401 (nao ha canal pra saber o provider).
        $this->get('/webhook/cloud/token-inexistente?hub.mode=subscribe&hub.verify_token=' . self::VERIFY . '&hub.challenge=1')
            ->assertUnauthorized();
    }

    // ---- HMAC (POST) ---------------------------------------------------------------------

    public function test_post_com_hmac_valida_processa_e_invalida_ou_ausente_e_403(): void
    {
        // Valida: 200, mensagem persistida NO CANAL cloud com wamid na idempotencia.
        $this->postAssinado($this->payloadMeta('oi', 'wamid.IN1'))->assertOk();
        $this->assertDatabaseHas('incoming_messages', [
            'evolution_message_id' => 'wamid.IN1', 'account_id' => $this->account->id,
            'channel_id' => $this->cloud->id, 'instance' => self::PNID,
            'remote_jid' => self::JID, // wa_id -> JID canonico NA BORDA
        ]);

        // Assinatura ERRADA: 403, nada persistido (Parte B: contrato da Meta).
        $this->postAssinado($this->payloadMeta('x', 'wamid.IN2'), 'segredo-errado')->assertForbidden();
        // Sem assinatura: 403.
        $this->postAssinado($this->payloadMeta('x', 'wamid.IN3'), null)->assertForbidden();
        $this->assertDatabaseMissing('incoming_messages', ['evolution_message_id' => 'wamid.IN2']);
        $this->assertDatabaseMissing('incoming_messages', ['evolution_message_id' => 'wamid.IN3']);
    }

    public function test_dedupe_por_wamid_na_chave_de_idempotencia_existente(): void
    {
        $this->postAssinado($this->payloadMeta('oi', 'wamid.DUP'))->assertOk();
        $this->postAssinado($this->payloadMeta('oi', 'wamid.DUP'))->assertOk(); // re-entrega

        $this->assertSame(1, \App\Models\IncomingMessage::withoutAccountScope()
            ->where('evolution_message_id', 'wamid.DUP')->count());
    }

    // ---- adaptador -----------------------------------------------------------------------------

    public function test_adaptador_converte_payload_meta_pro_dto_neutro_e_ignora_statuses(): void
    {
        $provider = app(CloudApiProvider::class);

        $dto = $provider->normalizeIncoming($this->payloadMeta('Que horas são?', 'wamid.ADP'));
        $this->assertNotNull($dto);
        $this->assertSame(self::PNID, $dto->instance);
        $this->assertSame('wamid.ADP', $dto->providerMessageId);
        $this->assertSame(self::JID, $dto->remoteJid);
        $this->assertFalse($dto->fromMe);
        $this->assertSame('Cliente Cloud', $dto->pushName);
        $this->assertSame('text', $dto->type);
        $this->assertSame('Que horas são?', $dto->text);

        // Payload de STATUS (delivered/read): ignorado com log leve (D5).
        $status = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => self::PNID],
            'statuses' => [['id' => 'wamid.X', 'status' => 'delivered']],
        ]]]]]];
        $this->assertNull($provider->normalizeIncoming($status));

        // Nao-texto (imagem com caption): catch-all, nunca descarta.
        $img = $this->payloadMeta('x', 'wamid.IMG');
        $img['entry'][0]['changes'][0]['value']['messages'][0] = [
            'from' => self::WA_ID, 'id' => 'wamid.IMG', 'timestamp' => (string) now()->timestamp,
            'type' => 'image', 'image' => ['id' => 'MID', 'caption' => 'olha isso'],
        ];
        $dto = $provider->normalizeIncoming($img);
        $this->assertSame('image', $dto->type);
        $this->assertSame('olha isso', $dto->text);
    }

    // ---- resposta pelo canal de ENTRADA (os dois no mesmo teste) --------------------------------

    public function test_resposta_sai_pelo_canal_em_que_a_mensagem_chegou(): void
    {
        $r = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'horario', 'response_text' => 'Das 8h as 18h.', 'enabled' => true]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'horario']);
        $r->responses()->create(['response_text' => 'Das 8h as 18h.']);

        // 1. Chegou pelo canal CLOUD -> responde pelo Graph API com o token do canal.
        $this->postAssinado($this->payloadMeta('qual o horario?', 'wamid.CANAL1'))->assertOk();
        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com/v21.0/' . self::PNID . '/messages')
            && $req->header('Authorization')[0] === 'Bearer token-meta-teste'
            && data_get($req->data(), 'text.body') === 'Das 8h as 18h.'
            && data_get($req->data(), 'to') === self::WA_ID);

        // 2. MESMA regra, chegada pela EVOLUTION -> responde pelo sendText da Evolution.
        (new \App\Jobs\ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'EVO-1', 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => 'qual o horario?'], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
        Http::assertSent(fn ($req) => str_contains($req->url(), 'evo-host:8090/message/sendText/fabio-pessoal')
            && $req['text'] === 'Das 8h as 18h.');
    }

    // ---- janela de 24h por contato+CANAL ------------------------------------------------------------

    public function test_janela_e_por_contato_e_canal_e_evolution_nao_abre_a_do_cloud(): void
    {
        // Inbound pela EVOLUTION: abre a janela DAQUELE canal, nunca a do cloud.
        (new \App\Jobs\ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'EVO-W1', 'fromMe' => false, 'remoteJid' => self::JID],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => 'oi'], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );

        $this->assertDatabaseHas('contact_channel_windows', ['contact_id' => $this->contact->id, 'channel_id' => $this->evo->id]);
        $this->assertDatabaseMissing('contact_channel_windows', ['contact_id' => $this->contact->id, 'channel_id' => $this->cloud->id]);
        $this->assertFalse(ContactChannelWindow::isOpen($this->account->id, self::JID, $this->cloud->id));

        // Manual pelo canal CLOUD com janela fechada: BLOQUEADO com motivo claro.
        $log = app(Sender::class)->send(mode: 'manual', channel: $this->cloud, jid: self::JID, text: 'oi manual');
        $this->assertSame('blocked', $log->status);
        $this->assertSame('janela_24h', $log->motivo);

        // Manual pela EVOLUTION: sempre pode (comportamento de sempre).
        $log = app(Sender::class)->send(mode: 'manual', channel: $this->evo, jid: self::JID, text: 'oi manual');
        $this->assertSame('sent', $log->status);

        // Inbound pelo CLOUD abre a janela do cloud -> manual passa a poder.
        $this->postAssinado($this->payloadMeta('abrindo janela', 'wamid.W2'))->assertOk();
        $this->assertTrue(ContactChannelWindow::isOpen($this->account->id, self::JID, $this->cloud->id));
        $log = app(Sender::class)->send(mode: 'manual', channel: $this->cloud, jid: self::JID, text: 'agora vai');
        $this->assertSame('sent', $log->status);

        // 25h depois: fechou de novo.
        Carbon::setTestNow(now()->addHours(25));
        $this->assertFalse(ContactChannelWindow::isOpen($this->account->id, self::JID, $this->cloud->id));
    }

    public function test_pendencia_do_canal_oficial_mostra_countdown_da_janela(): void
    {
        // Pendencia cujo INCOMING chegou pelo canal cloud (janela aberta agora).
        $this->postAssinado($this->payloadMeta('quero orcamento', 'wamid.PEN'))->assertOk();
        $im = \App\Models\IncomingMessage::query()->where('evolution_message_id', 'wamid.PEN')->firstOrFail();
        PendingApproval::create([
            'account_id' => $this->account->id, 'contact_id' => $this->contact->id,
            'incoming_message_id' => $im->id, 'remote_jid' => self::JID,
            'suggested_response' => 'Orcamento em anexo.', 'origin' => 'regra',
            'reason' => 'baixa_confianca', 'confidence' => 0.5, 'status' => 'pending',
        ]);

        // Janela ABERTA: countdown discreto (abriu agora -> "resta 24h 00min").
        Livewire::test(Revisao::class)->assertSee('janela de 24h')->assertSee('resta 24h 00min');

        // 25h depois (sem novo inbound): FECHADA, com o aviso do bloqueio.
        Carbon::setTestNow(now()->addHours(25));
        Livewire::test(Revisao::class)->assertSee('FECHADA');
    }

    // ---- capacidades ---------------------------------------------------------------------------------

    public function test_capacidades_do_cloud_e_decimo_freio_de_proativa(): void
    {
        $caps = app(CloudApiProvider::class)->capabilities();
        $this->assertFalse($caps->grupos);
        $this->assertFalse($caps->mensagemLivreForaDaJanela);
        $this->assertFalse($caps->proativaLivre);
        $this->assertFalse($caps->qr);
        $this->assertFalse($caps->template); // CH-3

        // Conta SO com canal cloud: o 10o freio (CH-1) dispara com o provider REAL.
        $b = Account::create(['name' => 'So Cloud']);
        Channel::withoutAccountScope()->create([
            'account_id' => $b->id, 'instance' => '222333444555666', 'provider' => 'cloud_api',
            'webhook_token' => 'tok-cloud-b', 'status' => 'connected',
            'credentials' => ['access_token' => 'x', 'phone_number_id' => '222333444555666', 'waba_id' => 'w', 'verify_token' => 'v', 'app_secret' => 's'],
        ]);
        AutoReplySetting::withoutAccountScope()->create(['account_id' => $b->id, 'proactive_enabled' => true]);
        $contatoB = Contact::withoutAccountScope()->create([
            'account_id' => $b->id, 'remote_jid' => '554177776666@s.whatsapp.net',
            'auto_reply_mode' => 'on', 'proactive_opt_in' => true,
        ]);

        $decision = app(\App\Whatsapp\Proactive\ProactiveGuard::class)
            ->allows($b->id, (int) $contatoB->id, 'oi');
        $this->assertSame('canal_sem_proativa_livre', $decision->reason);
    }

    // ---- MATCH-1 vale igual no caminho cloud -----------------------------------------------------------

    public function test_normalizacao_match1_vale_no_caminho_cloud(): void
    {
        $r = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'Que horas são ?', 'response_text' => 'Agora são {hora} !', 'enabled' => true]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'Que horas são ?']);
        $r->responses()->create(['response_text' => 'Agora são {hora} !']);

        $this->postAssinado($this->payloadMeta('QUE  HORAS SAO!!!', 'wamid.N1'))->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com')
            && str_contains((string) data_get($req->data(), 'text.body'), 'Agora são'));
    }

    // ---- conexao + comando -------------------------------------------------------------------------------

    public function test_connection_state_por_credenciais(): void
    {
        $this->assertSame('connected', app(CloudApiProvider::class)->connectionState($this->cloud));

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'x'], 401)]);
        $this->assertSame('disconnected', app(CloudApiProvider::class)->connectionState($this->cloud));

        $semCred = new Channel(['instance' => 'x', 'provider' => 'cloud_api']);
        $this->assertSame('disconnected', app(CloudApiProvider::class)->connectionState($semCred));
    }

    public function test_comando_cria_canal_cloud_com_segredos_ocultos_e_cifrados(): void
    {
        $this->artisan('msg:channel:create-cloud', ['--account' => $this->account->id])
            ->expectsQuestion('phone_number_id (do painel da Meta, numero de TESTE)', '333444555666777')
            ->expectsQuestion('WABA id (WhatsApp Business Account id)', '888999000')
            ->expectsQuestion('verify_token do webhook (vazio = gero um)', '')
            ->expectsQuestion('access token (oculto; temporario do painel vale ~23h)', 'token-secreto-abc')
            ->expectsQuestion('app secret (oculto; em App settings > Basic)', 'segredo-app-xyz')
            ->assertSuccessful();

        $canal = Channel::withoutAccountScope()->where('instance', '333444555666777')->firstOrFail();
        $this->assertSame('cloud_api', $canal->provider);
        $this->assertSame('token-secreto-abc', $canal->credentials['access_token']);
        $this->assertSame(32, strlen((string) $canal->credentials['verify_token'])); // gerado

        // Cifrado em repouso: os segredos NUNCA aparecem em claro na coluna.
        $cru = (string) \Illuminate\Support\Facades\DB::table('channels')->where('id', $canal->id)->value('credentials');
        $this->assertStringNotContainsString('token-secreto-abc', $cru);
        $this->assertStringNotContainsString('segredo-app-xyz', $cru);
    }
}
