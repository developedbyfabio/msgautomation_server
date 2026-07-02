<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsappMessage;
use App\Models\IncomingMessage;
use App\Channels\Evolution\EvolutionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S6 — normalizador catch-all: NENHUM tipo e descartado. Texto/legenda extraidos
 * quando existem; tipos sem texto persistem com text null; tipo desconhecido nao
 * quebra. Idempotencia mantida.
 */
class NormalizerCatchAllTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): EvolutionProvider
    {
        // CH-1 (setup): o adaptador vive no EvolutionProvider — mesmo contrato.
        return app(EvolutionProvider::class);
    }

    private function payload(string $messageType, array $message, array $over = []): array
    {
        return array_replace_recursive([
            'event' => 'messages.upsert',
            'instance' => 'fabio-pessoal',
            'data' => [
                'key' => ['id' => 'EVO' . uniqid(), 'fromMe' => false, 'remoteJid' => '5541999990000@s.whatsapp.net'],
                'pushName' => 'Fulano',
                'messageType' => $messageType,
                'message' => $message,
                'messageTimestamp' => 1782699162,
            ],
        ], $over);
    }

    public function test_todos_os_tipos_normalizam_sem_descarte(): void
    {
        $casos = [
            ['conversation', ['conversation' => 'oi'], 'oi'],
            ['extendedTextMessage', ['extendedTextMessage' => ['text' => 'ola']], 'ola'],
            ['imageMessage', ['imageMessage' => ['caption' => 'foto legal']], 'foto legal'],
            ['imageMessage', ['imageMessage' => []], null],            // imagem sem legenda
            ['videoMessage', ['videoMessage' => ['caption' => 'clipe']], 'clipe'],
            ['audioMessage', ['audioMessage' => ['seconds' => 5]], null],
            ['stickerMessage', ['stickerMessage' => []], null],
            ['documentMessage', ['documentMessage' => ['fileName' => 'nota.pdf']], null],
            ['reactionMessage', ['reactionMessage' => ['text' => '😂']], null],
            ['locationMessage', ['locationMessage' => ['degreesLatitude' => -25.4]], null],
            ['contactMessage', ['contactMessage' => ['displayName' => 'Joao']], null],
            ['pollCreationMessage', ['pollCreationMessage' => ['name' => 'Almoco?']], null],
            ['fooBarMessage', ['fooBarMessage' => ['x' => 1]], null],   // tipo INVENTADO
        ];

        foreach ($casos as [$type, $message, $textoEsperado]) {
            $dto = $this->driver()->normalizeIncoming($this->payload($type, $message));
            $this->assertNotNull($dto, "tipo {$type} foi descartado");
            $this->assertSame($type, $dto->type, "tipo {$type} mudou");
            $this->assertSame($textoEsperado, $dto->text, "texto do tipo {$type}");
        }
    }

    public function test_tipo_inferido_ignora_messageContextInfo(): void
    {
        $dto = $this->driver()->normalizeIncoming($this->payload('', [
            'messageContextInfo' => ['deviceListMetadata' => []],
            'imageMessage' => ['caption' => 'x'],
        ], ['data' => ['messageType' => null]]));

        $this->assertNotNull($dto);
        $this->assertSame('imageMessage', $dto->type);
    }

    public function test_job_persiste_tipo_desconhecido_e_e_idempotente(): void
    {
        // MT-0: a conta e resolvida pelo CANAL da instancia (como em producao, via
        // seeder). Sem canal, o payload seria descartado por seguranca.
        $account = \App\Models\Account::create(['name' => 'T']);
        \App\Models\Channel::create(['account_id' => $account->id, 'instance' => 'fabio-pessoal', 'status' => 'connected']);

        $payload = $this->payload('fooBarMessage', ['fooBarMessage' => ['x' => 1]], ['data' => ['key' => ['id' => 'FIXO-CATCHALL']]]);

        (new ProcessIncomingWhatsappMessage($payload))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );
        // Re-entrega do mesmo evento -> ignora (idempotencia), nao duplica.
        (new ProcessIncomingWhatsappMessage($payload))->handle(
            app(\App\Contracts\WhatsappGateway::class),
            app(\App\Whatsapp\AutoReply\RuleMatcher::class),
            app(\App\Whatsapp\AutoReply\AntiBanGuard::class),
        );

        $this->assertSame(1, IncomingMessage::where('evolution_message_id', 'FIXO-CATCHALL')->count());
        $this->assertSame('fooBarMessage', IncomingMessage::where('evolution_message_id', 'FIXO-CATCHALL')->value('type'));
    }
}
