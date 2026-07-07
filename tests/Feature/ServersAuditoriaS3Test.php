<?php

namespace Tests\Feature;

use App\Livewire\Servidores\Incidentes;
use App\Models\Account;
use App\Models\SystemEvent;
use App\Models\User;
use App\Servers\AgentToken;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\IncidentManager;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use App\Tenancy\AccountContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Servidores S3 — auditoria pre-canal (A1..A6). Garante que a base da avaliacao
 * e solida ANTES de ligar o WhatsApp: isolamento multitenant (A1), watchdog pela
 * hora de recebimento (A2), for-duration em segundos robusto a cadencia/amostra
 * perdida (A3), debounce simetrico de resolucao (A4), ack que nao engole
 * escalada (A5) e poda/retencao coerente (A6).
 */
class ServersAuditoriaS3Test extends TestCase
{
    use RefreshDatabase;

    private function servidor(int $accountId, string $nome, array $extra = []): Server
    {
        return Server::withoutAccountScope()->create(array_merge([
            'account_id' => $accountId,
            'name' => $nome,
            'os' => 'linux',
            'last_seen_at' => now(),
        ], $extra));
    }

    /** Empurra amostras de cpu: [ageSeconds => valor] (o received_at e derivado). */
    private function cpu(int $serverId, array $pontos): void
    {
        $buffer = app(MetricsBuffer::class);
        foreach ($pontos as $age => $v) {
            $buffer->push($serverId, [
                'received_at' => now()->getTimestamp() - $age,
                'cpu_pct' => (float) $v,
                'mem_pct' => 10.0,
                'disks' => [['mount' => '/', 'pct' => 20.0]],
            ]);
        }
    }

    private function evaluate(Server $server): void
    {
        app(ServerEvaluator::class)->evaluate($server->fresh());
    }

    // ===== A1 — isolamento multitenant ==========================================

