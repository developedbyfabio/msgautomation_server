<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\SystemEvent;
use App\Servers\AgentToken;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Servidores S1 — endpoint de ingestao (o coracao da fatia). Invariantes:
 * autenticidade (401 sem gravar NADA), isolamento por servidor (token de A
 * jamais alimenta B), rejeicao barata (413/422 sem efeito colateral), rate
 * limit (429), buffer EFEMERO (trim+TTL, sem tabela de historico) e
 * last_seen_at DURAVEL no MySQL (base do watchdog da S2 — sobrevive a flush
 * do cache). Log via SystemEvent com ref idempotente e SEM token.
 */
class ServersIngestTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Server $server;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Interna']);
        $this->server = Server::create([
            'account_id' => $this->account->id,
            'name' => 'srv-teste',
            'host' => '10.0.0.10',
            'os' => 'linux',
        ]);
        $this->token = app(AgentToken::class)->issue($this->server);
    }

    /** Payload valido minimo (formato da Fase 0). */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'agent_version' => '1',
            'ts' => now()->getTimestamp(),
            'cpu_pct' => 37.2,
            'cpu_count' => 8,
            'load' => [1.2, 0.9, 0.7],
            'mem' => ['total_mb' => 16000, 'used_mb' => 9200, 'pct' => 57.5],
            'swap' => ['total_mb' => 4096, 'used_mb' => 120, 'pct' => 2.9],
            'disks' => [['mount' => '/', 'total_gb' => 100, 'used_gb' => 63, 'pct' => 63.0]],
        ], $extra);
    }

    private function ingest(array $payload, ?string $token = null)
    {
        $headers = $token !== null ? ['X-Agent-Token' => $token] : [];

        return $this->postJson(route('webhook.servers.ingest'), $payload, $headers);
    }

    // ---- autenticidade -------------------------------------------------------

    public function test_token_valido_200_grava_buffer_e_last_seen(): void
    {
        $this->ingest($this->payload(), $this->token)->assertOk();

        $samples = app(MetricsBuffer::class)->samples($this->server->id);
        $this->assertCount(1, $samples);
        $this->assertSame(37.2, $samples[0]['cpu_pct']);
        $this->assertSame(57.5, $samples[0]['mem_pct']);
        $this->assertSame('/', $samples[0]['disks'][0]['mount']);

        $fresh = $this->server->fresh();
        $this->assertNotNull($fresh->last_seen_at);
        $this->assertSame(37.2, $fresh->last_sample['cpu_pct']);
    }

    public function test_sem_token_401_e_nada_e_gravado(): void
    {
        $this->ingest($this->payload())->assertStatus(401);

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertNull($this->server->fresh()->last_seen_at);
    }

    public function test_token_errado_401_e_nada_e_gravado(): void
    {
        $this->ingest($this->payload(), 'agt_'.str_repeat('x', 48))->assertStatus(401);

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertNull($this->server->fresh()->last_seen_at);
        // Falha de auth vira warning no /logs (1/hora por IP), SEM o token.
        $evento = SystemEvent::withoutAccountScope()->where('type', 'servidores')->where('level', 'warning')->first();
        $this->assertNotNull($evento);
    }

    public function test_servidor_desativado_403_e_nada_e_gravado(): void
    {
        $this->server->update(['enabled' => false]);

        $this->ingest($this->payload(), $this->token)->assertStatus(403);

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertNull($this->server->fresh()->last_seen_at);
    }

    // ---- rejeicao barata -----------------------------------------------------

    public function test_payload_malformado_422_sem_efeito_colateral(): void
    {
        // Sem cpu_pct nem mem: nao da pra avaliar nada — rejeita.
        $this->ingest(['disks' => [['mount' => '/', 'pct' => 10]]], $this->token)
            ->assertStatus(422);

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertNull($this->server->fresh()->last_seen_at);
    }

    public function test_payload_sem_discos_e_aceito_e_nao_cega_o_watchdog(): void
    {
        // Coletor que so nao achou disco local (mount de rede morto, so pseudo-FS)
        // ainda entrega CPU/RAM/swap/load. NAO pode virar 422 -> coletor morto ->
        // watchdog falso. Aceita (200), grava a amostra e ATUALIZA last_seen.
        $p = $this->payload();
        unset($p['disks']);

        $this->ingest($p, $this->token)->assertOk();

        $samples = app(MetricsBuffer::class)->samples($this->server->id);
        $this->assertCount(1, $samples);
        $this->assertSame([], $samples[0]['disks']); // sem disco, mas amostra valida
        $this->assertSame(37.2, $samples[0]['cpu_pct']);
        // last_seen atualizado -> o watchdog (S2) NAO dispara "sem reportar".
        $this->assertNotNull($this->server->fresh()->last_seen_at);
    }

    public function test_payload_com_lista_de_discos_vazia_e_aceito(): void
    {
        $this->ingest($this->payload(['disks' => []]), $this->token)->assertOk();

        $samples = app(MetricsBuffer::class)->samples($this->server->id);
        $this->assertCount(1, $samples);
        $this->assertSame([], $samples[0]['disks']);
        $this->assertNotNull($this->server->fresh()->last_seen_at);
    }

    public function test_payload_gigante_413_antes_de_qualquer_processamento(): void
    {
        $this->ingest($this->payload(['junk' => str_repeat('a', 17000)]), $this->token)
            ->assertStatus(413);

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertNull($this->server->fresh()->last_seen_at);
    }

    public function test_rate_limit_429_apos_estourar_o_limite_por_token(): void
    {
        // Limite: 10/min por token (cadencia legitima e 2-4/min).
        foreach (range(1, 10) as $i) {
            $this->ingest($this->payload(), $this->token)->assertOk();
        }

        $this->ingest($this->payload(), $this->token)->assertStatus(429);
    }

    // ---- isolamento ----------------------------------------------------------

    public function test_token_de_um_servidor_jamais_alimenta_outro(): void
    {
        $outro = Server::create([
            'account_id' => $this->account->id,
            'name' => 'srv-outro',
            'os' => 'linux',
        ]);
        app(AgentToken::class)->issue($outro);

        $this->ingest($this->payload(), $this->token)->assertOk();

        $this->assertCount(1, app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertSame([], app(MetricsBuffer::class)->samples($outro->id));
        $this->assertNotNull($this->server->fresh()->last_seen_at);
        $this->assertNull($outro->fresh()->last_seen_at);
    }

    // ---- buffer efemero + last_seen duravel -----------------------------------

    public function test_buffer_respeita_o_trim_da_janela(): void
    {
        $buffer = app(MetricsBuffer::class);
        foreach (range(1, MetricsBuffer::MAX_SAMPLES + 10) as $i) {
            $buffer->push($this->server->id, ['cpu_pct' => (float) $i]);
        }

        $samples = $buffer->samples($this->server->id);
        $this->assertCount(MetricsBuffer::MAX_SAMPLES, $samples);
        // Mais recente primeiro; as 10 mais antigas foram aparadas.
        $this->assertSame((float) (MetricsBuffer::MAX_SAMPLES + 10), $samples[0]['cpu_pct']);
    }

    public function test_buffer_expira_pelo_ttl(): void
    {
        app(MetricsBuffer::class)->push($this->server->id, ['cpu_pct' => 1.0]);
        $this->assertCount(1, app(MetricsBuffer::class)->samples($this->server->id));

        $this->travel(MetricsBuffer::TTL_SECONDS + 60)->seconds();

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
    }

    public function test_last_seen_sobrevive_a_flush_do_cache(): void
    {
        $this->ingest($this->payload(), $this->token)->assertOk();

        Cache::flush(); // "flush do Redis" simulado: buffer some...

        $this->assertSame([], app(MetricsBuffer::class)->samples($this->server->id));
        $this->assertNotNull($this->server->fresh()->last_seen_at); // ...watchdog (S2) nao cega
    }

    public function test_nenhuma_tabela_de_historico_e_criada(): void
    {
        // Guard-rail da decisao de arquitetura: 3 ingestoes nao criam linha
        // alem do estado corrente do proprio servidor (nada de TSDB).
        foreach (range(1, 3) as $i) {
            $this->ingest($this->payload(), $this->token)->assertOk();
        }

        $this->assertSame(1, Server::withoutAccountScope()->count());
        $this->assertCount(3, app(MetricsBuffer::class)->samples($this->server->id));
    }

    // ---- log -------------------------------------------------------------------

    public function test_log_de_ingestao_e_idempotente_por_janela(): void
    {
        $this->ingest($this->payload(), $this->token)->assertOk();
        $this->ingest($this->payload(), $this->token)->assertOk();

        // Duas ingestoes na mesma hora = UM evento (ref srv-ingest:{id}:{YmdH}).
        $this->assertSame(1, SystemEvent::withoutAccountScope()
            ->where('type', 'servidores')->where('level', 'info')->count());
    }

    public function test_token_nunca_aparece_nos_logs(): void
    {
        $this->ingest($this->payload(), $this->token)->assertOk();
        $this->ingest($this->payload(), 'agt_'.str_repeat('x', 48))->assertStatus(401);

        foreach (SystemEvent::withoutAccountScope()->get() as $evento) {
            $linha = json_encode($evento->getAttributes());
            $this->assertStringNotContainsString($this->token, $linha);
            $this->assertStringNotContainsString('agt_'.str_repeat('x', 48), $linha);
        }
    }

    public function test_token_nao_fica_em_claro_na_tabela_servers(): void
    {
        $linha = json_encode($this->server->fresh()->getAttributes());
        $this->assertStringNotContainsString($this->token, $linha);
        $this->assertSame(hash('sha256', $this->token), $this->server->fresh()->agent_token_hash);
    }
}
