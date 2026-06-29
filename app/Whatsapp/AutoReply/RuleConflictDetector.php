<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyRule;

/**
 * Fatia 0 — detector de SOBREPOSICAO: avisa quando dois gatilhos de regras ativas
 * casariam a MESMA mensagem (o Fabio resolve de proposito). Reusa o matcher: para
 * cada VALOR de gatilho de cada regra, ve se OUTRA regra tambem casa esse valor.
 *
 * Cobertura: exact/starts_with/contains (e regex como "matcher", nao como amostra).
 * regex/tolerante como AMOSTRA e best-effort (nao geramos texto de exemplo deles).
 * Ignora escopo de proposito (o aviso vale mesmo que os escopos so colidam p/ alguns
 * contatos). Preparado pra estender a gatilhos de fluxo na Fatia A (mesmo motor).
 *
 * @return mapa ruleId => array<int,array{id:int,label:string,sample:string}>
 */
class RuleConflictDetector
{
    public function __construct(private RuleMatcher $matcher)
    {
    }

    public function conflicts(int $accountId): array
    {
        $rules = AutoReplyRule::query()
            ->with(['triggers', 'responses'])
            ->where('account_id', $accountId)
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rules as $a) {
            foreach ($a->triggerList() as $t) {
                if ($t['type'] === 'regex') {
                    continue; // best-effort: nao geramos amostra de regex
                }
                $sample = trim((string) $t['value']);
                if ($sample === '') {
                    continue;
                }
                foreach ($rules as $b) {
                    if ((int) $b->id === (int) $a->id) {
                        continue;
                    }
                    // Mensagem = sample de A casa B tambem -> conflito MUTUO (marca os dois lados).
                    if ($this->matcher->firstMatchingTrigger($b, $sample) !== null) {
                        $out[$a->id][$b->id] = ['id' => (int) $b->id, 'label' => $this->label($b), 'sample' => $sample];
                        $out[$b->id][$a->id] = ['id' => (int) $a->id, 'label' => $this->label($a), 'sample' => $sample];
                    }
                }
            }
        }

        // reindexa (remove chaves por id)
        return array_map(fn ($m) => array_values($m), $out);
    }

    private function label(AutoReplyRule $rule): string
    {
        $first = $rule->triggerList()->first();

        return $first ? ($first['value'] ?: ('#' . $rule->id)) : ('#' . $rule->id);
    }
}
