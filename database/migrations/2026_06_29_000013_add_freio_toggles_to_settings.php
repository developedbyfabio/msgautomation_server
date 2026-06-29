<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S2 — liga/desliga por freio-throttle global. Aditivo: cada toggle entra com
 * default TRUE (preserva o comportamento atual). Desligado = aquele freio nao
 * bloqueia. skip_groups e warmup_enabled JA sao booleans (seus proprios toggles).
 * fromMe e idempotencia NAO ganham toggle (guardas estruturais, sempre ativos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->boolean('window_enabled')->default(true)->after('window_end');
            $table->boolean('min_interval_enabled')->default(true)->after('min_interval_seconds');
            $table->boolean('per_minute_enabled')->default(true)->after('per_minute_cap');
            $table->boolean('per_day_enabled')->default(true)->after('per_day_cap');
            $table->boolean('contact_rate_enabled')->default(true)->after('contact_rate_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropColumn(['window_enabled', 'min_interval_enabled', 'per_minute_enabled', 'per_day_enabled', 'contact_rate_enabled']);
        });
    }
};
