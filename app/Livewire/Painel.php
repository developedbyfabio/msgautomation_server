<?php

namespace App\Livewire;

use App\Metrics\PainelMetrics;
use App\Tenancy\AccountContext;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * M-1 — /painel: visao do dono com os numeros que o sistema JA registra. Leitura
 * PURA (nada de dominio e escrito; so cache de 60s por conta+periodo). SEM
 * polling: botao "Atualizar" (dados agregados nao mudam a cada segundo; o cache
 * de 60s tornaria polling curto inutil e polling longo confunde — decisao
 * documentada no relatorio da fatia).
 */
#[Layout('components.layouts.app')]
class Painel extends Component
{
    public string $periodo = '7d'; // hoje | 7d | 30d

    // MATCH-1 — "virar regra" a partir do sem-match (mesmo caminho oficial da
    // promocao da Fatia 4: RuleWriter, guardas todas).
    public ?int $promoteUnmatchedId = null;
    public string $uTrigger = '';
    public string $uResponse = '';

    public function setPeriodo(string $periodo): void
    {
        if (array_key_exists($periodo, PainelMetrics::PERIODOS)) {
            $this->periodo = $periodo;
        }
    }

    /** Botao Atualizar: derruba o cache do periodo atual e re-le. */
    public function atualizar(): void
    {
        Cache::forget('painel:' . $this->accountId() . ':' . $this->periodo);
        $this->dispatch('toast', message: 'Painel atualizado.');
    }

    private function accountId(): int
    {
        return app(AccountContext::class)->id();
    }

    // ---- MATCH-1: sem-match -> regra ------------------------------------------

    public function abrirVirarRegra(int $id): void
    {
        $u = \App\Models\UnmatchedMessage::query()->find($id);
        if (! $u) {
            return;
        }
        $this->promoteUnmatchedId = $u->id;
        $this->uTrigger = (string) $u->text;
        $this->uResponse = '';
        $this->resetValidation();
    }

    public function fecharVirarRegra(): void
    {
        $this->promoteUnmatchedId = null;
        $this->reset(['uTrigger', 'uResponse']);
        $this->resetValidation();
    }

    public function confirmVirarRegra(\App\Whatsapp\AutoReply\RuleWriter $writer): void
    {
        $u = \App\Models\UnmatchedMessage::query()->find($this->promoteUnmatchedId);
        if (! $u) {
            $this->fecharVirarRegra();

            return;
        }
        if (trim($this->uTrigger) === '') {
            $this->addError('uTrigger', 'Informe o gatilho.');

            return;
        }
        if (trim($this->uResponse) === '') {
            $this->addError('uResponse', 'Informe a resposta.');

            return;
        }

        // Caminho OFICIAL (guardas todas: S5, regex, escopo). Tolerante por default —
        // o objetivo e perdoar a digitacao real do WhatsApp.
        $res = $writer->save($this->accountId(), [
            'triggers' => [['type' => 'contains', 'value' => trim($this->uTrigger), 'precision' => 'tolerante', 'fuzzy_level' => 'media']],
            'responses' => [trim($this->uResponse)],
            'enabled' => true,
            'cooldown_mode' => 'global',
            'cooldown_minutes' => null,
            'scope' => 'global',
            'contact_ids' => [],
            'ai_match_enabled' => false,
            'ai_examples' => [],
        ]);

        if ($res['errors'] !== []) {
            foreach ($res['errors'] as $campo => $msg) {
                $this->addError(str_starts_with($campo, 'triggers') ? 'uTrigger' : 'uResponse', $msg);
            }

            return;
        }

        foreach ($res['warnings'] as $aviso) {
            $this->dispatch('toast', message: 'Aviso: ' . $aviso, type: 'error');
        }

        // O item (e os irmaos com o MESMO texto) some da lista quando promovido.
        \App\Models\UnmatchedMessage::query()->where('text', $u->text)->delete();

        $this->fecharVirarRegra();
        $this->dispatch('toast', message: 'Regra criada a partir do sem-match (gatilho tolerante). Teste no testador de /regras.');
    }

    /** Inicio do periodo selecionado (fuso de exibicao). */
    private function inicioDoPeriodo(): \Illuminate\Support\Carbon
    {
        $tz = (string) config('app.display_timezone');

        return match ($this->periodo) {
            'hoje' => now($tz)->startOfDay(),
            '30d' => now($tz)->subDays(30),
            default => now($tz)->subDays(7),
        };
    }

    public function render(PainelMetrics $metrics)
    {
        // MATCH-1 — bloco "Sem resposta": VIVO (fora do cache de 60s) porque o
        // "virar regra" remove itens na hora; agregado por texto (frequencia).
        $inicio = $this->inicioDoPeriodo();
        $semResposta = [
            'total' => \App\Models\UnmatchedMessage::query()->where('created_at', '>=', $inicio)->count(),
            'itens' => \App\Models\UnmatchedMessage::query()
                ->where('created_at', '>=', $inicio)
                ->selectRaw('MIN(id) as id, text, COUNT(*) as vezes, MAX(created_at) as ultima')
                ->groupBy('text')
                ->orderByDesc('vezes')->orderByDesc('ultima')
                ->limit(8)
                ->get(),
        ];

        return view('livewire.painel', [
            'dados' => $metrics->dados($this->accountId(), $this->periodo),
            'periodos' => PainelMetrics::PERIODOS,
            'semResposta' => $semResposta,
        ]);
    }
}
