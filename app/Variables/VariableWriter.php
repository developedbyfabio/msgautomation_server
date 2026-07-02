<?php

namespace App\Variables;

use App\Models\Variable;
use App\Whatsapp\Secrets\SecretVault;

/**
 * V-1 — caminho OFICIAL (unico) de gravacao de variavel, usado pelo /variaveis.
 *
 * GUARDAS (a anti-bypass do S5 e a mais importante):
 *  - valor NUNCA contem {senha:}/referencia de segredo — dado sensivel e assunto
 *    do COFRE (senao a variavel viraria um tunel pra vazar senha sem escopo);
 *  - valor NUNCA contem outro placeholder {x} — um nivel, SEM recursao;
 *  - nomes reservados (nome/saudacao/data/hora/senha, com fold de acento/caixa)
 *    bloqueados pra custom; slug [a-z0-9_]{1,40}; unico por conta;
 *  - variavel de SISTEMA: nao renomeia, nao exclui, nao desativa — so edita
 *    textos/faixas (e o tipo fica travado);
 *  - horario: faixas H:i validas + valor_padrao OBRIGATORIO; sobreposicao de
 *    faixas gera AVISO (nao bloqueio — a primeira que cobre vence);
 *  - dia_semana: dias validos + valor_padrao OBRIGATORIO.
 *
 * O cache de resolucao e invalidado pelo observer do model em qualquer escrita.
 */
