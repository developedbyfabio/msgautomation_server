<?php

namespace App\Console\Commands;

use App\Ai\AiClassificationRequest;
use App\Contracts\AiClassifier;
use Illuminate\Console\Command;

/**
 * Sanidade da IA (Camada 3): faz UMA chamada real ao Gemini com uma frase inocua e
 * reporta ok/erro. Valida chave/modelo/endpoint SEM ecoar a chave. NAO envia WhatsApp,
 * NAO mexe em contatos/regras — so exercita o classificador.
 */
class AiSanity extends Command
{
    protected $signature = 'ai:sanity';

    protected $description = 'Testa a conexao com o Gemini (uma chamada real, frase inocua) — reporta ok/erro';

    public function handle(AiClassifier $ai): int
    {
        $model = (string) config('services.gemini.model');

        if ((string) config('services.gemini.api_key') === '') {
            $this->error('GEMINI_API_KEY vazia no .env. Adicione a chave (chmod 600) e rode de novo.');

            return self::FAILURE;
        }

        $this->line("Modelo: {$model}. Chamando o Gemini com uma frase de teste...");

        $r = $ai->classify(new AiClassificationRequest(
            'teste de conexao',
            [['rule_id' => 1, 'triggers' => ['teste'], 'examples' => []]],
            [],
        ));

        if ($r->unknown) {
            // Nunca ecoa a chave — so o motivo do driver.
            $this->error("erro: {$r->reason} (modelo {$model}).");

            return self::FAILURE;
        }

        $this->info("ok: o Gemini respondeu e o JSON foi valido (modelo {$model}, confidence {$r->confidence}).");

        return self::SUCCESS;
    }
}
