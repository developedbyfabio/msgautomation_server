<?php

namespace App\Console\Commands;

use App\Whatsapp\EvolutionApi;
use Illuminate\Console\Command;

/**
 * Prepara a instancia da Evolution: cria (se faltar) e configura o webhook
 * por instancia apontando pro app no host. NAO conecta numero (isso e o QR, gate).
 */
class EvolutionSetup extends Command
{
    protected $signature = 'evolution:setup';

    protected $description = 'Cria a instancia (se faltar) e configura o webhook de mensagens recebidas';

    public function handle(EvolutionApi $api): int
    {
        $this->info("Instancia: {$api->instance()}");

        if ($api->instanceExists()) {
            $this->line('Instancia ja existe — ok.');
        } else {
            $this->line('Criando instancia...');
            $resp = $api->createInstance();
            if (! $resp->successful()) {
                $this->error("Falha ao criar instancia (HTTP {$resp->status()}): " . $resp->body());

                return self::FAILURE;
            }
            $this->info('Instancia criada.');
        }

        $url = (string) config('services.evolution.webhook_url');
        $header = (string) config('services.webhook.header');
        $secret = (string) config('services.webhook.secret');

        if ($secret === '') {
            $this->error('WEBHOOK_SECRET vazio no .env — configure antes.');

            return self::FAILURE;
        }

        $this->line("Configurando webhook -> {$url} (evento MESSAGES_UPSERT)");
        $resp = $api->setWebhook($url, ['MESSAGES_UPSERT'], [$header => $secret]);

        if (! $resp->successful()) {
            $this->error("Falha ao configurar webhook (HTTP {$resp->status()}): " . $resp->body());

            return self::FAILURE;
        }

        $this->info('Webhook configurado com sucesso (header secreto nao exibido).');

        return self::SUCCESS;
    }
}