class VariableWriter
{
    private const DIAS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];

    public function __construct(private SecretVault $vault)
    {
    }

    /**
     * @return array{variable: ?Variable, errors: array<string,string>, warnings: array<int,string>}
     */
    public function save(int $accountId, array $dados, ?int $editingId = null): array
    {
        $warnings = [];
        $editando = $editingId !== null
            ? Variable::withoutAccountScope()->where('account_id', $accountId)->find($editingId)
            : null;
        if ($editingId !== null && ! $editando) {
            return $this->erro('name', 'Variavel nao encontrada.');
        }

        // ---- nome ------------------------------------------------------------
        $name = mb_strtolower(trim((string) ($dados['name'] ?? '')), 'UTF-8');
        if ($editando?->is_system) {
            // Sistema: nome e tipo TRAVADOS (so config/textos mudam).
            $name = (string) $editando->name;
            $type = (string) $editando->type;
        } else {
            if (! preg_match('/^[a-z0-9_]{1,40}$/', $name)) {
                return $this->erro('name', 'Nome invalido: use so letras minusculas, numeros e _ (ate 40).');
            }
            if (Variable::isReserved($name)) {
                return $this->erro('name', 'Nome reservado (nome, saudacao, data, hora, senha) — escolha outro.');
            }
            $duplicada = Variable::withoutAccountScope()
                ->where('account_id', $accountId)->where('name', $name)
                ->when($editingId, fn ($q) => $q->where('id', '!=', $editingId))
                ->exists();
            if ($duplicada) {
                return $this->erro('name', 'Ja existe variavel com esse nome.');
            }
            $type = (string) ($dados['type'] ?? '');
            if (! in_array($type, Variable::TYPES, true)) {
                return $this->erro('type', 'Tipo invalido.');
            }
        }

        // ---- config por tipo (+ coleta de todos os VALORES pras guardas) ------
        $config = [];
        $valores = [];

        if ($type === 'static') {
            $valor = trim((string) ($dados['config']['valor'] ?? ''));
            if ($valor === '') {
                return $this->erro('config', 'Informe o texto da variavel.');
            }
            $config = ['valor' => $valor];
            $valores[] = $valor;
        }

        if ($type === 'horario') {
            $faixas = [];
            foreach ((array) ($dados['config']['faixas'] ?? []) as $f) {
                $inicio = trim((string) ($f['inicio'] ?? ''));
                $fim = trim((string) ($f['fim'] ?? ''));
                $valor = trim((string) ($f['valor'] ?? ''));
                if ($inicio === '' && $fim === '' && $valor === '') {
                    continue; // linha vazia do form
                }
                if (! preg_match('/^\d{2}:\d{2}$/', $inicio) || ! preg_match('/^\d{2}:\d{2}$/', $fim) || $valor === '') {
                    return $this->erro('config', 'Faixa invalida: use horarios HH:MM e um texto em cada faixa.');
                }
                $faixas[] = ['inicio' => $inicio, 'fim' => $fim, 'valor' => $valor];
                $valores[] = $valor;
            }
            if ($faixas === []) {
                return $this->erro('config', 'Cadastre ao menos uma faixa de horario.');
            }
            $padrao = trim((string) ($dados['config']['valor_padrao'] ?? ''));
            if ($padrao === '') {
                return $this->erro('config', 'O valor padrao (fora das faixas) e OBRIGATORIO.');
            }
            $valores[] = $padrao;
            $config = ['faixas' => $faixas, 'valor_padrao' => $padrao];

            if ($aviso = $this->avisoSobreposicao($faixas)) {
                $warnings[] = $aviso;
            }
        }

        if ($type === 'dia_semana') {
            foreach (self::DIAS as $dia) {
                $valor = trim((string) ($dados['config'][$dia] ?? ''));
                if ($valor !== '') {
                    $config[$dia] = $valor;
                    $valores[] = $valor;
                }
            }
            $padrao = trim((string) ($dados['config']['valor_padrao'] ?? ''));
            if ($padrao === '') {
                return $this->erro('config', 'O valor padrao (dias nao preenchidos) e OBRIGATORIO.');
            }
            $valores[] = $padrao;
            $config['valor_padrao'] = $padrao;
        }

        // ---- GUARDAS duras sobre TODOS os valores ------------------------------
        foreach ($valores as $valor) {
            // Anti-bypass do S5: segredo JAMAIS em variavel (dado sensivel = cofre,
            // que exige escopo explicito de contatos; variavel vale pra todo lugar).
            if ($this->vault->hasRef($valor) || preg_match('/\{senha\b/iu', $valor)) {
                return $this->erro('config', 'Valor de variavel NAO pode conter {senha:...}: dado sensivel e assunto do cofre (/senhas), que tem escopo por contato. Variavel e pra conteudo nao-sensivel.');
            }
            // Sem variavel dentro de variavel (um nivel, sem recursao).
            if (preg_match('/\{\w+\}/u', $valor)) {
                return $this->erro('config', 'Valor de variavel nao pode conter outro placeholder ({...}) — um nivel so, sem recursao.');
            }
        }

        // ---- persistencia -------------------------------------------------------
        $persist = [
            'name' => $name,
            'type' => $type,
            'config' => $config,
            'active' => $editando?->is_system ? true : (bool) ($dados['active'] ?? true),
        ];

        if ($editando) {
            $editando->update($persist);
            $variable = $editando;
        } else {
            $variable = Variable::create(array_merge($persist, [
                'account_id' => $accountId,
                'is_system' => false,
            ]));
        }

        return ['variable' => $variable, 'errors' => [], 'warnings' => $warnings];
    }

    /** Exclusao: variavel de sistema NUNCA sai. */
    public function delete(int $accountId, int $id): bool
    {
        $v = Variable::withoutAccountScope()->where('account_id', $accountId)->find($id);
        if (! $v || $v->is_system) {
            return false;
        }

        $v->delete();

        return true;
    }

    /** Aviso (nao bloqueio) quando duas faixas cobrem o mesmo minuto. */
    private function avisoSobreposicao(array $faixas): ?string
    {
        $cobre = function (array $f, string $hora): bool {
            return $f['inicio'] <= $f['fim']
                ? ($hora >= $f['inicio'] && $hora <= $f['fim'])
                : ($hora >= $f['inicio'] || $hora <= $f['fim']);
        };

        foreach ($faixas as $i => $a) {
            foreach (array_slice($faixas, $i + 1, null, true) as $j => $b) {
                if ($cobre($b, $a['inicio']) || $cobre($b, $a['fim']) || $cobre($a, $b['inicio'])) {
                    return "Faixas " . ($i + 1) . ' e ' . ($j + 1) . ' se sobrepoem — a primeira que cobre o horario vence.';
                }
            }
        }

        return null;
    }

    /** @return array{variable: null, errors: array<string,string>, warnings: array<int,string>} */
    private function erro(string $campo, string $msg): array
    {
        return ['variable' => null, 'errors' => [$campo => $msg], 'warnings' => []];
    }
}
