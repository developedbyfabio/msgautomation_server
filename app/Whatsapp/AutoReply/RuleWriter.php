<?php

namespace App\Whatsapp\AutoReply;

use App\Models\AutoReplyRule;
use App\Models\Contact;
use App\Whatsapp\Secrets\SecretVault;

/**
 * Fatia 4 — caminho OFICIAL (unico) de gravacao de regra, extraido do CRUD de
 * /regras e reusado pela promocao "virar regra" do /revisao. Nao existe caminho
 * paralelo: toda regra passa pelas MESMAS guardas.
 *
 * Guardas aplicadas aqui (alem da validacao de formato, que fica no componente):
 *  - regex de gatilho tem que compilar (protecao anti-catastrofe do RuleMatcher);
 *  - ao menos uma resposta nao-vazia;
 *  - escopo 'contatos' exige ao menos um contato DA CONTA (ids de fora sao descartados);
 *  - S5: resposta com {senha:...} exige escopo 'contatos' (senao a senha iria em texto
 *    pra QUALQUER contato) e gatilhos ESTRITOS (sem tolerancia a erro de digitacao).
 */
class RuleWriter
{
    public function __construct(private SecretVault $vault)
    {
    }

    /**
     * Cria/atualiza a regra com filhas. Retorna ['rule' => AutoReplyRule|null,
     * 'errors' => [campo => mensagem]] — com erros, NADA e persistido.
     *
     * @param array{
     *   triggers: array<int,array{type:string,value:string,precision?:string,fuzzy_level?:?string}>,
     *   responses: array<int,string>,
     *   enabled: bool,
     *   cooldown_mode: string,
     *   cooldown_minutes: ?int,
     *   scope: string,
     *   contact_ids: array<int,int>,
     *   ai_match_enabled: bool,
     *   ai_examples: array<int,string>,
     * } $dados
     * @return array{rule: ?AutoReplyRule, errors: array<string,string>}
     */
    public function save(int $accountId, array $dados, ?int $editingId = null): array
    {
        $erros = [];

        // Protecao: valida cada gatilho regex (padrao compila).
        foreach ($dados['triggers'] as $i => $t) {
            if (($t['type'] ?? '') === 'regex' && ! RuleMatcher::isValidRegex((string) ($t['value'] ?? ''))) {
                return ['rule' => null, 'errors' => ["triggers.{$i}.value" => 'Regex invalido. Confira o padrao.']];
            }
        }

        $triggers = array_values(array_map(function ($t) {
            $precision = ($t['precision'] ?? 'exato') === 'tolerante' ? 'tolerante' : 'exato';
            // Regex nao usa fuzzy (e ja um padrao); so exato/tolerante valem p/ texto.
            if ($t['type'] === 'regex') {
                $precision = 'exato';
            }

            return [
                'match_type' => $t['type'],
                'match_value' => trim((string) $t['value']),
                'precision' => $precision,
                'fuzzy_level' => $precision === 'tolerante' ? ($t['fuzzy_level'] ?? 'media') : null,
            ];
        }, $dados['triggers']));

        $responses = array_values(array_filter(array_map(
            fn ($r) => trim((string) $r),
            $dados['responses'],
        ), fn ($r) => $r !== ''));

        if ($responses === []) {
            return ['rule' => null, 'errors' => ['responses' => 'Cadastre ao menos uma resposta.']];
        }

        $scope = in_array($dados['scope'] ?? 'global', ['contatos', 'tags'], true) ? $dados['scope'] : 'global';
        // Contatos do escopo, validados como do mesmo account.
        $contactIds = [];
        if ($scope === 'contatos') {
            $contactIds = Contact::query()->where('account_id', $accountId)
                ->whereIn('id', $dados['contact_ids'] ?? [])->pluck('id')->all();
            if ($contactIds === []) {
                return ['rule' => null, 'errors' => ['scopeContactIds' => 'Escopo "contatos": selecione ao menos um contato.']];
            }
        }

        // T-1 — tags do escopo, validadas como da mesma conta.
        $tagIds = [];
        if ($scope === 'tags') {
            $tagIds = \App\Models\Tag::withoutAccountScope()->where('account_id', $accountId)
                ->whereIn('id', $dados['tag_ids'] ?? [])->pluck('id')->all();
            if ($tagIds === []) {
                return ['rule' => null, 'errors' => ['scopeTagIds' => 'Escopo "tag": selecione ao menos uma tag.']];
            }
        }

        // S5 — guarda de escopo para regras que devolvem SENHA ({senha:...}).
        $temSenha = collect($responses)->contains(fn ($r) => $this->vault->hasRef((string) $r));
        if ($temSenha) {
            // T-1: TAG e dinamica (um evento/regra de board pode aplica-la a qualquer
            // contato) — segredo exige lista EXPLICITA de contatos. Nunca por tag.
            if ($scope === 'tags') {
                return ['rule' => null, 'errors' => ['scope' => 'Esta regra envia uma senha ({senha:...}) e NAO pode usar escopo por tag: tag e dinamica (eventos podem aplica-la a qualquer contato). Use "Contatos Especificos" e selecione quem pode receber.']];
            }
            if ($scope !== 'contatos') {
                return ['rule' => null, 'errors' => ['scope' => 'Esta regra envia uma senha ({senha:...}). A senha iria em texto pra QUALQUER contato que disparasse. Use escopo "Contatos Especificos" e selecione quem pode receber.']];
            }
            $temFuzzy = collect($triggers)->contains(fn ($t) => ($t['precision'] ?? 'exato') === 'tolerante');
            if ($temFuzzy) {
                return ['rule' => null, 'errors' => ['triggers' => 'Regra que envia senha deve usar match ESTRITO (exato). Tire a tolerancia a erros dos gatilhos pra nao disparar por engano e vazar a senha.']];
            }
        }

        // Frases-exemplo da IA (opcionais; so as nao-vazias). Exemplos de MENSAGEM.
        $aiExamples = array_values(array_filter(array_map(
            fn ($p) => trim((string) $p),
            $dados['ai_examples'] ?? [],
        ), fn ($p) => $p !== ''));

        // Colunas legadas = cache do 1o gatilho / 1a resposta (back-compat).
        $cooldownMode = (string) ($dados['cooldown_mode'] ?? 'global');
        $persist = [
            'match_type' => $triggers[0]['match_type'],
            'match_value' => $triggers[0]['match_value'],
            'response_text' => $responses[0],
            'enabled' => (bool) ($dados['enabled'] ?? true),
            'cooldown_mode' => $cooldownMode,
            'cooldown_minutes' => $cooldownMode === 'cada_n' ? (int) ($dados['cooldown_minutes'] ?? 60) : null,
            'scope' => $scope,
            'ai_match_enabled' => (bool) ($dados['ai_match_enabled'] ?? false),
        ];

        if ($editingId !== null) {
            $rule = AutoReplyRule::query()->where('account_id', $accountId)->findOrFail($editingId);
            $rule->update($persist);
        } else {
            $next = (int) (AutoReplyRule::query()->where('account_id', $accountId)->max('priority') ?? -1) + 1;
            $rule = AutoReplyRule::create(array_merge($persist, [
                'account_id' => $accountId,
                'priority' => $next,
            ]));
        }

        // Re-sincroniza as filhas (substitui, sem tocar em outras regras).
        $rule->triggers()->delete();
        $rule->triggers()->createMany($triggers);
        $rule->responses()->delete();
        $rule->responses()->createMany(array_map(fn ($r) => ['response_text' => $r], $responses));
        $rule->contacts()->sync($contactIds);
        $rule->tags()->sync($tagIds); // T-1: escopo por tag ([] fora do escopo 'tags')

        // Frases-exemplo da IA: re-sincroniza (substitui).
        $rule->aiExamples()->delete();
        if ($aiExamples !== []) {
            $rule->aiExamples()->createMany(array_map(fn ($p) => ['phrase' => $p], $aiExamples));
        }

        // V-1 — AVISO (nao bloqueio): referencia {algo} que nao e nativa nem
        // variavel ativa da conta sairia CRUA pro contato (a licao do bug da
        // saudacao). O chamador exibe o aviso.
        $desconhecidas = [];
        foreach ($responses as $resp) {
            $desconhecidas = array_merge($desconhecidas, \App\Models\Variable::unknownRefs($accountId, $resp));
        }
        $warnings = $desconhecidas !== []
            ? ['Referencia(s) desconhecida(s) na resposta: {' . implode('}, {', array_unique($desconhecidas)) . '} — sem variavel ativa com esse nome, sai cru pro contato.']
            : [];

        return ['rule' => $rule, 'errors' => [], 'warnings' => $warnings];
    }
}
