<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Channel;
use App\Whatsapp\AutoReply\Sender;
use Illuminate\Console\Command;

/**
 * Envio MANUAL de uma mensagem (prova da Fatia 2 e, no futuro, intervencao humana).
 *
 * Caminho manual (R1): respeita os tetos protetivos (intervalo/min/dia), mas NAO
 * passa pelo kill switch, janela ou opt-out. Registra em auto_reply_logs.
 */
class WhatsappSend extends Command
{
    protected $signature = 'whatsapp:send {jid : numero ou jid de destino} {text : texto a enviar} {--account=}';

    protected $description = 'Envia UMA mensagem manual via o driver (respeita tetos protetivos, ignora kill switch)';

    public function handle(Sender $sender): int
    {
        $account = $this->option('account')
            ? Account::find((int) $this->option('account'))
            : Account::query()->oldest('id')->first();

        if (! $account) {
            $this->error('Nenhuma account encontrada. Rode o seeder.');

            return self::FAILURE;
        }

        $channel = $account->channels()->oldest('id')->first()
            ?? Channel::where('instance', config('services.evolution.instance'))->first();

        if (! $channel) {
            $this->error('Nenhum channel/instance encontrado para a account.');

            return self::FAILURE;
        }

        $log = $sender->send(
            mode: 'manual',
            channel: $channel,
            jid: $this->argument('jid'),
            text: $this->argument('text'),
        );

        return match ($log->status) {
            'sent' => tap(self::SUCCESS, fn () => $this->info("Enviado. log #{$log->id} provider_id=" . ($log->provider_message_id ?? '-'))),
            'blocked' => tap(self::SUCCESS, fn () => $this->warn("Bloqueado por freio: {$log->motivo} (log #{$log->id})")),
            default => tap(self::FAILURE, fn () => $this->error("Falha no envio: {$log->motivo} (log #{$log->id})")),
        };
    }
}
