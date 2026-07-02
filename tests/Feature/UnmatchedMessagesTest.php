<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Livewire\Painel;
use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Models\AutoReplySetting;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\UnmatchedMessage;
use App\Whatsapp\AutoReply\AntiBanGuard;
use App\Whatsapp\AutoReply\RuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MATCH-1 — log de sem-match: silencio ELEGIVEL vira registro (grupo, opt-out e
 * nao-aprovado NAO entram); IA que silencia tambem registra; prune >30d; painel
 * mostra contagem/frequencia; "virar regra" cria pelo RuleWriter e o item some;
 * isolamento entre contas.
 */
class UnmatchedMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const JID = '5541999990000@s.whatsapp.net';

    private Account $account;
    private Channel $channel;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 7, 3, 10, 0, 0, 'America/Sao_Paulo'));
        Http::fake(['*' => Http::response(['key' => ['id' => 'PMID']], 201)]);

        $this->account = Account::create(['name' => 'T']);
        $this->channel = Channel::create(['account_id' => $this->account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);
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

    private function webhook(string $texto, string $id, string $jid = self::JID, bool $fromMe = false): void
    {
        (new ProcessIncomingWhatsappMessage([
            'event' => 'messages.upsert', 'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => $id, 'fromMe' => $fromMe, 'remoteJid' => $jid],
                'pushName' => 'Cliente', 'messageType' => 'conversation',
                'message' => ['conversation' => $texto], 'messageTimestamp' => 1782699162,
            ],
        ]))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(RuleMatcher::class),
            app(AntiBanGuard::class),
        );
    }

    // ---- gravacao no silencio elegivel ---------------------------------------------

    public function test_silencio_elegivel_grava_e_matches_respostas_nao_gravam(): void
    {
        $r = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'horario', 'response_text' => 'Das 8 as 18.', 'enabled' => true]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'horario']);
        $r->responses()->create(['response_text' => 'Das 8 as 18.']);

        // Casa regra: NAO grava.
        $this->webhook('qual o horario?', 'U1');
        $this->assertDatabaseCount('unmatched_messages', 0);

        // Nao casa nada + IA off: GRAVA (com contato e texto truncavel).
        $this->webhook('voces vendem parafuso M8?', 'U2');
        $this->assertDatabaseHas('unmatched_messages', [
            'account_id' => $this->account->id,
            'contact_id' => $this->contact->id,
            'text' => 'voces vendem parafuso M8?',
        ]);
    }

    public function test_grupo_opt_out_e_nao_aprovado_nao_gravam(): void
    {
        // Grupo: fora.
        $this->webhook('alguem sabe?', 'U3', '123456@g.us');
        // Opt-out (off): fora.
        $this->contact->update(['auto_reply_mode' => 'off']);
        $this->webhook('sem resposta pra mim', 'U4');
        // fromMe: fora.
        $this->webhook('nota mental', 'U5', self::JID, true);
        // Nao aprovado sob allowlist: fora.
        AutoReplySetting::where('account_id', $this->account->id)->update(['reply_policy' => 'allowlist']);
        Contact::create(['account_id' => $this->account->id, 'remote_jid' => '5541888880000@s.whatsapp.net', 'auto_reply_mode' => 'default']);
        $this->webhook('oi, quero orcamento', 'U6', '5541888880000@s.whatsapp.net');

        $this->assertDatabaseCount('unmatched_messages', 0);
    }

    public function test_ia_que_silencia_tambem_grava(): void
    {
        AutoReplySetting::where('account_id', $this->account->id)->update(['ai_enabled' => true]);
        $this->contact->update(['ai_enabled' => true, 'ai_mode' => 'intencao']);
        $im = \App\Models\IncomingMessage::create([
            'account_id' => $this->account->id, 'channel_id' => $this->channel->id,
            'instance' => 'fabio-pessoal', 'evolution_message_id' => 'AI-U1',
            'remote_jid' => self::JID, 'from_me' => false, 'push_name' => 'Cliente',
            'type' => 'conversation', 'text' => 'pergunta sem regra', 'raw_payload' => [], 'received_at' => now(),
        ]);

        // Driver falso: nenhuma intencao -> decideByRule loga 'silenciou' (sem_regra).
        $fake = new class implements \App\Contracts\AiClassifier
        {
            public function classify(\App\Ai\AiClassificationRequest $r): \App\Ai\AiClassification
            {
                return new \App\Ai\AiClassification('', 0.9, null, false, false, 'nenhuma');
            }

            public function answer(\App\Ai\AiAnswerRequest $r): \App\Ai\AiAnswer
            {
                return new \App\Ai\AiAnswer('', false, 0.1, false, [], 'nao achei', null);
            }
        };
        // Regra candidata da IA (senao e silencio estrutural — tambem gravaria).
        $r = AutoReplyRule::create(['account_id' => $this->account->id, 'match_type' => 'contains', 'match_value' => 'preco', 'response_text' => 'x', 'enabled' => true, 'ai_match_enabled' => true]);
        $r->triggers()->create(['match_type' => 'contains', 'match_value' => 'preco']);
        $r->responses()->create(['response_text' => 'x']);

        (new \App\Jobs\ClassifyWithAi($im->id, $this->account->id))->handle(
            $fake, app(AntiBanGuard::class), app(RuleMatcher::class),
            app(\App\Whatsapp\Secrets\SecretVault::class),
            app(\App\Whatsapp\AutoReply\RuleResponder::class),
        );

        $this->assertDatabaseHas('ai_decisions', ['incoming_message_id' => $im->id, 'acao' => 'silenciou']);
        $this->assertDatabaseHas('unmatched_messages', ['account_id' => $this->account->id, 'text' => 'pergunta sem regra']);
    }

    // ---- prune ------------------------------------------------------------------------

    public function test_prune_apaga_somente_acima_de_30_dias(): void
    {
        UnmatchedMessage::record($this->account->id, self::JID, 'recente');
        $velha = UnmatchedMessage::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'contact_id' => null, 'text' => 'velha',
        ]);
        $velha->forceFill(['created_at' => now()->subDays(31)])->saveQuietly();

        $this->artisan('unmatched:prune')->assertSuccessful();

        $this->assertDatabaseHas('unmatched_messages', ['text' => 'recente']);
        $this->assertDatabaseMissing('unmatched_messages', ['text' => 'velha']);
    }

    // ---- painel + virar regra ------------------------------------------------------------

    public function test_painel_conta_agrupa_e_virar_regra_cria_pelo_caminho_oficial(): void
    {
        $this->webhook('voces tem estoque?', 'P1');
        $this->webhook('Voces tem estoque?', 'P2'); // repete (frequencia)
        $this->webhook('outra pergunta perdida', 'P3');
        // OBS: os textos diferem por caixa -> agrupamento e por texto CRU truncado;
        // os dois "estoque" diferem em 1 char, entao valem como itens separados.

        $comp = Livewire::test(Painel::class)
            ->assertSee('Sem resposta')
            ->assertSee('voces tem estoque?')
            ->assertSee('outra pergunta perdida');

        $id = (int) UnmatchedMessage::query()->where('text', 'voces tem estoque?')->value('id');
        $comp->call('abrirVirarRegra', $id)
            ->assertSet('uTrigger', 'voces tem estoque?')
            ->set('uResponse', 'Temos sim, {nome}! O que voce procura?')
            ->call('confirmVirarRegra')
            ->assertHasNoErrors();

        // Regra criada pelo caminho oficial, gatilho TOLERANTE, e o item sumiu.
        $this->assertDatabaseHas('rule_triggers', [
            'match_value' => 'voces tem estoque?', 'precision' => 'tolerante',
            'normalized_text' => 'voces tem estoque',
        ]);
        $this->assertDatabaseMissing('unmatched_messages', ['text' => 'voces tem estoque?']);
        $this->assertDatabaseHas('unmatched_messages', ['text' => 'outra pergunta perdida']);

        // E a proxima mensagem igual JA e respondida (fim do ciclo).
        $this->webhook('VOCES TEM ESTOQUE?!', 'P4');
        Http::assertSent(fn ($r) => str_contains((string) $r['text'], 'Temos sim'));
        $this->assertDatabaseMissing('unmatched_messages', ['text' => 'VOCES TEM ESTOQUE?!']);
    }

    public function test_virar_regra_com_senha_e_barrado_pelas_guardas_do_writer(): void
    {
        app(\App\Whatsapp\Secrets\SecretVault::class)->put($this->account->id, 'wifi', 'segredo123');
        UnmatchedMessage::record($this->account->id, self::JID, 'qual a senha?');
        $id = (int) UnmatchedMessage::query()->value('id');

        Livewire::test(Painel::class)
            ->call('abrirVirarRegra', $id)
            ->set('uResponse', 'Senha: {senha:wifi}')
            ->call('confirmVirarRegra')
            ->assertHasErrors('uResponse'); // S5: senha exige escopo contatos (writer barrou)

        $this->assertDatabaseHas('unmatched_messages', ['text' => 'qual a senha?']); // nada some
    }
}
