<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servidores S2 — regras de alerta + incidentes. ADITIVA (duas tabelas novas
 * + seed dos padroes; nada existente e tocado). Nomes prefixados com server_
 * (nao "alert_rules"/"incidents" genericos): evita colisao com dominio futuro
 * do SaaS — ajuste registrado no relatorio.
 *
 * server_alert_rules — limiar por METRICA (cpu|ram|swap|disk|load|watchdog),
 * escopo conta; server_id NULL = padrao GLOBAL da conta, preenchido = sobre-
 * escrita daquele servidor (precedencia: especifica > global, INCLUSIVE
 * enabled=false — sobrescrita desligada SILENCIA a metrica no servidor).
 * for_duration por NIVEL (warning_for_s/critical_for_s — os defaults do dono
 * pedem duracoes diferentes por nivel, ex.: CPU warn 5min / crit 2min).
 * Unicidade (conta, servidor, metrica) e garantida na APLICACAO: o unique de
 * MySQL nao cobre linhas com server_id NULL (NULL != NULL) — registrado.
 *
 * server_incidents — estado DURAVEL do incidente (MySQL e a fonte de verdade;
 * flush do Redis NAO ressuscita resolvido nem reabre aberto). open_key
 * ("{server}:{metric}[:{mount}]") e UNIQUE enquanto aberto e vira NULL no
 * resolve (MySQL permite N nulls) — garante NO BANCO "um incidente ativo por
 * (servidor, metrica/particao)" e da idempotencia a avaliacao (mesmo padrao
 * do event_id unique do billing). notified_* marcam a acao de notificacao por
 * transicao (na S2, o registro SILENCIOSO; na S3, o envio real).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained('servers')->cascadeOnDelete();
            $table->string('metric', 20); // cpu|ram|swap|disk|load|watchdog
            $table->decimal('warning_threshold', 10, 2)->nullable(); // % (cpu/ram/swap/disk), load1/nucleo, segundos (watchdog)
            $table->decimal('critical_threshold', 10, 2);
            $table->unsignedInteger('warning_for_s')->default(300);  // histerese por nivel
            $table->unsignedInteger('critical_for_s')->default(120);
            $table->unsignedInteger('cooldown_s')->default(1800);    // re-notificacao (S3); S2 nao repete
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['account_id', 'server_id', 'metric']);
        });

        Schema::create('server_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained('servers')->cascadeOnDelete();
            $table->foreignId('rule_id')->nullable()->constrained('server_alert_rules')->nullOnDelete();
            $table->string('metric', 20);
            $table->string('mount', 120)->nullable(); // disk: QUAL particao
            $table->string('level', 10);              // warning|critical (pode escalar)
            $table->string('status', 15)->default('firing'); // firing|acknowledged|resolved
            $table->string('open_key', 180)->nullable()->unique(); // setado enquanto aberto; NULL no resolve
            $table->decimal('value_at_fire', 10, 2)->nullable();
            $table->json('detail')->nullable();       // janela observada / gap — diagnostico
            $table->timestamp('started_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('notified_firing_at')->nullable();
            $table->timestamp('notified_resolved_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'started_at']);
            $table->index(['server_id', 'metric']);
        });

        // Seed dos PADROES GLOBAIS (server_id NULL) para as contas existentes —
        // valores da S2 (editaveis na tela Alertas). Insert direto (sem models:
        // migration nao depende de codigo da aplicacao). Contas futuras sao
        // cobertas pelo ensure lazy (AlertRuleDefaults) na tela e no command.
        $agora = now();
        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            foreach (self::DEFAULTS as $regra) {
                DB::table('server_alert_rules')->insert($regra + [
                    'account_id' => $accountId,
                    'server_id' => null,
                    'enabled' => true,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        }
    }

    /** Padroes sensatos (mesma tabela do AlertRuleDefaults — duplicado aqui de proposito: migration congelada no tempo). */
    private const DEFAULTS = [
        ['metric' => 'cpu', 'warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 300, 'critical_for_s' => 120, 'cooldown_s' => 1800],
        ['metric' => 'ram', 'warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 300, 'critical_for_s' => 120, 'cooldown_s' => 1800],
        ['metric' => 'swap', 'warning_threshold' => 25, 'critical_threshold' => 50, 'warning_for_s' => 300, 'critical_for_s' => 300, 'cooldown_s' => 1800],
        ['metric' => 'disk', 'warning_threshold' => 85, 'critical_threshold' => 95, 'warning_for_s' => 60, 'critical_for_s' => 60, 'cooldown_s' => 3600],
        ['metric' => 'load', 'warning_threshold' => 1.5, 'critical_threshold' => 2.5, 'warning_for_s' => 300, 'critical_for_s' => 300, 'cooldown_s' => 1800],
        ['metric' => 'watchdog', 'warning_threshold' => 180, 'critical_threshold' => 300, 'warning_for_s' => 0, 'critical_for_s' => 0, 'cooldown_s' => 1800],
    ];

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::dropIfExists('server_incidents');
        Schema::dropIfExists('server_alert_rules');
    }
};
