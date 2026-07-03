<?php

namespace App\Metrics;

use App\Models\AiDecision;
use App\Models\AutoReplyLog;
use App\Models\AutoReplySetting;
use App\Models\Board;
use App\Models\CampaignTarget;
use App\Models\Card;
use App\Models\CardTransition;
use App\Models\FlowSession;
use App\Models\IncomingMessage;
use App\Models\PendingApproval;
use App\Models\ProactiveCampaign;
use App\Whatsapp\Proactive\ProactiveGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * M-1 — leitura PURA dos numeros que o sistema JA registra (nenhuma coleta nova,
 * pipeline intocado). Agregados SQL por conta+periodo (fuso SP na janela de datas)
 * com CACHE de 60s por conta+periodo (navegar nao martela o banco).
 *
 * Origem das respostas (derivada dos logs existentes):
 *  - manual | aprovacao | proativa: direto do mode do log;
 *  - mode 'auto' subdividido cruzando com ai_decisions (acao=respondeu) da MESMA
 *    incoming_message: origem regra -> "IA (casou regra)"; origem base -> "IA
 *    (base)"; sem decisao: rule_id preenchido -> regra deterministica; rule_id
 *    null -> fluxo (texto direto do no).
 *
 * Mediana de 1a resposta (definicao documentada): POR CONTATO no periodo — do
 * primeiro incoming individual do contato ate o primeiro envio pra ele depois
 * disso. Mediana (nao media): outliers de horas nao distorcem. Sem resposta =
 * fora da mediana.
 */
class PainelMetrics
{
    public const PERIODOS = ['hoje' => 'Hoje', '7d' => '7 dias', '30d' => '30 dias'];

    /** Payload completo do painel (cacheado 60s por conta+periodo). */
    public function dados(int $accountId, string $periodo): array
    {
        $periodo = array_key_exists($periodo, self::PERIODOS) ? $periodo : '7d';

        return Cache::remember("painel:{$accountId}:{$periodo}", 60, function () use ($accountId, $periodo) {
            [$from, $to] = $this->janela($periodo);

            return [
                'resumo' => $this->resumo($accountId, $from, $to),
                'origens' => $this->porOrigem($accountId, $from, $to),
                'ia' => $this->ia($accountId, $from, $to),
                'fluxos' => $this->fluxos($accountId, $from, $to),
                'kanban' => $this->kanban($accountId, $from, $to),
                'proativas' => $this->proativas($accountId, $from, $to),
            ];
        });
    }

    /** Janela [from, to] em UTC a partir do periodo em horario de SP. */
    private function janela(string $periodo): array
    {
        $tz = (string) config('app.display_timezone');
        $agora = Carbon::now($tz);

        $from = match ($periodo) {
            'hoje' => $agora->copy()->startOfDay(),
            '30d' => $agora->copy()->subDays(30),
            default => $agora->copy()->subDays(7),
        };

        return [$from->utc(), $agora->copy()->utc()];
    }

