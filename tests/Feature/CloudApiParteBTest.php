<?php

namespace Tests\Feature;

use App\Channels\CloudApi\CloudApiProvider;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CH-2 PARTE B — o que entrou ALEM da Parte A, validado contra a doc oficial
 * da Meta (alcancavel do VPS): dedup por wamid com TTL (at-least-once, retries
 * por 36h) SEM risco de perder mensagem em retry do proprio job; normalizacao
 * de numero BR (9o digito do wa_id modificado pela Cloud API — casa o contato
 * que JA existe, nunca duplica); resposta reativa como reply CONTEXTUAL
 * (context.message_id = wamid do inbound); janela de 24h respeitada ATE no
 * modo auto (fila represada); shape VERBATIM do exemplo oficial da doc.
 */
class CloudApiParteBTest extends TestCase
{
    use RefreshDatabase;

    private const PNID = '106540352242922'; // do exemplo oficial da doc
    private const APP_SECRET = 'app-secret-b';
    private const VERIFY = 'verif-b';

    private Account $account;
    private Channel $cloud;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'America/Sao_Paulo'));
        config(['services.cloud_api.graph_base' => 'https://graph.facebook.com']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $this->account = Account::create(['name' => 'B']);
        $this->cloud = Channel::create([
            'account_id' => $this->account->id, 'instance' => self::PNID,
            'provider' => 'cloud_api', 'webhook_token' => 'tok-b', 'status' => 'connected',
            'credentials' => [
                'access_token' => 'tok-meta', 'phone_number_id' => self::PNID,
                'waba_id' => '102290129340398', 'verify_token' => self::VERIFY, 'app_secret' => self::APP_SECRET,
            ],
        ]);
        AutoReplySetting::create([
            'account_id' => $this->account->id, 'enabled' => true, 'reply_policy' => 'all',
            'min_interval_seconds' => 0, 'per_minute_cap' => 100, 'per_day_cap' => 100,
            'contact_rate_seconds' => 0, 'contact_rate_enabled' => false,
            'delay_min_seconds' => 0, 'delay_max_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------

    private function payloadMeta(string $texto, string $wamid, string $from): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => '102290129340398',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => self::PNID],
                        'contacts' => [['profile' => ['name' => 'Cliente B'], 'wa_id' => $from]],
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

    private function postAssinado(array $payload)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/webhook/cloud/tok-b', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $raw, self::APP_SECRET),
        ], $raw);
    }

    private function regra(string $gatilho, string $resposta): void
    {
        $r = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => $gatilho, 'response_text' => $resposta, 'enabled' => true]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => $gatilho]);
        $r->responses()->create(['response_text' => $resposta]);
    }

    private function contato(string $jid): Contact
    {
        return Contact::create([
            'account_id' => $this->account->id, 'remote_jid' => $jid,
            'auto_reply_mode' => 'on', 'push_name' => 'Cliente B',
        ]);
    }

    // ---- shape VERBATIM da doc oficial ---------------------------------------------

    public function test_payload_verbatim_do_exemplo_oficial_da_meta_e_adaptado(): void
    {
        // Copia fiel do exemplo publicado (payload-examples), so com o pnid do canal.
        $oficial = json_decode('{"object":"whatsapp_business_account","entry":[{"id":"102290129340398","changes":[{"value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"15550783881","phone_number_id":"106540352242922"},"contacts":[{"profile":{"name":"Sheena Nelson"},"wa_id":"16505551234"}],"messages":[{"from":"16505551234","id":"wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQTRBNjU5OUFFRTAzODEwMTQ0RgA=","timestamp":"1749416383","type":"text","text":{"body":"Does it come in another color?"}}]},"field":"messages"}]}]}', true);

        $dto = app(CloudApiProvider::class)->normalizeIncoming($oficial);
        $this->assertNotNull($dto);
        $this->assertSame(self::PNID, $dto->instance);
        $this->assertSame('wamid.HBgLMTY1MDM4Nzk0MzkVAgASGBQzQTRBNjU5OUFFRTAzODEwMTQ0RgA=', $dto->providerMessageId);
        $this->assertSame('16505551234@s.whatsapp.net', $dto->remoteJid); // nao-BR: intocado
        $this->assertSame('Sheena Nelson', $dto->pushName);
        $this->assertSame('Does it come in another color?', $dto->text);

        // Status VERBATIM (delivered, com conversation/pricing): ignorado (D5).
        $status = json_decode('{"object":"whatsapp_business_account","entry":[{"id":"102290129340398","changes":[{"value":{"messaging_product":"whatsapp","metadata":{"display_phone_number":"15550783881","phone_number_id":"106540352242922"},"statuses":[{"id":"wamid.X","status":"delivered","timestamp":"1750263773","recipient_id":"16505551234","conversation":{"id":"6ceb","origin":{"type":"service"}},"pricing":{"billable":true,"pricing_model":"CBP","category":"service"}}]},"field":"messages"}]}]}', true);
        $this->assertNull(app(CloudApiProvider::class)->normalizeIncoming($status));
    }

    // ---- dedup por wamid com TTL ----------------------------------------------------

    public function test_reentrega_da_meta_e_deduplicada_e_nao_gera_segunda_resposta(): void
    {
        $this->regra('preco', 'Tabela em anexo.');
        $this->contato('16505551234@s.whatsapp.net');

        $this->postAssinado($this->payloadMeta('qual o preco?', 'wamid.DED1', '16505551234'))->assertOk();
        $this->postAssinado($this->payloadMeta('qual o preco?', 'wamid.DED1', '16505551234'))->assertOk(); // retry da Meta

        $this->assertSame(1, IncomingMessage::withoutAccountScope()->where('evolution_message_id', 'wamid.DED1')->count());
        Http::assertSentCount(1); // UMA resposta, nunca em dobro
    }

    public function test_retry_do_proprio_job_nunca_perde_mensagem_mesmo_com_chave_de_dedup_ja_vista(): void
    {
        // Simula falha parcial: a chave de dedup foi claimada numa tentativa
        // anterior que MORREU antes de persistir. O retry precisa processar.
        Cache::add('cloud:dedup:' . sha1('wamid.RET1'), 1, now()->addHours(48));

        $this->postAssinado($this->payloadMeta('oi', 'wamid.RET1', '16505551234'))->assertOk();

        $this->assertSame(1, IncomingMessage::withoutAccountScope()->where('evolution_message_id', 'wamid.RET1')->count());
    }

    // ---- normalizacao de numero BR (9o digito) ---------------------------------------

    public function test_wa_id_br_sem_nono_digito_casa_o_contato_existente_com_nono(): void
    {
        // Contato criado pela Evolution com o 9 (forma moderna de celular BR).
        $this->contato('5541988887777@s.whatsapp.net');
        $antes = Contact::query()->count();

        // A Meta entrega o wa_id SEM o 9 (comportamento documentado BR/MX).
        $this->postAssinado($this->payloadMeta('oi', 'wamid.BR1', '554188887777'))->assertOk();

        $this->assertDatabaseHas('incoming_messages', [
            'evolution_message_id' => 'wamid.BR1',
            'remote_jid' => '5541988887777@s.whatsapp.net', // casou a forma EXISTENTE
        ]);
        $this->assertSame($antes, Contact::query()->count()); // NAO duplicou contato
    }

    public function test_wa_id_br_com_nono_digito_casa_o_contato_existente_sem_nono(): void
    {
        $this->contato('554188886666@s.whatsapp.net'); // historico sem o 9
        $antes = Contact::query()->count();

        $this->postAssinado($this->payloadMeta('oi', 'wamid.BR2', '5541988886666'))->assertOk();

        $this->assertDatabaseHas('incoming_messages', [
            'evolution_message_id' => 'wamid.BR2',
            'remote_jid' => '554188886666@s.whatsapp.net',
        ]);
        $this->assertSame($antes, Contact::query()->count());
    }

    public function test_wa_id_br_sem_contato_existente_mantem_a_forma_recebida(): void
    {
        $this->postAssinado($this->payloadMeta('oi', 'wamid.BR3', '554177775555'))->assertOk();

        $this->assertDatabaseHas('incoming_messages', [
            'evolution_message_id' => 'wamid.BR3',
            'remote_jid' => '554177775555@s.whatsapp.net', // como veio da Meta
        ]);
        // E se a forma recebida JA existe, ela vence mesmo com variante tambem
        // existente (nunca troca o certo pelo talvez).
        $this->contato('554177774444@s.whatsapp.net');
        $this->contato('5541977774444@s.whatsapp.net');
        $this->postAssinado($this->payloadMeta('oi', 'wamid.BR4', '554177774444'))->assertOk();
        $this->assertDatabaseHas('incoming_messages', [
            'evolution_message_id' => 'wamid.BR4',
            'remote_jid' => '554177774444@s.whatsapp.net',
        ]);
    }

    // ---- reply contextual -------------------------------------------------------------

    public function test_resposta_reativa_no_cloud_e_reply_contextual_do_wamid_recebido(): void
    {
        $this->regra('horario', 'Das 8h as 18h.');
        $this->contato('16505551234@s.whatsapp.net');

        $this->postAssinado($this->payloadMeta('qual o horario?', 'wamid.CTX1', '16505551234'))->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages')
            && data_get($req->data(), 'context.message_id') === 'wamid.CTX1'
            && data_get($req->data(), 'text.body') === 'Das 8h as 18h.');
    }

    public function test_envio_manual_sem_incoming_nao_carrega_context(): void
    {
        $contato = $this->contato('16505551234@s.whatsapp.net');
        // Janela aberta pra permitir o manual no cloud.
        \App\Models\ContactChannelWindow::touchWindow($this->account->id, (int) $contato->id, (int) $this->cloud->id);

        $log = app(Sender::class)->send(mode: 'manual', channel: $this->cloud, jid: '16505551234@s.whatsapp.net', text: 'oi manual');

        $this->assertSame('sent', $log->status);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/messages')
            && data_get($req->data(), 'context') === null);
    }

    // ---- janela de 24h tambem no modo auto ----------------------------------------------

    public function test_auto_com_janela_fechada_e_bloqueado_sem_tentar_free_form(): void
    {
        $this->contato('16505551234@s.whatsapp.net');

        // Inbound abre a janela; o processamento do auto SO acontece 25h depois
        // (fila patologicamente represada — o caso que a Parte B cobre).
        $this->postAssinado($this->payloadMeta('sem regra que case', 'wamid.JAN1', '16505551234'))->assertOk();
        $im = IncomingMessage::query()->where('evolution_message_id', 'wamid.JAN1')->firstOrFail();

        Carbon::setTestNow(now()->addHours(25));

        $log = app(Sender::class)->send(
            mode: 'auto', channel: $this->cloud, jid: '16505551234@s.whatsapp.net',
            text: 'resposta atrasada', incomingMessageId: (int) $im->id,
        );

        $this->assertSame('blocked', $log->status);
        $this->assertSame('janela_24h', $log->motivo);
        Http::assertNothingSent(); // "sem regra que case" nao respondeu; o atrasado nao tenta
    }
}
