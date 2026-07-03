<?php

namespace App\Livewire;

use App\Models\AutoReplyLog;
use App\Models\Channel;
use App\Models\IncomingMessage;
use App\Models\SystemEvent;
use App\Models\UnmatchedMessage;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Prompt 02 — /logs: timeline CURADA e SOMENTE-LEITURA dos acontecimentos da
 * conta (envios ok/falhos com code da Meta legivel, recebidas por canal,
 * sem-match, eventos de canal e erros do sistema). Nasce do buraco do 130497:
 * falha assincrona da Meta nunca mais some. Horario SEMPRE em SP (paraExibicao).
 * Fontes: tabelas existentes + system_events; nada aqui executa acao.
 */
#[Layout('components.layouts.app')]
class Logs extends Component
{
    public string $tipo = 'todos';     // todos|recebida|envio_ok|envio_falhou|sem_match|canal|erro_sistema
    public string $canal = 'todos';    // todos|evolution|cloud_api
    public string $periodo = '24h';    // hoje|24h|7d
    public int $limite = 50;

    public const TIPOS = [
        'todos' => 'Todos os eventos',
        'envio_falhou' => 'Envios que FALHARAM',
        'envio_ok' => 'Envios ok',
        'recebida' => 'Mensagens recebidas',
        'sem_match' => 'Sem resposta (sem match)',
        'canal' => 'Eventos de canal',
        'erro_sistema' => 'Erros do sistema',
    ];

    public function updated(): void
    {
        $this->limite = 50; // filtro novo recomeca a janela
    }

    public function carregarMais(): void
    {
        $this->limite += 50;
    }

    private function cutoff(): Carbon
    {
        return match ($this->periodo) {
            'hoje' => now()->paraExibicao()->startOfDay()->setTimezone(config('app.timezone')),
            '7d' => now()->subDays(7),
            default => now()->subDay(),
        };
    }

    /** @return array<int,int>|null ids dos canais do provider filtrado (null = sem filtro) */
    private function canaisFiltrados(): ?array
    {
        if ($this->canal === 'todos') {
            return null;
        }

        return Channel::query()->where('provider', $this->canal)->pluck('id')->all();
    }

    public function render()
    {
        $cutoff = $this->cutoff();
        $ids = $this->canaisFiltrados();
        $canais = Channel::query()->get(['id', 'instance', 'provider'])->keyBy('id');
        $rotulo = fn (?int $id) => $id && isset($canais[$id])
            ? ($canais[$id]->provider === 'cloud_api' ? 'Cloud' : 'Evolution')
            : '—';

        $eventos = collect();
        $quer = fn (string $t) => $this->tipo === 'todos' || $this->tipo === $t;

        if ($quer('recebida')) {
            $eventos = $eventos->concat(IncomingMessage::query()
                ->where('from_me', false)->where('received_at', '>=', $cutoff)
                // Prompt 17: reacao nao e mensagem — some da listagem de recebidas
                // (limpa as linhas historicas; novas ja nem entram, corte do prompt 16).
                ->whereNotIn('type', IncomingMessage::REACTION_TYPES)
                ->when($ids !== null, fn ($q) => $q->whereIn('channel_id', $ids))
                ->orderByDesc('received_at')->limit($this->limite)
                ->get()->map(fn ($m) => [
                    'tipo' => 'recebida', 'nivel' => 'info',
                    'titulo' => 'Recebida de ' . ($m->push_name ?: str($m->remote_jid)->before('@')) . ' (' . $rotulo($m->channel_id) . ')',
                    'detalhe' => str((string) $m->text)->limit(120)->toString(),
                    'extra' => null,
                    'quando' => $m->received_at,
                ]));
        }

        if ($quer('envio_ok') || $quer('envio_falhou')) {
            $eventos = $eventos->concat(AutoReplyLog::query()
                ->where('created_at', '>=', $cutoff)
                ->when($ids !== null, fn ($q) => $q->whereIn('channel_id', $ids))
                ->when($this->tipo === 'envio_ok', fn ($q) => $q->where('status', 'sent'))
                ->when($this->tipo === 'envio_falhou', fn ($q) => $q->whereIn('status', ['failed', 'blocked']))
                ->orderByDesc('id')->limit($this->limite)
                ->get()->map(fn ($l) => [
                    'tipo' => $l->status === 'sent' ? 'envio_ok' : 'envio_falhou',
                    'nivel' => match ($l->status) {
                        'sent' => 'info', 'blocked' => 'warning', default => 'error',
                    },
                    'titulo' => match ($l->status) {
                        'sent' => 'Resposta enviada pra ' . str($l->remote_jid)->before('@') . ' (' . $rotulo($l->channel_id) . ')',
                        'blocked' => 'Envio BLOQUEADO por freio (' . ($l->motivo ?: '?') . ') pra ' . str($l->remote_jid)->before('@'),
                        default => 'Envio FALHOU (' . ($l->motivo ?: 'erro') . ') pra ' . str($l->remote_jid)->before('@') . ' (' . $rotulo($l->channel_id) . ')',
                    },
                    'detalhe' => str((string) $l->response_text)->limit(120)->toString(),
                    'extra' => null,
                    'quando' => $l->sent_at ?: $l->created_at,
                ]));
        }

        if ($quer('sem_match')) {
            $eventos = $eventos->concat(UnmatchedMessage::query()
                ->with('contact:id,remote_jid,push_name')
                ->where('created_at', '>=', $cutoff)
                ->orderByDesc('id')->limit($this->limite)
                ->get()->map(fn ($u) => [
                    'tipo' => 'sem_match', 'nivel' => 'warning',
                    'titulo' => 'Sem resposta pra ' . ($u->contact?->push_name ?: str((string) $u->contact?->remote_jid)->before('@')),
                    'detalhe' => str((string) $u->text)->limit(120)->toString(),
                    'extra' => null,
                    'quando' => $u->created_at,
                ]));
        }

        if ($quer('envio_falhou') || $quer('canal') || $quer('erro_sistema')) {
            // Da conta (escopo normal) + GLOBAIS (account NULL — erro de servidor;
            // bypass NOMEADO por natureza do dado).
            $daConta = SystemEvent::query()
                ->where('occurred_at', '>=', $cutoff)
                ->when($ids !== null, fn ($q) => $q->whereIn('channel_id', $ids))
                ->when($this->tipo !== 'todos', fn ($q) => $q->where('type', $this->tipo))
                ->orderByDesc('occurred_at')->limit($this->limite)->get();
            $globais = ($quer('erro_sistema') && $this->canal === 'todos')
                ? SystemEvent::withoutAccountScope()->whereNull('account_id')
                    ->where('occurred_at', '>=', $cutoff)
                    ->when($this->tipo !== 'todos', fn ($q) => $q->where('type', $this->tipo))
                    ->orderByDesc('occurred_at')->limit($this->limite)->get()
                : collect();

            $eventos = $eventos->concat($daConta->concat($globais)->map(fn ($e) => [
                'tipo' => $e->type, 'nivel' => $e->level,
                'titulo' => $e->title . ($e->account_id === null ? ' [sistema]' : ''),
                'detalhe' => $e->detail
                    ? collect($e->detail)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ')
                    : null,
                'extra' => $e->detail,
                'quando' => $e->occurred_at,
            ]));
        }

        $eventos = $eventos->sortByDesc('quando')->values()->take($this->limite);

        return view('livewire.logs', [
            'eventos' => $eventos,
            'temMais' => $eventos->count() >= $this->limite,
        ]);
    }
}
