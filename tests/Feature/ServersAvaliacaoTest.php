<?php

namespace Tests\Feature;

use App\Console\Commands\ServersEvaluate;
use App\Models\Account;
use App\Models\AutoReplyLog;
use App\Models\SystemEvent;
use App\Servers\AlertRule;
use App\Servers\AlertRuleDefaults;
use App\Servers\Incident;
use App\Servers\MetricsBuffer;
use App\Servers\Server;
use App\Servers\ServerEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Servidores S2 — o coracao da fatia: histerese sobre o buffer efemero,
 * maquina de estado DURAVEL do incidente, watchdog com precedencia,
 * idempotencia do command (lock + rodar 2x) e MODO SILENCIOSO (nenhum
 * WhatsApp: Http::assertNothingSent + zero AutoReplyLog).
 *
 * Defaults em jogo: cpu warn 85/300s crit 95/120s; disk warn 85 crit 95/60s;
 * load (por nucleo) warn 1.5 crit 2.5/300s; watchdog warn 180s crit 300s.
 */
class ServersAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = Account::create(['name' => 'Interna']);
        $this->server = Server::create([
            'account_id' => $this->account->id,
            'name' => 'srv-a',
            'os' => 'linux',
            'last_seen_at' => now(), // fresco: watchdog quieto por padrao
        ]);
        AlertRuleDefaults::ensureFor($this->account->id);
    }

    /** Empurra amostras no buffer: $pontos = [ageSeconds => cpu_pct] (mais antiga primeiro). */
    private function cpuSamples(array $pontos, array $extra = []): void
    {
        $buffer = app(MetricsBuffer::class);
        foreach ($pontos as $age => $cpu) {
            $buffer->push($this->server->id, array_merge([
                'received_at' => now()->getTimestamp() - $age,
                'cpu_pct' => (float) $cpu,
                'mem_pct' => 10.0,
                'swap_pct' => 0.0,
                'load' => [0.1, 0.1, 0.1],
                'cpu_count' => 8,
                'disks' => [['mount' => '/', 'pct' => 20.0]],
            ], $extra));
        }
    }

    private function evaluate(): void
    {
        app(ServerEvaluator::class)->evaluate($this->server->fresh());
    }

    private function incidents(): Collection
    {
        return Incident::withoutAccountScope()->get();
    }

    // ---- histerese / for-duration ------------------------------------------------

    public function test_pico_curto_nao_dispara(): void
    {
        // cpu 97 por so 60s (crit exige 120s; warn exige 300s) -> nada.
        $this->cpuSamples([60 => 97, 30 => 97, 0 => 97]);

        $this->evaluate();

        $this->assertSame(0, $this->incidents()->count());
    }

    public function test_condicao_persistente_abre_critical(): void
    {
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $this->evaluate();

        $inc = $this->incidents()->sole();
        $this->assertSame('cpu', $inc->metric);
        $this->assertSame('critical', $inc->level);
        $this->assertSame(Incident::STATUS_FIRING, $inc->status);
        $this->assertNotNull($inc->open_key);
        $this->assertNotNull($inc->notified_firing_at);

        // Modo silencioso: o "teria notificado" esta nos Logs, nivel error.
        $evento = SystemEvent::withoutAccountScope()->where('ref', 'srv-incident:'.$inc->id.':firing')->first();
        $this->assertNotNull($evento);
        $this->assertSame('error', $evento->level);
        $this->assertStringContainsString('Teria notificado', $evento->title);
    }

    public function test_faixa_warning_abre_warning(): void
    {
        // cpu 90 (>=85, <95) por 330s -> warning (warn exige 300s).
        $this->cpuSamples([330 => 90, 270 => 90, 210 => 90, 150 => 90, 90 => 90, 30 => 90, 0 => 90]);

        $this->evaluate();

        $inc = $this->incidents()->sole();
        $this->assertSame('warning', $inc->level);
    }

    public function test_amostras_insuficientes_nao_disparam(): void
    {
        // 2 amostras violando cobrindo 300s: menos que MIN_SAMPLES=3 -> nada
        // (falso positivo por falta de dado e papel do watchdog, nao da metrica).
        $this->cpuSamples([300 => 99, 0 => 99]);

        $this->evaluate();

        $this->assertSame(0, $this->incidents()->count());
    }

    public function test_buffer_vazio_nao_dispara_metrica(): void
    {
        $this->evaluate();

        $this->assertSame(0, $this->incidents()->count());
    }

    // ---- metricas especificas ------------------------------------------------------

    public function test_disco_avalia_por_particao_e_identifica_o_mount(): void
    {
        $buffer = app(MetricsBuffer::class);
        foreach ([90, 45, 0] as $age) { // disk: for 60s, 3 amostras cobrindo 90s
            $buffer->push($this->server->id, [
                'received_at' => now()->getTimestamp() - $age,
                'cpu_pct' => 5.0,
                'mem_pct' => 10.0,
                'disks' => [
                    ['mount' => '/', 'pct' => 50.0],
                    ['mount' => '/var', 'pct' => 97.0],
                ],
            ]);
        }

        $this->evaluate();

        $inc = $this->incidents()->sole();
        $this->assertSame('disk', $inc->metric);
        $this->assertSame('/var', $inc->mount);
        $this->assertSame('critical', $inc->level);
    }

    public function test_load_e_por_nucleo_quando_ha_cpu_count(): void
    {
        // load1 13 em 8 nucleos = 1.625/nucleo: warning (1.5), nao critical (2.5).
        $this->cpuSamples(
            [330 => 5, 270 => 5, 210 => 5, 150 => 5, 90 => 5, 30 => 5, 0 => 5],
            ['load' => [13.0, 12.0, 11.0], 'cpu_count' => 8],
        );

        $this->evaluate();

        $inc = $this->incidents()->sole();
        $this->assertSame('load', $inc->metric);
        $this->assertSame('warning', $inc->level);
    }

    public function test_load_sem_cpu_count_compara_absoluto(): void
    {
        // Limitacao registrada: sem cpu_count o limiar vira absoluto (2.6 > 2.5).
        $buffer = app(MetricsBuffer::class);
        foreach ([330, 270, 210, 150, 90, 30, 0] as $age) {
            $buffer->push($this->server->id, [
                'received_at' => now()->getTimestamp() - $age,
                'cpu_pct' => 5.0,
                'mem_pct' => 10.0,
                'load' => [2.6, 2.5, 2.4],
                'disks' => [['mount' => '/', 'pct' => 20.0]],
            ]);
        }

        $this->evaluate();

        $inc = $this->incidents()->sole();
        $this->assertSame('load', $inc->metric);
        $this->assertSame('critical', $inc->level);
    }

    // ---- maquina de estado -----------------------------------------------------------

    public function test_um_incidente_ativo_por_servidor_e_metrica(): void
    {
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $this->evaluate();
        $this->evaluate();
        $this->evaluate();

        $this->assertSame(1, $this->incidents()->count());
    }

    public function test_escalada_warning_para_critical_no_mesmo_incidente(): void
    {
        $this->cpuSamples([330 => 90, 270 => 90, 210 => 90, 150 => 90, 90 => 90, 30 => 90, 0 => 90]);
        $this->evaluate();
        $warning = $this->incidents()->sole();
        $this->assertSame('warning', $warning->level);

        // A condicao piora: 97 pelos ultimos 150s (>= crit 120s).
        $this->cpuSamples([140 => 97, 100 => 97, 60 => 97, 20 => 97, 0 => 97]);
        $this->evaluate();

        $this->assertSame(1, $this->incidents()->count()); // NUNCA um segundo
        $inc = $this->incidents()->sole();
        $this->assertSame($warning->id, $inc->id);
        $this->assertSame('critical', $inc->level);
        $this->assertNotNull(SystemEvent::withoutAccountScope()->where('ref', 'srv-incident:'.$inc->id.':escalated')->first());
    }

    public function test_normalizacao_resolve_com_uma_notificacao(): void
    {
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate();
        $inc = $this->incidents()->sole();

        // Normaliza: cpu 10 pelos ultimos 330s (resolve exige limpo por warning_for_s=300).
        $this->cpuSamples([330 => 10, 270 => 10, 210 => 10, 150 => 10, 90 => 10, 30 => 10, 0 => 10]);
        $this->evaluate();

        $inc->refresh();
        $this->assertSame(Incident::STATUS_RESOLVED, $inc->status);
        $this->assertNotNull($inc->resolved_at);
        $this->assertNull($inc->open_key); // libera para incidente futuro
        $this->assertNotNull($inc->notified_resolved_at);

        $evento = SystemEvent::withoutAccountScope()->where('ref', 'srv-incident:'.$inc->id.':resolved')->first();
        $this->assertNotNull($evento);
        $this->assertSame('info', $evento->level);

        // Rodar de novo nao duplica o evento de resolucao (ref unique).
        $this->evaluate();
        $this->assertSame(1, SystemEvent::withoutAccountScope()->where('ref', 'srv-incident:'.$inc->id.':resolved')->count());
    }

    public function test_incidente_sobrevive_a_flush_do_buffer(): void
    {
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate();
        $inc = $this->incidents()->sole();

        Cache::flush(); // buffer efemero some; MySQL e a fonte de verdade

        $this->evaluate();

        $inc->refresh();
        $this->assertSame(Incident::STATUS_FIRING, $inc->status); // intacto: nem fecha nem duplica
        $this->assertSame(1, $this->incidents()->count());
    }

    public function test_resolvido_nao_ressuscita_e_reincidencia_abre_novo(): void
    {
        // Abre e resolve.
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate();
        $this->cpuSamples([330 => 10, 270 => 10, 210 => 10, 150 => 10, 90 => 10, 30 => 10, 0 => 10]);
        $this->evaluate();
        $resolvido = $this->incidents()->sole();
        $this->assertSame(Incident::STATUS_RESOLVED, $resolvido->status);

        Cache::flush();
        $this->evaluate(); // flush nao ressuscita
        $this->assertSame(Incident::STATUS_RESOLVED, $resolvido->fresh()->status);

        // Reincidencia: NOVO incidente (historico preservado, resolvido intocado).
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate();

        $this->assertSame(2, $this->incidents()->count());
        $this->assertSame(Incident::STATUS_RESOLVED, $resolvido->fresh()->status);
        $this->assertSame(Incident::STATUS_FIRING, $this->incidents()->firstWhere('id', '!=', $resolvido->id)->status);
    }

    // ---- watchdog ---------------------------------------------------------------------

    public function test_watchdog_abre_warning_e_escala_critical_pelo_gap(): void
    {
        $this->server->forceFill(['last_seen_at' => now()->subSeconds(240)])->save(); // 240 >= 180
        $this->evaluate();

        $inc = $this->incidents()->sole();
        $this->assertSame('watchdog', $inc->metric);
        $this->assertSame('warning', $inc->level);

        $this->server->forceFill(['last_seen_at' => now()->subSeconds(400)])->save(); // 400 >= 300
        $this->evaluate();

        $this->assertSame(1, $this->incidents()->count());
        $this->assertSame('critical', $inc->fresh()->level);
    }

    public function test_watchdog_tem_precedencia_metricas_nao_avaliam_dado_velho(): void
    {
        // Buffer CHEIO de violacao, mas o servidor esta mudo ha 400s: so watchdog.
        $this->cpuSamples([700 => 97, 650 => 97, 600 => 97, 550 => 97, 500 => 97, 450 => 97, 400 => 97]);
        $this->server->forceFill(['last_seen_at' => now()->subSeconds(400)])->save();

        $this->evaluate();

        $this->assertSame(1, $this->incidents()->count());
        $this->assertSame('watchdog', $this->incidents()->sole()->metric); // NENHUM cpu
    }

    public function test_stale_congela_incidente_de_metrica_aberto(): void
    {
        // CPU firing com servidor saudavel...
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->evaluate();
        $cpu = $this->incidents()->sole();

        // ...depois o servidor emudece: metrica nao fecha nem re-avalia com dado velho.
        $this->server->forceFill(['last_seen_at' => now()->subSeconds(400)])->save();
        $this->evaluate();

        $this->assertSame(Incident::STATUS_FIRING, $cpu->fresh()->status); // congelado
        $this->assertSame(2, $this->incidents()->count()); // cpu + watchdog
    }

    public function test_watchdog_resolve_quando_volta_a_reportar(): void
    {
        $this->server->forceFill(['last_seen_at' => now()->subSeconds(400)])->save();
        $this->evaluate();
        $watchdog = $this->incidents()->sole();

        $this->server->forceFill(['last_seen_at' => now()])->save(); // voltou
        $this->evaluate();

        $this->assertSame(Incident::STATUS_RESOLVED, $watchdog->fresh()->status);
        $this->assertNotNull($watchdog->fresh()->resolved_at);
    }

    public function test_servidor_que_nunca_reportou_nao_dispara_watchdog(): void
    {
        $this->server->forceFill(['last_seen_at' => null])->save();

        $this->evaluate();

        $this->assertSame(0, $this->incidents()->count());
    }

    // ---- precedencia de regras (especifica > global) -------------------------------------

    public function test_sobrescrita_do_servidor_vence_a_global(): void
    {
        // Override do srv-a: cpu critical 50 (global e 95). Outro servidor segue a global.
        AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'cpu', 'warning_threshold' => null, 'critical_threshold' => 50,
            'warning_for_s' => 60, 'critical_for_s' => 60, 'cooldown_s' => 60, 'enabled' => true,
        ]);
        $outro = Server::create(['account_id' => $this->account->id, 'name' => 'srv-b', 'os' => 'linux', 'last_seen_at' => now()]);

        // cpu 60 nos DOIS servidores por 90s.
        $buffer = app(MetricsBuffer::class);
        foreach ([$this->server->id, $outro->id] as $sid) {
            foreach ([90, 45, 0] as $age) {
                $buffer->push($sid, ['received_at' => now()->getTimestamp() - $age, 'cpu_pct' => 60.0, 'mem_pct' => 10.0, 'disks' => [['mount' => '/', 'pct' => 20.0]]]);
            }
        }

        app(ServerEvaluator::class)->evaluate($this->server->fresh());
        app(ServerEvaluator::class)->evaluate($outro->fresh());

        $inc = $this->incidents()->sole(); // SO o srv-a (override 50) dispara
        $this->assertSame($this->server->id, $inc->server_id);
        $this->assertSame('critical', $inc->level);
    }

    public function test_sobrescrita_desligada_silencia_a_metrica_no_servidor(): void
    {
        AlertRule::withoutAccountScope()->create([
            'account_id' => $this->account->id, 'server_id' => $this->server->id,
            'metric' => 'cpu', 'warning_threshold' => 85, 'critical_threshold' => 95,
            'warning_for_s' => 300, 'critical_for_s' => 120, 'cooldown_s' => 1800, 'enabled' => false,
        ]);
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $this->evaluate();

        $this->assertSame(0, $this->incidents()->count()); // NAO cai na global
    }

    // ---- command: idempotencia, lock, servidor desativado ----------------------------------

    public function test_rodar_o_command_duas_vezes_nao_duplica_nada(): void
    {
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $this->artisan('servers:evaluate')->assertSuccessful();
        $incidentes = $this->incidents()->count();
        $eventos = SystemEvent::withoutAccountScope()->where('type', 'servidores')->count();

        $this->artisan('servers:evaluate')->assertSuccessful();

        $this->assertSame($incidentes, $this->incidents()->count());
        $this->assertSame($eventos, SystemEvent::withoutAccountScope()->where('type', 'servidores')->count());
    }

    public function test_lock_impede_execucao_sobreposta(): void
    {
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);

        $lock = Cache::lock(ServersEvaluate::LOCK_KEY, 50);
        $this->assertTrue($lock->get()); // simulando um tick ainda em execucao

        $this->artisan('servers:evaluate')
            ->expectsOutputToContain('pulando')
            ->assertSuccessful();

        $this->assertSame(0, $this->incidents()->count()); // nada avaliado sob lock
        $lock->release();

        $this->artisan('servers:evaluate')->assertSuccessful(); // liberado: avalia
        $this->assertSame(1, $this->incidents()->count());
    }

    public function test_servidor_desativado_nao_e_avaliado(): void
    {
        $this->server->update(['enabled' => false]);
        $this->cpuSamples([150 => 97, 120 => 97, 90 => 97, 60 => 97, 30 => 97, 0 => 97]);
        $this->server->forceFill(['last_seen_at' => now()->subSeconds(999)])->save(); // nem watchdog

        $this->artisan('servers:evaluate')->assertSuccessful();

        $this->assertSame(0, $this->incidents()->count());
    }

    // ---- MODO SILENCIOSO (a garantia da fatia) ----------------------------------------------

    public function test_cem_por_cento_mudo_nenhum_whatsapp_sai(): void
    {
        Http::fake(); // qualquer chamada HTTP (Evolution/Cloud) seria capturada

        $this->assertFalse((bool) config('servers.notifications_enabled')); // flag OFF por default

        // Ciclo completo: firing -> escalated -> resolved + watchdog.
        $this->cpuSamples([330 => 90, 270 => 90, 210 => 90, 150 => 90, 90 => 90, 30 => 90, 0 => 90]);
        $this->artisan('servers:evaluate');
        $this->cpuSamples([140 => 97, 100 => 97, 60 => 97, 20 => 97, 0 => 97]);
        $this->artisan('servers:evaluate');
        $this->cpuSamples([330 => 10, 270 => 10, 210 => 10, 150 => 10, 90 => 10, 30 => 10, 0 => 10]);
        $this->artisan('servers:evaluate');

        Http::assertNothingSent();                       // NENHUM HTTP de envio
        $this->assertSame(0, AutoReplyLog::withoutAccountScope()->count()); // sender nunca tocado

        // E o rastro do "teria notificado" existe para calibracao.
        $this->assertGreaterThanOrEqual(3, SystemEvent::withoutAccountScope()
            ->where('type', 'servidores')->where('title', 'like', '%Teria notificado%')->count());
    }

    public function test_defaults_sao_idempotentes(): void
    {
        AlertRuleDefaults::ensureFor($this->account->id);
        AlertRuleDefaults::ensureFor($this->account->id);

        $this->assertSame(6, AlertRule::withoutAccountScope()->whereNull('server_id')->count());
    }
}
