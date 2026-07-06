<?php

namespace App\Console\Commands;

use App\Models\FlowTrigger;
use App\Models\RuleTrigger;
use App\Whatsapp\TextNormalizer;
use Illuminate\Console\Command;

/**
 * Fatia 19 (MATCH-2) — re-normaliza TODOS os gatilhos persistidos com a
 * pipeline NOVA (normalized_text com squeeze de runs 3+; normalized_phonetic
 * novo). O RISCO CENTRAL da fatia: pipeline nova com normalized_text velho =
 * regra que casava PARA de casar em silencio (gatilho velho != mensagem nova).
 *
 * IDEMPOTENTE: recomputa a forma esperada e so faz UPDATE onde difere —
 * rodar 2x nao muda nada na 2a. UPDATE de campo DERIVADO apenas (match_value e
 * o resto intocados). Cobre rule_triggers E flow_triggers, todas as contas.
 */
class RenormalizeTriggers extends Command
{
    protected $signature = 'msg:renormalize-triggers';

    protected $description = 'Re-normaliza normalized_text/normalized_phonetic de todos os gatilhos (MATCH-2). Idempotente.';

    public function handle(): int
    {
        foreach ([['rule_triggers', RuleTrigger::class], ['flow_triggers', FlowTrigger::class]] as [$tabela, $model]) {
            $atualizados = 0;
            $corretos = 0;

            $model::query()->orderBy('id')->chunkById(200, function ($triggers) use (&$atualizados, &$corretos) {
                foreach ($triggers as $t) {
                    $regex = $t->match_type === 'regex';
                    $norm = $regex ? null : TextNormalizer::normalize((string) $t->match_value);
                    $phon = $regex ? null : TextNormalizer::phonetic((string) $t->match_value);

                    if ($t->normalized_text === $norm && $t->normalized_phonetic === $phon) {
                        $corretos++;

                        continue; // ja na pipeline nova: idempotencia
                    }

                    // UPDATE direto dos campos derivados (o saving do model
                    // recomputaria igual; query() evita disparar eventos a toa).
                    $t->newQuery()->whereKey($t->id)->update([
                        'normalized_text' => $norm,
                        'normalized_phonetic' => $phon,
                    ]);
                    $atualizados++;
                }
            });

            $this->line(sprintf('  %-14s %d re-normalizado(s), %d ja correto(s)', $tabela . ':', $atualizados, $corretos));
        }

        $this->info('Pronto. Re-rodar e seguro (idempotente).');

        return self::SUCCESS;
    }
}