    public function test_a1_avaliar_servidor_de_a_nunca_toca_incidente_de_b(): void
    {
        $a = Account::create(['name' => 'Empresa A']);
        $b = Account::create(['name' => 'Empresa B']);
        AlertRuleDefaults::ensureFor($a->id);
        AlertRuleDefaults::ensureFor($b->id);
        $sa = $this->servidor($a->id, 'srv-a');
        $sb = $this->servidor($b->id, 'srv-b');

        // Só A viola CPU; B está calmo.
        $this->cpu($sa->id, [150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->cpu($sb->id, [150 => 5, 120 => 5, 90 => 5, 60 => 5, 30 => 5, 0 => 5]);

        $this->evaluate($sa);
        $this->evaluate($sb);

        $incidentes = Incident::withoutAccountScope()->get();
        $this->assertCount(1, $incidentes);
        $this->assertSame($a->id, $incidentes->first()->account_id);
        $this->assertSame($sa->id, $incidentes->first()->server_id);
    }

    public function test_a1_regra_global_e_por_tenant_editar_a_nao_muda_avaliacao_de_b(): void
    {
        $a = Account::create(['name' => 'Empresa A']);
        $b = Account::create(['name' => 'Empresa B']);
        AlertRuleDefaults::ensureFor($a->id);
        AlertRuleDefaults::ensureFor($b->id);

        // Cada empresa tem a SUA regra global (server_id NULL + account_id proprio).
        $this->assertSame(6, AlertRule::withoutAccountScope()->whereNull('server_id')->where('account_id', $a->id)->count());
        $this->assertSame(6, AlertRule::withoutAccountScope()->whereNull('server_id')->where('account_id', $b->id)->count());

        // A afrouxa a CPU critical para 50; B fica no default 95.
        AlertRule::withoutAccountScope()->where('account_id', $a->id)->whereNull('server_id')->where('metric', 'cpu')
            ->update(['critical_threshold' => 50, 'critical_for_s' => 60]);

        $sa = $this->servidor($a->id, 'srv-a');
        $sb = $this->servidor($b->id, 'srv-b');
        // cpu 60 nos dois: dispara em A (limiar 50), NAO em B (limiar 95).
        $this->cpu($sa->id, [90 => 60, 45 => 60, 0 => 60]);
        $this->cpu($sb->id, [90 => 60, 45 => 60, 0 => 60]);

        $this->evaluate($sa);
        $this->evaluate($sb);

        $incidentes = Incident::withoutAccountScope()->get();
        $this->assertCount(1, $incidentes);
        $this->assertSame($a->id, $incidentes->first()->account_id);
    }

    public function test_a1_tela_incidentes_de_a_nao_ve_incidente_de_b(): void
    {
        $a = Account::create(['name' => 'Empresa A']);
        $b = Account::create(['name' => 'Empresa B']);
        $ownerA = User::create(['name' => 'Dono A', 'email' => 'a@x.local', 'password' => Hash::make('senha-forte-123')]);
        $ownerA->accounts()->attach($a->id, ['role' => 'owner']);
        $sa = $this->servidor($a->id, 'srv-a');
        $sb = $this->servidor($b->id, 'srv-b');

        Incident::withoutAccountScope()->create(['account_id' => $a->id, 'server_id' => $sa->id, 'metric' => 'cpu', 'level' => 'critical', 'status' => 'firing', 'open_key' => Incident::openKey($sa->id, 'cpu'), 'started_at' => now()]);
        Incident::withoutAccountScope()->create(['account_id' => $b->id, 'server_id' => $sb->id, 'metric' => 'ram', 'level' => 'critical', 'status' => 'firing', 'open_key' => Incident::openKey($sb->id, 'ram'), 'started_at' => now()]);

        app(AccountContext::class)->set($a->id);
        $this->actingAs($ownerA);

        Livewire::test(Incidentes::class)
            ->assertSee('srv-a')
            ->assertDontSee('srv-b'); // incidente da outra empresa nunca aparece
    }

    // ===== A2 — watchdog pela hora de RECEBIMENTO ================================

    public function test_a2_timestamp_mentiroso_no_payload_nao_move_last_seen(): void
    {
        $account = Account::create(['name' => 'A']);
        $server = $this->servidor($account->id, 'srv', ['last_seen_at' => null]);
        $token = app(AgentToken::class)->issue($server);

        // Agente afirma ts absurdo (ano passado). O app deve gravar a hora de RECEBIMENTO.
        $this->postJson(route('webhook.servers.ingest'), [
            'ts' => now()->subYear()->getTimestamp(),
            'cpu_pct' => 10, 'mem' => ['pct' => 10],
            'disks' => [['mount' => '/', 'pct' => 20]],
        ], ['X-Agent-Token' => $token])->assertOk();

        $lastSeen = $server->fresh()->last_seen_at;
        $this->assertNotNull($lastSeen);
        $this->assertLessThan(5, abs($lastSeen->diffInSeconds(now()))); // ~agora, nao ano passado
    }

    public function test_a2_watchdog_le_recebimento_nao_o_ts_do_agente(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        // Servidor mudo ha 400s (recebimento), mesmo que amostras no buffer
        // "afirmem" ts recente — o watchdog olha last_seen_at (recebimento).
        $server = $this->servidor($account->id, 'srv', ['last_seen_at' => now()->subSeconds(400)]);
        $this->cpu($server->id, [0 => 10]); // amostra "fresca" no buffer nao muda o veredito

        $this->evaluate($server);

        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('watchdog', $inc->metric);
        $this->assertSame('critical', $inc->level);
    }

    // ===== A3 — for-duration em SEGUNDOS, robusto a cadencia/amostra perdida =====

    public function test_a3_uma_amostra_perdida_e_tolerada(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        // critical_for_s = 120. Amostras a cada 30s, FALTANDO a de 90s (gap 60s
        // entre 120 e 60 <= maxGap 75s): continuidade preservada -> dispara.
        $server = $this->servidor($account->id, 'srv');
        $this->cpu($server->id, [150 => 97, 120 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $this->evaluate($server);

        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('cpu', $inc->metric);
        $this->assertSame('critical', $inc->level);
    }

    public function test_a3_duas_amostras_seguidas_perdidas_quebram_a_continuidade(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        // Buraco de 120s (>maxGap 75s) entre a mais recente e a anterior: sem
        // prova de continuidade -> NAO dispara, apesar do span total ser grande.
        $server = $this->servidor($account->id, 'srv');
        $this->cpu($server->id, [150 => 97, 130 => 97, 0 => 97]);

        $this->evaluate($server);

        $this->assertSame(0, Incident::withoutAccountScope()->count());
    }

    public function test_a3_latencia_segue_for_duration_independente_da_cadencia(): void
    {
        // Cadencia menor (15s) NAO muda o veredito: o que importa e o SPAN em
        // segundos cobrir o for_duration, nao a contagem de amostras.
        config()->set('servers.cadence_s', 15);
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        $server = $this->servidor($account->id, 'srv');
        // Amostras a cada 15s cobrindo 135s (>= crit 120s).
        $this->cpu($server->id, [135 => 97, 120 => 97, 105 => 97, 90 => 97, 75 => 97, 60 => 97, 45 => 97, 30 => 97, 15 => 97, 0 => 97]);

        $this->evaluate($server);

        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('critical', $inc->level);
    }

    public function test_a3_pico_dentro_da_janela_nao_dispara(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        // crit 120s: 97 so nos ultimos 90s (span < 120) -> nao dispara.
        $server = $this->servidor($account->id, 'srv');
        $this->cpu($server->id, [90 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $this->evaluate($server);

        $this->assertSame(0, Incident::withoutAccountScope()->count());
    }

    // ===== A4 — debounce SIMETRICO de resolucao (anti-flapping) =================

    private function regraCurta(int $accountId, int $resolveForS): void
    {
        // Regra global de cpu com janelas curtas para o teste de flapping.
        AlertRule::withoutAccountScope()->where('account_id', $accountId)->whereNull('server_id')->where('metric', 'cpu')
            ->update(['warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 60, 'critical_for_s' => 60, 'resolve_for_s' => $resolveForS]);
    }

    public function test_a4_nao_resolve_na_primeira_amostra_boa(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        $this->regraCurta($account->id, 120); // resolve exige 120s limpos
        $server = $this->servidor($account->id, 'srv');
        $buffer = app(MetricsBuffer::class);

        // Abre critical.
        $this->cpu($server->id, [90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate($server);
        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('firing', $inc->status);

        // Janela deslizante na hora da avaliacao: so 60s limpos (< 120s de
        // debounce). forget() modela a janela corrente (o rabo velho ja saiu).
        $buffer->forget($server->id);
        $this->cpu($server->id, [60 => 50, 30 => 50, 0 => 50]);
        $this->evaluate($server);
        $this->assertSame('firing', $inc->fresh()->status);

        // Agora limpo por 150s (>= 120): resolve.
        $buffer->forget($server->id);
        $this->cpu($server->id, [150 => 50, 120 => 50, 90 => 50, 60 => 50, 30 => 50, 0 => 50]);
        $this->evaluate($server);
        $this->assertSame('resolved', $inc->fresh()->status);
    }

    public function test_a4_resolve_for_s_e_configuravel(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        $this->regraCurta($account->id, 30); // debounce curto: resolve rapido
        $server = $this->servidor($account->id, 'srv');
        $buffer = app(MetricsBuffer::class);

        $this->cpu($server->id, [90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate($server);
        $inc = Incident::withoutAccountScope()->sole();

        // Limpo por 45s (>= resolve_for_s 30): resolve (com o default 300 nao resolveria).
        $buffer->forget($server->id);
        $this->cpu($server->id, [45 => 50, 15 => 50, 0 => 50]);
        $this->evaluate($server);
        $this->assertSame('resolved', $inc->fresh()->status);
    }

    // ===== A5 — ack NAO engole escalada =========================================

    public function test_a5_ack_em_warning_e_escalada_para_critical_notifica(): void
    {
        $account = Account::create(['name' => 'A']);
        AlertRuleDefaults::ensureFor($account->id);
        $server = $this->servidor($account->id, 'srv');
        $dono = User::create(['name' => 'Dono', 'email' => 'dono@x.local', 'password' => Hash::make('x')]);
        $dono->accounts()->attach($account->id, ['role' => 'owner']);

        // Abre warning (cpu 90 por 330s).
        $this->cpu($server->id, [330 => 90, 300 => 90, 240 => 90, 180 => 90, 120 => 90, 60 => 90, 0 => 90]);
        $this->evaluate($server);
        $inc = Incident::withoutAccountScope()->sole();
        $this->assertSame('warning', $inc->level);

        // Dono reconhece o WARNING.
        app(IncidentManager::class)->acknowledge($inc, $dono->id);
        $this->assertSame('acknowledged', $inc->fresh()->status);

        // Severidade sobe para critical: FURA o ack.
        $this->cpu($server->id, [140 => 97, 100 => 97, 60 => 97, 20 => 97, 0 => 97]);
        $this->evaluate($server);

        $inc->refresh();
        $this->assertSame('critical', $inc->level);
        $this->assertSame('firing', $inc->status);       // des-reconhecido
        $this->assertNull($inc->acknowledged_at);
        $this->assertNull($inc->acknowledged_by);
        // A escalada gerou notificacao (SystemEvent no modo silencioso).
        $this->assertNotNull(SystemEvent::withoutAccountScope()->where('ref', 'srv-incident:'.$inc->id.':escalated')->first());
    }

    // ===== A6 — poda/retencao coerente ==========================================

    public function test_a6_retencao_do_buffer_cobre_o_maior_for_duration_permitido(): void
    {
        // A cobertura do buffer (amostras x cadencia) tem de conter o teto de
        // for_duration + margem — senao uma regra valida nunca dispararia.
        $maxFor = (int) config('servers.max_for_duration_s', 600);
        $this->assertGreaterThanOrEqual($maxFor, MetricsBuffer::coverageSeconds());
    }

    public function test_a6_buffer_tem_teto_de_amostras_e_ttl(): void
    {
        $account = Account::create(['name' => 'A']);
        $server = $this->servidor($account->id, 'srv');
        $buffer = app(MetricsBuffer::class);

        foreach (range(1, MetricsBuffer::MAX_SAMPLES + 20) as $i) {
            $buffer->push($server->id, ['received_at' => now()->getTimestamp() - $i, 'cpu_pct' => 1.0]);
        }
        $this->assertCount(MetricsBuffer::MAX_SAMPLES, $buffer->samples($server->id));

        $this->travel(MetricsBuffer::TTL_SECONDS + 60)->seconds();
        $this->assertSame([], $buffer->samples($server->id));
    }
}
