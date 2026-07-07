<?php

namespace App\Http\Controllers;

use App\Models\SystemEvent;
use App\Servers\AgentToken;
use App\Servers\MetricsBuffer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Servidores S1 — ingestao de metricas dos agentes coletores (PUSH). Endpoint
 * PUBLICO (agentes podem estar em redes externas) — mesmo nivel de risco do
 * webhook de billing. Invariantes:
 *
 *  1. BARATO DE REJEITAR: tamanho checado ANTES de parse/query (413); rate
 *     limit por token/IP no middleware (throttle:server-ingest, 429) ANTES
 *     de chegar aqui; payload malformado -> 422 sem efeito colateral.
 *  2. AUTENTICIDADE + ISOLAMENTO: o token do header resolve O SERVIDOR dono
 *     (sha256 indexado + hash_equals — AgentToken::resolve). Sem/errado ->
 *     401 SEM gravar nada. Um token JAMAIS alimenta outro servidor; a conta
 *     vem do proprio servidor resolvido.
 *  3. FORA DO CICLO DE REQUEST: grava o MINIMO (amostra no buffer efemero +
 *     last_seen_at/last_sample duraveis) e responde. NENHUMA avaliacao de
 *     alerta, NENHUM envio — isso e S2/S3, em command agendado/fila.
 *  4. LOG SEM INFLAR: SystemEvent com ref idempotente POR JANELA (1/hora por
 *     servidor; falhas de auth 1/hora por IP). O token NUNCA vai pro log.
 */
class ServerIngestController extends Controller
{
    private const MAX_BYTES = 16384; // payload legitimo tem ~1 KB

    public function __invoke(Request $request, AgentToken $tokens, MetricsBuffer $buffer): JsonResponse
    {
        // 1. tamanho — rejeita antes de qualquer parse/query.
        if (strlen((string) $request->getContent()) > self::MAX_BYTES) {
            return response()->json(['error' => 'payload too large'], 413);
        }

        // 2. autenticidade: token -> servidor (timing-safe). 401 sem gravar.
        $server = $tokens->resolve((string) $request->header('X-Agent-Token', ''));
        if ($server === null) {
            $this->logAuthFalha($request->ip());

            return response()->json(['error' => 'unauthorized'], 401);
        }
        if (! $server->enabled) {
            // Token valido mas servidor desativado na UI: nao ingere (403 ajuda
            // o dono a diagnosticar o coletor sem reativar por engano).
            return response()->json(['error' => 'server disabled'], 403);
        }

        // 3. validacao minima e barata (campos da Fase 0; so o necessario pros
        //    alertas da S2). Malformado -> 422 sem efeito colateral.
        $v = Validator::make((array) $request->json()->all(), [
            'agent_version' => 'nullable|string|max:20',
            'ts' => 'nullable|integer|min:0',
            'cpu_pct' => 'required|numeric|min:0|max:100',
            'cpu_count' => 'nullable|integer|min:1|max:1024',
            'load' => 'nullable|array|max:3',
            'load.*' => 'numeric|min:0|max:100000',
            'mem' => 'required|array',
            'mem.pct' => 'required|numeric|min:0|max:100',
            'mem.total_mb' => 'nullable|numeric|min:0',
            'mem.used_mb' => 'nullable|numeric|min:0',
            'swap' => 'nullable|array',
            'swap.pct' => 'nullable|numeric|min:0|max:100',
            'swap.total_mb' => 'nullable|numeric|min:0',
            'swap.used_mb' => 'nullable|numeric|min:0',
            'disks' => 'required|array|min:1|max:20',
            'disks.*.mount' => 'required|string|max:120',
            'disks.*.pct' => 'required|numeric|min:0|max:100',
            'disks.*.total_gb' => 'nullable|numeric|min:0',
            'disks.*.used_gb' => 'nullable|numeric|min:0',
        ]);
        if ($v->fails()) {
            $this->logPayloadInvalido($server->id, $server->account_id, $server->name, $v->errors()->keys());

            return response()->json(['error' => 'invalid payload', 'fields' => $v->errors()->keys()], 422);
        }
        $data = $v->validated();

        // 4. grava o MINIMO: amostra NORMALIZADA (so campos conhecidos) no
        //    buffer efemero + estado corrente duravel no MySQL.
        $sample = [
            'ts' => $data['ts'] ?? now()->getTimestamp(),
            'received_at' => now()->getTimestamp(),
            'cpu_pct' => (float) $data['cpu_pct'],
            'cpu_count' => isset($data['cpu_count']) ? (int) $data['cpu_count'] : null,
            'load' => isset($data['load']) ? array_map(fn ($n) => (float) $n, array_values($data['load'])) : null,
            'mem_pct' => (float) data_get($data, 'mem.pct'),
            'swap_pct' => data_get($data, 'swap.pct') !== null ? (float) data_get($data, 'swap.pct') : null,
            'disks' => array_map(fn (array $d) => [
                'mount' => (string) $d['mount'],
                'pct' => (float) $d['pct'],
            ], $data['disks']),
            'agent_version' => $data['agent_version'] ?? null,
        ];

        $buffer->push($server->id, $sample);

        // last_seen_at e a base DURAVEL do watchdog (S2): sobrevive a flush do
        // Redis. Sem timestamps: updated_at segue significando "config mudou",
        // nao "reportou" (ingestao chega a cada 15-30s).
        $server->timestamps = false;
        $server->forceFill(['last_seen_at' => now(), 'last_sample' => $sample])->save();

        // 5. log 1/hora por servidor (ref idempotente) — visivel no /logs sem inflar.
        $this->logIngestaoOk($server->id, $server->account_id, $server->name);

        return response()->json(['received' => true]);
    }

