<?php

namespace App\Models;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * CH-2 — janela de 24h da Meta POR CONTATO+CANAL: last_inbound_at atualizado a
 * cada mensagem RECEBIDA naquele canal. Mensagem na Evolution NAO abre janela
 * no canal oficial (e vice-versa). O Sender consulta isOpen() quando o provider
 * do canal declara mensagemLivreForaDaJanela = false (so cloud_api hoje).
 * Reativo responde em segundos — janela aberta por construcao.
 */
class ContactChannelWindow extends Model
{
    use BelongsToAccount;

    public const JANELA_HORAS = 24;

    protected $fillable = ['account_id', 'contact_id', 'channel_id', 'last_inbound_at'];

    protected function casts(): array
    {
        return ['last_inbound_at' => 'datetime'];
    }

    /** Upsert no caminho do webhook (toda mensagem recebida re-abre a janela). */
    public static function touchWindow(int $accountId, int $contactId, int $channelId, ?Carbon $at = null): void
    {
        static::withoutAccountScope()->updateOrCreate(
            ['contact_id' => $contactId, 'channel_id' => $channelId],
            ['account_id' => $accountId, 'last_inbound_at' => $at ?: now()],
        );
    }

    /** Janela aberta = ultimo inbound neste CANAL ha menos de 24h. */
    public static function isOpen(int $accountId, string $remoteJid, int $channelId): bool
    {
        return static::restante($accountId, $remoteJid, $channelId) !== null;
    }

    /** Tempo RESTANTE da janela (countdown), ou null se fechada/sem inbound. */
    public static function restante(int $accountId, string $remoteJid, int $channelId): ?\DateInterval
    {
        $contactId = Contact::withoutAccountScope()
            ->where('account_id', $accountId)->where('remote_jid', $remoteJid)->value('id');
        if ($contactId === null) {
            return null;
        }

        $last = static::withoutAccountScope()
            ->where('contact_id', $contactId)->where('channel_id', $channelId)
            ->value('last_inbound_at');
        if ($last === null) {
            return null;
        }

        $fecha = Carbon::parse($last)->addHours(self::JANELA_HORAS);

        return $fecha->isFuture() ? now()->diff($fecha) : null;
    }
}
