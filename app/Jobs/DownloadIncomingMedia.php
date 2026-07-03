<?php

namespace App\Jobs;

use App\Channels\ProviderRegistry;
use App\Models\Channel;
use App\Models\IncomingMessage;
use App\Models\SystemEvent;
use App\Tenancy\AccountContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Prompt 13 — midia RECEBIDA, Fatia 2: baixa+armazena o binario (imagem cheia /
 * audio) num JOB SEPARADO do inbound. Assim o pipeline reativo (receber, casar
 * regra, responder) NUNCA depende disto: se o download/descriptografia falhar, o
 * job LOGA (SystemEvent + Log, com visibilidade) e segue — nada derruba a mensagem
 * nem a resposta.
 *
 * Isolamento: contexto de conta EXPLICITO (ids escalares, sem serializar model);
 * path escopado por conta (media/incoming/{conta}/{numero}/{uuid}.ext) no disco
 * privado. Idempotente: se ja tem media_path, nao rebaixa.
 */
class DownloadIncomingMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 20;

    public function __construct(
        public readonly int $messageId,
        public readonly int $accountId,
        public readonly int $channelId,
    ) {
    }

    public function handle(ProviderRegistry $registry): void
    {
        // Contexto explicito pro resto do job (queries escopadas por conta).
        app(AccountContext::class)->set($this->accountId);

        $message = IncomingMessage::query()
            ->where('account_id', $this->accountId)
            ->find($this->messageId);
        if ($message === null || $message->media_path !== null) {
            return; // sumiu, ou ja baixado (idempotente)
        }

        $categoria = $message->mediaCategory();
        if ($categoria === null) {
            return; // tipo sem midia baixavel nesta fatia (imagem/audio)
        }

        $channel = Channel::withoutAccountScope()
            ->where('account_id', $this->accountId)
            ->find($this->channelId);
        if ($channel === null) {
            return;
        }

        try {
            $media = $registry->for($channel)->fetchIncomingMedia($channel, $message);

            if ($media === null) {
                // Sem midia a baixar (ex.: Cloud sem media_id, canal sem credencial).
                $message->forceFill(['media_status' => 'unsupported'])->save();

                return;
            }

            $numero = Str::before($message->remote_jid, '@') ?: 'sem-numero';
            $ext = $this->extensao($media->mime, $categoria);
            $path = 'media/incoming/' . $this->accountId . '/' . $numero . '/' . Str::uuid() . '.' . $ext;

            Storage::disk('local')->put($path, $media->binary);

            $message->forceFill([
                'media_path' => $path,
                'media_mime' => $media->mime,
                'media_name' => $media->filename,
                'media_status' => 'stored',
            ])->save();
        } catch (\Throwable $e) {
            // Best-effort: nunca propaga. Marca falha + registra pra visibilidade (/logs).
            $message->forceFill(['media_status' => 'failed'])->save();

            Log::warning('Midia recebida: download falhou.', [
                'message' => $this->messageId,
                'channel' => $this->channelId,
                'motivo' => $e->getMessage(),
            ]);

            try {
                SystemEvent::withoutAccountScope()->create([
                    'account_id' => $this->accountId,
                    'channel_id' => $this->channelId,
                    'type' => 'midia_download_falhou',
                    'level' => 'warning',
                    'title' => 'Nao foi possivel baixar a midia recebida (' . $categoria . ').',
                    'detail' => ['message_id' => $this->messageId, 'motivo' => mb_substr($e->getMessage(), 0, 180)],
                    'occurred_at' => now(),
                ]);
            } catch (\Throwable) {
                // log de erro nunca pode derrubar o job
            }
        }
    }

    /** Extensao cosmetica (o content-type servido vem de media_mime). */
    private function extensao(string $mime, string $categoria): string
    {
        return match (trim(explode(';', $mime)[0])) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'audio/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',
            'audio/amr' => 'amr',
            default => $categoria === 'audio' ? 'bin' : 'jpg',
        };
    }
}