    private function resumo(int $accountId, Carbon $from, Carbon $to): array
    {
        $base = IncomingMessage::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('from_me', false)
            // Prompt 16: reacao nao e mensagem — fora da contagem (neutraliza as linhas
            // historicas e defende caso alguma escape o corte da ingestao).
            ->whereNotIn('type', IncomingMessage::REACTION_TYPES)
            ->whereBetween('received_at', [$from, $to]);

        $recebidas = (clone $base)->where('remote_jid', 'not like', '%@g.us')->count();
        $grupos = (clone $base)->where('remote_jid', 'like', '%@g.us')->count();

        $porMode = AutoReplyLog::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])
            ->selectRaw('mode, COUNT(*) as total')
            ->groupBy('mode')
            ->pluck('total', 'mode');
        $enviadas = (int) $porMode->sum();
        $automaticas = (int) ($porMode['auto'] ?? 0);

        return [
            'recebidas' => $recebidas,
            'grupos' => $grupos,
            'enviadas' => $enviadas,
            'pct_automatico' => $enviadas > 0 ? (int) round($automaticas / $enviadas * 100) : 0,
            'mediana_primeira_resposta' => $this->medianaPrimeiraResposta($accountId, $from, $to),
        ];
    }

    /** Mediana (segundos) da 1a resposta por CONTATO no periodo; null sem pares. */
    private function medianaPrimeiraResposta(int $accountId, Carbon $from, Carbon $to): ?int
    {
        // 1 query: primeiro incoming individual de cada contato no periodo.
        $primeiros = IncomingMessage::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('from_me', false)
            ->where('remote_jid', 'not like', '%@g.us')
            ->whereBetween('received_at', [$from, $to])
            ->selectRaw('remote_jid, MIN(received_at) as primeira')
            ->groupBy('remote_jid')
            ->pluck('primeira', 'remote_jid');

        if ($primeiros->isEmpty()) {
            return null;
        }

        // 1 query: envios do periodo pros mesmos contatos; casamento em memoria.
        $envios = AutoReplyLog::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])
            ->whereIn('remote_jid', $primeiros->keys())
            ->orderBy('sent_at')
            ->get(['remote_jid', 'sent_at'])
            ->groupBy('remote_jid');

        $deltas = [];
        foreach ($primeiros as $jid => $primeira) {
            $primeira = Carbon::parse($primeira);
            $resposta = $envios->get($jid)?->first(fn ($l) => $l->sent_at->gt($primeira));
            if ($resposta) {
                $deltas[] = $primeira->diffInSeconds($resposta->sent_at);
            }
            // Sem resposta: fora da mediana (definicao).
        }

        if ($deltas === []) {
            return null;
        }
        sort($deltas);
        $n = count($deltas);

        // Mediana: elemento central (par -> media dos dois centrais).
        return $n % 2 === 1
            ? (int) $deltas[intdiv($n, 2)]
            : (int) round(($deltas[$n / 2 - 1] + $deltas[$n / 2]) / 2);
    }

    /** @return array<string,int> rotulo => total (respostas por origem) */
    private function porOrigem(int $accountId, Carbon $from, Carbon $to): array
    {
        $logs = AutoReplyLog::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('status', 'sent')
            ->whereBetween('sent_at', [$from, $to])
            ->get(['mode', 'rule_id', 'incoming_message_id']);

        // Decisoes da IA que RESPONDERAM no periodo (mapeia incoming -> origem IA).
        $ia = AiDecision::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('acao', 'respondeu')
            ->whereBetween('created_at', [$from->copy()->subDay(), $to]) // margem: decisao antecede o envio
            ->whereNotNull('incoming_message_id')
            ->pluck('origem', 'incoming_message_id');

        $out = [
            'Regra deterministica' => 0, 'Fluxo' => 0, 'IA (casou regra)' => 0,
            'IA (base)' => 0, 'Aprovacao humana' => 0, 'Manual' => 0, 'Proativa' => 0,
        ];

        foreach ($logs as $log) {
            if ($log->mode === 'manual') {
                $out['Manual']++;
            } elseif ($log->mode === 'aprovacao') {
                $out['Aprovacao humana']++;
            } elseif ($log->mode === 'proactive') {
                $out['Proativa']++;
            } else { // auto
                $origemIa = $log->incoming_message_id ? $ia->get($log->incoming_message_id) : null;
                if ($origemIa === 'regra') {
                    $out['IA (casou regra)']++;
                } elseif ($origemIa === 'base') {
                    $out['IA (base)']++;
                } elseif ($log->rule_id !== null) {
                    $out['Regra deterministica']++;
                } else {
                    $out['Fluxo']++;
                }
            }
        }

        return $out;
    }

    private function ia(int $accountId, Carbon $from, Carbon $to): array
    {
        $porAcao = AiDecision::withoutAccountScope()
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('acao, COUNT(*) as total')
            ->groupBy('acao')
            ->pluck('total', 'acao')
            ->all();

        $topIntents = AiDecision::withoutAccountScope()
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('intent')
            ->selectRaw('intent, COUNT(*) as total')
            ->groupBy('intent')
            ->orderByDesc('total')
            ->limit(5)
            ->pluck('total', 'intent')
            ->all();

        $settings = AutoReplySetting::withoutAccountScope()->firstOrCreate(['account_id' => $accountId]);
        $dia = Carbon::now((string) config('app.display_timezone'))->format('Y-m-d');
        $consumoDia = (int) Cache::get("ai:{$accountId}:gemini:calls:{$dia}", 0);

        $expDias = (int) config('ai.approval_expire_days', 7);
        $pendencias = PendingApproval::withoutAccountScope()
            ->where('account_id', $accountId)
            ->where('status', 'pending')
            ->when($expDias > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($expDias)))
            ->count();

        return [
            'por_acao' => $porAcao,
            'top_intents' => $topIntents,
            'consumo_dia' => $consumoDia,
            'cota_dia' => (int) config('services.gemini.daily_cap', 1000),
            'pendencias' => $pendencias,
        ];
    }

    private function fluxos(int $accountId, Carbon $from, Carbon $to): array
    {
        $sessoes = FlowSession::withoutAccountScope()
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $topFluxos = FlowSession::withoutAccountScope()
            ->where('flow_sessions.account_id', $accountId)
            ->whereBetween('flow_sessions.created_at', [$from, $to])
            ->join('flows', 'flows.id', '=', 'flow_sessions.flow_id')
            ->selectRaw('flows.name, COUNT(*) as total')
            ->groupBy('flows.name')
            ->orderByDesc('total')
            ->limit(3)
            ->pluck('total', 'name')
            ->all();

        return [
            'iniciadas' => (int) $sessoes->sum(),
            'concluidas' => (int) ($sessoes['completed'] ?? 0),
            'expiradas' => (int) ($sessoes['expired'] ?? 0),
            'top' => $topFluxos,
        ];
    }

    private function kanban(int $accountId, Carbon $from, Carbon $to): array
    {
        $criados = Card::withoutAccountScope()
            ->where('account_id', $accountId)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $board = Board::withoutAccountScope()
            ->where('account_id', $accountId)->where('is_default', true)
            ->with('columns')->first();
        $nomes = $board?->columns->pluck('name', 'id') ?? collect();

        // Transicoes do periodo por coluna DESTINO (join implicito via card->board da conta).
        $transicoes = CardTransition::query()
            ->whereBetween('card_transitions.created_at', [$from, $to])
            ->join('cards', 'cards.id', '=', 'card_transitions.card_id')
            ->where('cards.account_id', $accountId)
            ->selectRaw('card_transitions.to_column_id, COUNT(*) as total')
            ->groupBy('card_transitions.to_column_id')
            ->pluck('total', 'to_column_id')
            ->mapWithKeys(fn ($total, $colId) => [(string) ($nomes[$colId] ?? 'coluna removida') => (int) $total])
            ->all();

        // Retrato AGORA: cards por coluna.
        $agora = Card::withoutAccountScope()
            ->where('account_id', $accountId)
            ->selectRaw('column_id, COUNT(*) as total')
            ->groupBy('column_id')
            ->pluck('total', 'column_id')
            ->mapWithKeys(fn ($total, $colId) => [(string) ($nomes[$colId] ?? 'coluna removida') => (int) $total])
            ->all();

        return ['criados' => $criados, 'transicoes' => $transicoes, 'agora' => $agora];
    }

    private function proativas(int $accountId, Carbon $from, Carbon $to): array
    {
        $campanhaIds = ProactiveCampaign::withoutAccountScope()
            ->where('account_id', $accountId)->pluck('id');

        $enviadas = CampaignTarget::query()->whereIn('campaign_id', $campanhaIds)
            ->where('status', 'sent')->whereBetween('sent_at', [$from, $to])->count();
        $puladas = CampaignTarget::query()->whereIn('campaign_id', $campanhaIds)
            ->where('status', 'skipped')->whereBetween('updated_at', [$from, $to])
            ->selectRaw('skip_reason, COUNT(*) as total')
            ->groupBy('skip_reason')->pluck('total', 'skip_reason')->all();
        $falhadas = CampaignTarget::query()->whereIn('campaign_id', $campanhaIds)
            ->where('status', 'failed')->whereBetween('updated_at', [$from, $to])->count();

        $settings = AutoReplySetting::withoutAccountScope()->firstOrCreate(['account_id' => $accountId]);

        return [
            'enviadas' => $enviadas,
            'puladas' => $puladas,
            'falhadas' => $falhadas,
            'consumo_dia' => app(ProactiveGuard::class)->dayCount($accountId),
            'teto_dia' => (int) $settings->proactive_daily_cap,
            'campanhas_ativas' => ProactiveCampaign::withoutAccountScope()
                ->where('account_id', $accountId)
                ->whereIn('status', ['approved', 'running', 'paused'])->count(),
        ];
    }
}
