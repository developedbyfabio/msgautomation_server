<?php

namespace Tests\Benchmark;

use App\Models\Account;
use App\Models\AutoReplyRule;
use App\Whatsapp\AutoReply\RuleMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fatia 19 — micro-benchmark do RuleMatcher::match (fora das suites default do
 * phpunit: tests/Benchmark nao esta em <testsuites>). Rodar explicito:
 *   php artisan test tests/Benchmark/MatchBench.php
 *
 * Carga: 200 gatilhos mistos (1/3 exact, 1/3 contains, 1/3 tolerante media)
 * distribuidos em 100 regras x 1.000 mensagens variadas (com e sem match, com
 * typos). Reporta media e p95 por mensagem em ms. Deterministico (seed fixa).
 */
class MatchBench extends TestCase
{
    use RefreshDatabase;

    public function test_bench_200_gatilhos_x_1000_mensagens(): void
    {
        mt_srand(42);
        $account = Account::create(['name' => 'Bench']);

        $palavras = [
            'senha', 'wifi', 'preco', 'horario', 'endereco', 'entrega', 'orcamento', 'pagamento',
            'cardapio', 'promocao', 'agendamento', 'consulta', 'suporte', 'atendente', 'garantia',
            'trocas', 'estoque', 'produto', 'servico', 'boleto', 'pix', 'cartao', 'desconto',
            'funcionamento', 'reserva', 'pedido', 'nota', 'fiscal', 'catalogo', 'novidade',
        ];

        // 100 regras x 2 gatilhos = 200 gatilhos (tipos alternados).
        foreach (range(1, 100) as $i) {
            $rule = AutoReplyRule::create([
                'account_id' => $account->id, 'match_type' => 'contains', 'match_value' => "bench{$i}",
                'response_text' => "resposta {$i}", 'enabled' => true,
            ]);
            $rule->responses()->create(['response_text' => "resposta {$i}"]);
            foreach (range(1, 2) as $j) {
                $tipo = ['exact', 'contains', 'contains'][($i + $j) % 3];
                $tolerante = ($i + $j) % 3 === 2;
                $valor = $palavras[($i * 2 + $j) % count($palavras)] . ' ' . $palavras[($i * 7 + $j) % count($palavras)];
                $rule->triggers()->create([
                    'match_type' => $tipo,
                    'match_value' => $valor,
                    'precision' => $tolerante ? 'tolerante' : 'exato',
                    'fuzzy_level' => $tolerante ? 'media' : null,
                ]);
            }
        }

        // 1.000 mensagens: mistura de match exato, typo, e ruido sem match.
        $mensagens = [];
        foreach (range(1, 1000) as $k) {
            $a = $palavras[mt_rand(0, count($palavras) - 1)];
            $b = $palavras[mt_rand(0, count($palavras) - 1)];
            $mensagens[] = match ($k % 5) {
                0 => "oi, qual o {$a} {$b} por favor?",
                1 => "me fala o " . substr($a, 0, -1) . " {$b}",          // typo: falta letra
                2 => "quero saber sobre {$a}",
                3 => "bom dia! tudo bem com voces? " . str_repeat('bla ', 10),
                default => "{$a} {$b}",
            };
        }

        $matcher = app(RuleMatcher::class);
        // Warm-up (carrega relacoes/opcache paths).
        foreach (array_slice($mensagens, 0, 20) as $m) {
            $matcher->match($account->id, null, $m);
        }

        $tempos = [];
        foreach ($mensagens as $m) {
            $t0 = hrtime(true);
            $matcher->match($account->id, null, $m);
            $tempos[] = (hrtime(true) - $t0) / 1e6; // ms
        }

        sort($tempos);
        $media = array_sum($tempos) / count($tempos);
        $p95 = $tempos[(int) floor(count($tempos) * 0.95)];

        fwrite(STDERR, sprintf(
            "\n[MatchBench] mensagens=%d gatilhos=200 | media=%.3f ms | p95=%.3f ms | total=%.1f ms\n",
            count($tempos), $media, $p95, array_sum($tempos),
        ));

        $this->assertLessThan(50, $media, 'sanidade: media por mensagem explodiu');
    }
}
