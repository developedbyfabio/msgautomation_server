<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P-4 — rodape de saida OBRIGATORIO em toda proativa (conformidade LGPD +
 * anti-ban: quem sabe sair manda a palavra; quem nao sabe, denuncia).
 *  - settings.proactive_optout_footer: rodape PADRAO da conta (seed com
 *    {palavra_sair} — resolve pro valor atual da palavra NO ENVIO);
 *  - proactive_campaigns.optout_footer: rodape POR campanha (congela no
 *    snapshot como template; a variavel resolve no envio).
 * Backfill ADITIVO: campanhas existentes ganham o default (historico coerente,
 * comportamento futuro; nada e reescrito alem da coluna nova).
 */
return new class extends Migration
{
    private const FOOTER_DEFAULT = 'Para nao receber mais mensagens assim, responda {palavra_sair}.';

    public function up(): void
    {
        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->string('proactive_optout_footer', 500)
                ->default(self::FOOTER_DEFAULT)
                ->after('proactive_optout_word');
        });

        Schema::table('proactive_campaigns', function (Blueprint $table) {
            $table->text('optout_footer')->nullable()->after('message');
        });

        DB::table('proactive_campaigns')->whereNull('optout_footer')
            ->update(['optout_footer' => self::FOOTER_DEFAULT]);
    }

    public function down(): void
    {
        Schema::table('proactive_campaigns', function (Blueprint $table) {
            $table->dropColumn('optout_footer');
        });

        Schema::table('auto_reply_settings', function (Blueprint $table) {
            $table->dropColumn('proactive_optout_footer');
        });
    }
};