    /** Evento informativo 1/hora por servidor. Best-effort: log nunca derruba a ingestao. */
    private function logIngestaoOk(int $serverId, int $accountId, string $name): void
    {
        try {
            SystemEvent::withoutAccountScope()->firstOrCreate(
                ['ref' => 'srv-ingest:'.$serverId.':'.now()->format('YmdH')],
                [
                    'account_id' => $accountId,
                    'type' => 'servidores',
                    'level' => 'info',
                    'title' => "Servidor {$name}: recebendo metricas",
                    'detail' => ['server_id' => $serverId],
                    'occurred_at' => now(),
                ],
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** Token ausente/invalido: warning 1/hora por IP. NUNCA loga o token. */
    private function logAuthFalha(?string $ip): void
    {
        try {
            SystemEvent::withoutAccountScope()->firstOrCreate(
                ['ref' => 'srv-ingest-auth:'.sha1((string) $ip).':'.now()->format('YmdH')],
                [
                    'account_id' => null, // nao sabemos a conta (token nao resolveu)
                    'type' => 'servidores',
                    'level' => 'warning',
                    'title' => 'Ingestao de metricas: token ausente ou invalido',
                    'detail' => ['ip' => $ip],
                    'occurred_at' => now(),
                ],
            );
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** Payload invalido de um agente AUTENTICADO: warning 1/hora por servidor. */
    private function logPayloadInvalido(int $serverId, int $accountId, string $name, array $campos): void
    {
        try {
            SystemEvent::withoutAccountScope()->firstOrCreate(
                ['ref' => 'srv-ingest-payload:'.$serverId.':'.now()->format('YmdH')],
                [
                    'account_id' => $accountId,
                    'type' => 'servidores',
                    'level' => 'warning',
                    'title' => "Servidor {$name}: payload de metricas invalido",
                    'detail' => ['server_id' => $serverId, 'campos' => array_slice($campos, 0, 10)],
                    'occurred_at' => now(),
                ],
            );
        } catch (\Throwable) {
            // best-effort
        }
    }
}
