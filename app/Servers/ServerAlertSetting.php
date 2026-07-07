<?php

namespace App\Servers;

use App\Tenancy\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * Servidores — preferencias de alerta por conta (hoje: o separador dos avisos
 * agrupados numa mesma mensagem de WhatsApp).
 */
class ServerAlertSetting extends Model
{
    use BelongsToAccount;

    protected $table = 'server_alert_settings';

    /** Default sensato: uma quebra de linha (cada aviso na sua linha). */
    public const DEFAULT_SEPARATOR = "\n";

    protected $fillable = ['account_id', 'group_separator'];

    /** Separador configurado da conta (ou o default). Nunca vazio. */
    public static function separatorFor(int $accountId): string
    {
        $sep = static::withoutAccountScope()->where('account_id', $accountId)->value('group_separator');

        return ($sep === null || $sep === '') ? self::DEFAULT_SEPARATOR : $sep;
    }
}
