<?php

namespace Tests\Feature;

use App\Livewire\Conversas;
use App\Metrics\PainelMetrics;
use App\Models\Account;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\IncomingMessage;
use App\Jobs\ProcessIncomingWhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Prompt 16 — reacao (curtir/coracao/emoji) NAO e mensagem. Corte na ingestao
 * (choke point) por TIPO EXPLICITO (reactionMessage/Evolution, reaction/Cloud),
 * nunca por texto vazio — midia legitima tambem tem texto nulo e NAO pode cair.
 * Metrica e thread tambem filtram reacao (passado + defesa).
 */
class ReacaoCorteTest extends TestCase
{
    use RefreshDatabase;

    private function evoChannel(Account $a): Channel
    {
        return Channel::create([
            'account_id' => $a->id, 'instance' => 'fabio-pessoal', 'provider' => 'evolution',
            'webhook_token' => 'tok-evo-' . $a->id, 'status' => 'connected',
        ]);
    }

    private function cloudChannel(Account $a): Channel
    {
        return Channel::create([
            'account_id' => $a->id, 'instance' => 'PNID1', 'provider' => 'cloud_api',
            'webhook_token' => 'tok-cloud-' . $a->id, 'status' => 'connected',
            'credentials' => ['access_token' => 'TOK', 'phone_number_id' => 'PNID1'],
        ]);
    }

    private function evoPayload(string $id, string $type, array $message): array
    {
        return ['event' => 'messages.upsert', 'instance' => 'fabio-pessoal', 'data' => [
            'key' => ['id' => $id, 'remoteJid' => '554188887777@s.whatsapp.net', 'fromMe' => false],
            'messageType' => $type, 'message' => $message, 'messageTimestamp' => now()->timestamp,
        ]];
    }

    // ---- Frente 1: corte na ingestao -------------------------------------------

    public function test_reacao_evolution_nao_cria_mensagem(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);

        ProcessIncomingWhatsappMessage::dispatchSync(
            $this->evoPayload('REACT1', 'reactionMessage', ['reactionMessage' => ['key' => ['id' => 'ALVO1'], 'text' => '👍']]),
            $c->id,
        );

        // Nao criou linha => nenhum consumidor (Kanban/regra/IA/metrica) rodou.
        $this->assertSame(0, IncomingMessage::withoutAccountScope()->count());
        $this->assertDatabaseMissing('incoming_messages', ['evolution_message_id' => 'REACT1']);
    }

    public function test_reacao_cloud_nao_cria_mensagem(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->cloudChannel($a);

        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => 'PNID1'],
            'contacts' => [['profile' => ['name' => 'X']]],
            'messages' => [[
                'id' => 'wamid.R1', 'from' => '554188887777', 'type' => 'reaction',
                'reaction' => ['message_id' => 'wamid.ALVO', 'emoji' => '❤️'],
                'timestamp' => (string) now()->timestamp,
            ]],
        ]]]]]];

        ProcessIncomingWhatsappMessage::dispatchSync($payload, $c->id);

        $this->assertSame(0, IncomingMessage::withoutAccountScope()->count());
    }

    // ---- anti-regressao: mensagem legitima continua entrando --------------------

    public function test_texto_normal_continua_criando_mensagem(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);

        ProcessIncomingWhatsappMessage::dispatchSync(
            $this->evoPayload('TXT1', 'conversation', ['conversation' => 'oi tudo bem']),
            $c->id,
        );

        $this->assertDatabaseHas('incoming_messages', ['evolution_message_id' => 'TXT1', 'type' => 'conversation']);
    }

    public function test_midia_com_texto_nulo_continua_criando_mensagem(): void
    {
        // CRITICO: prova que o criterio e TIPO-reacao, nao texto-vazio. Imagem tem text=null.
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);

        ProcessIncomingWhatsappMessage::dispatchSync(
            $this->evoPayload('IMG1', 'imageMessage', ['imageMessage' => ['mimetype' => 'image/jpeg']]),
            $c->id,
        );

        $msg = IncomingMessage::withoutAccountScope()->where('evolution_message_id', 'IMG1')->first();
        $this->assertNotNull($msg, 'Imagem (texto nulo) NAO pode ser barrada pelo corte de reacao.');
        $this->assertSame('imageMessage', $msg->type);
        $this->assertNull($msg->text);
    }

    // ---- Frente 2: metrica ignora reacao ---------------------------------------

    public function test_painel_nao_conta_reacoes(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);
        $base = ['account_id' => $a->id, 'channel_id' => $c->id, 'instance' => 'fabio-pessoal',
            'from_me' => false, 'received_at' => now()];

        IncomingMessage::create($base + ['evolution_message_id' => 'M1', 'remote_jid' => '551199@s.whatsapp.net', 'type' => 'conversation', 'text' => 'oi', 'raw_payload' => ['x' => 1]]);
        IncomingMessage::create($base + ['evolution_message_id' => 'M2', 'remote_jid' => '551199@s.whatsapp.net', 'type' => 'reactionMessage', 'text' => null, 'raw_payload' => ['x' => 1]]);
        IncomingMessage::create($base + ['evolution_message_id' => 'G1', 'remote_jid' => '123@g.us', 'type' => 'reactionMessage', 'text' => null, 'raw_payload' => ['x' => 1]]);

        $d = app(PainelMetrics::class)->dados($a->id, '7d');

        $this->assertSame(1, $d['resumo']['recebidas']); // so a de texto (a reacao 1:1 fora)
        $this->assertSame(0, $d['resumo']['grupos']);     // a reacao de grupo fora
    }

    // ---- Frente 3: thread nao mostra reacao ------------------------------------

    public function test_thread_nao_retorna_reacoes(): void
    {
        $a = Account::create(['name' => 'A']);
        $c = $this->evoChannel($a);
        $jid = '554188887777@s.whatsapp.net';
        Contact::create(['account_id' => $a->id, 'remote_jid' => $jid, 'auto_reply_mode' => 'default']);
        $base = ['account_id' => $a->id, 'channel_id' => $c->id, 'instance' => 'fabio-pessoal',
            'remote_jid' => $jid, 'from_me' => false, 'received_at' => Carbon::create(2026, 6, 29, 13, 0, 0, 'UTC')];

        IncomingMessage::create($base + ['evolution_message_id' => 'T1', 'type' => 'conversation', 'text' => 'mensagem de verdade', 'raw_payload' => ['x' => 1]]);
        IncomingMessage::create($base + ['evolution_message_id' => 'T2', 'type' => 'reactionMessage', 'text' => null,
            'raw_payload' => ['data' => ['message' => ['reactionMessage' => ['text' => '🙏']]]]]);

        Livewire::test(Conversas::class)
            ->set('selectedJid', $jid)
            ->assertSee('mensagem de verdade')
            ->assertDontSee('reagiu');
    }
}
