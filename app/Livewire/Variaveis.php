<?php

namespace App\Livewire;

use App\Models\Variable;
use App\Tenancy\AccountContext;
use App\Variables\VariableWriter;
use App\Whatsapp\AutoReply\RuleResponder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * V-1 — /variaveis: placeholders configuraveis. Nativas explicadas ({nome}/{data}/
 * {hora} somente leitura; {senha:} com link pro cofre; {saudacao} = variavel de
 * SISTEMA editavel) + CRUD de variaveis custom (static | horario | dia_semana,
 * fallback obrigatorio), com PREVIEW do valor resolvido AGORA pelo MESMO
 * renderizador do envio. Guardas duras no VariableWriter (segredo/recursao/
 * reservados). Variavel e pra conteudo NAO-sensivel — senha/PIX e no cofre.
 */
#[Layout('components.layouts.app')]
class Variaveis extends Component
{
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $vName = '';
    public string $vType = 'static';
    public bool $vActive = true;
    public string $cValor = '';                 // static
    /** @var array<int,array{inicio:string,fim:string,valor:string}> */
    public array $cFaixas = [];                 // horario
    public string $cValorPadrao = '';           // horario + dia_semana
    /** @var array<string,string> */
    public array $cDias = [];                   // dia_semana

    public ?int $confirmingDeleteId = null;

    public const DIAS = ['seg' => 'Segunda', 'ter' => 'Terca', 'qua' => 'Quarta', 'qui' => 'Quinta', 'sex' => 'Sexta', 'sab' => 'Sabado', 'dom' => 'Domingo'];

    // ---- form -----------------------------------------------------------------

    public function novo(): void
    {
        $this->reset(['editingId', 'vName', 'cValor', 'cValorPadrao']);
        $this->vType = 'static';
        $this->vActive = true;
        $this->cFaixas = [['inicio' => '', 'fim' => '', 'valor' => '']];
        $this->cDias = array_fill_keys(array_keys(self::DIAS), '');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $v = $this->find($id);
        if (! $v) {
            return;
        }

        $this->editingId = $v->id;
        $this->vName = (string) $v->name;
        $this->vType = (string) $v->type;
        $this->vActive = (bool) $v->active;
        $cfg = (array) $v->config;
        $this->cValor = (string) ($cfg['valor'] ?? '');
        $this->cFaixas = array_values(array_map(fn ($f) => [
            'inicio' => (string) ($f['inicio'] ?? ''), 'fim' => (string) ($f['fim'] ?? ''), 'valor' => (string) ($f['valor'] ?? ''),
        ], (array) ($cfg['faixas'] ?? []))) ?: [['inicio' => '', 'fim' => '', 'valor' => '']];
        $this->cValorPadrao = (string) ($cfg['valor_padrao'] ?? '');
        $this->cDias = array_merge(array_fill_keys(array_keys(self::DIAS), ''), array_intersect_key($cfg, self::DIAS));
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function addFaixa(): void
    {
        $this->cFaixas[] = ['inicio' => '', 'fim' => '', 'valor' => ''];
    }

    public function removeFaixa(int $i): void
    {
        if (count($this->cFaixas) > 1) {
            unset($this->cFaixas[$i]);
            $this->cFaixas = array_values($this->cFaixas);
        }
    }

    public function save(VariableWriter $writer): void
    {
        $config = match ($this->vType) {
            'static' => ['valor' => $this->cValor],
            'horario' => ['faixas' => $this->cFaixas, 'valor_padrao' => $this->cValorPadrao],
            'dia_semana' => array_merge($this->cDias, ['valor_padrao' => $this->cValorPadrao]),
            default => [],
        };

        $res = $writer->save($this->accountId(), [
            'name' => $this->vName,
            'type' => $this->vType,
            'config' => $config,
            'active' => $this->vActive,
        ], $this->editingId);

        if ($res['errors'] !== []) {
            foreach ($res['errors'] as $campo => $msg) {
                $this->addError(match ($campo) {
                    'name' => 'vName', 'type' => 'vType', default => 'cValor',
                }, $msg);
            }

            return;
        }

        $this->closeForm();
        foreach ($res['warnings'] as $aviso) {
            $this->dispatch('toast', message: 'Aviso: ' . $aviso, type: 'error');
        }
        $this->dispatch('toast', message: 'Variavel salva. Ja vale no proximo envio.');
    }

    public function toggle(int $id): void
    {
        $v = $this->find($id);
        if (! $v || $v->is_system) {
            return; // sistema nunca desativa
        }
        $v->update(['active' => ! $v->active]);
        $this->dispatch('toast', message: $v->active ? 'Variavel ativada.' : 'Variavel desativada (a referencia passa a sair crua nos textos que a usam).');
    }

    // ---- excluir (mostra USO antes) -----------------------------------------------

    public function confirmDelete(int $id): void
    {
        $v = $this->find($id);
        if ($v && ! $v->is_system) {
            $this->confirmingDeleteId = $id;
        }
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function deleteConfirmed(VariableWriter $writer): void
    {
        if ($this->confirmingDeleteId) {
            $writer->delete($this->accountId(), $this->confirmingDeleteId);
            $this->dispatch('toast', message: 'Variavel excluida. Referencias nos textos ficaram intactas — sairao cruas ate voce ajustar.');
        }
        $this->confirmingDeleteId = null;
    }

    /** Onde a variavel e usada (contagem por area; busca literal de "{nome}"). */
    public function usoDe(string $name): array
    {
        $ref = '%{' . $name . '}%';
        $aid = $this->accountId();

        return [
            'regras' => (int) DB::table('rule_responses')
                ->join('auto_reply_rules', 'auto_reply_rules.id', '=', 'rule_responses.auto_reply_rule_id')
                ->where('auto_reply_rules.account_id', $aid)
                ->where('rule_responses.response_text', 'like', $ref)->count(),
            'fluxos' => (int) DB::table('flow_nodes')
                ->join('flows', 'flows.id', '=', 'flow_nodes.flow_id')
                ->where('flows.account_id', $aid)
                ->where('flow_nodes.message', 'like', $ref)->count(),
            'campanhas' => (int) DB::table('proactive_campaigns')
                ->where('account_id', $aid)->where('message', 'like', $ref)->count(),
            'base' => (int) DB::table('knowledge')
                ->where('account_id', $aid)->where('content', 'like', $ref)->count(),
        ];
    }

    // ---- consulta -------------------------------------------------------------------

    private function find(?int $id): ?Variable
    {
        return $id ? Variable::query()->find($id) : null;
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    public function render(RuleResponder $responder)
    {
        $variaveis = Variable::query()->orderByDesc('is_system')->orderBy('name')->get();

        // PREVIEW honesto: o valor que o MESMO renderizador do envio produziria agora.
        $preview = [];
        foreach ($variaveis as $v) {
            $preview[$v->id] = $v->active ? $responder->render('{' . $v->name . '}') : '(inativa — sai cru)';
        }

        return view('livewire.variaveis', [
            'variaveis' => $variaveis,
            'preview' => $preview,
            'nativasPreview' => [
                'nome' => 'nome do contato (ex.: "Claudia")',
                'data' => $responder->render('{data}'),
                'hora' => $responder->render('{hora}'),
            ],
            'deleting' => $this->confirmingDeleteId ? $this->find($this->confirmingDeleteId) : null,
        ]);
    }
}
