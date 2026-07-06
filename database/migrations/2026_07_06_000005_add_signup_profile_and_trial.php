<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fatia 25 — cadastro publico PF/PJ + trial de 7 dias. ADITIVA:
 *
 *  accounts: perfil PF/PJ (document UNICO = anti-abuso: 1 CPF/CNPJ = 1 conta;
 *  nullable — contas legadas/criadas pelo admin ficam sem perfil) + estado de
 *  assinatura (default 'active': as contas EXISTENTES e as criadas pelo admin
 *  seguem operando como hoje, SEM trial; so o cadastro publico grava 'trial'
 *  + trial_ends_at). O CORTE no fim do trial NAO existe aqui — Fatia 26.
 *
 *  users: consentimento LGPD (data + versao dos termos; so o cadastro publico
 *  preenche) e backfill de email_verified_at: usuarios pre-existentes foram
 *  criados por caminho PRIVILEGIADO (console/admin) — vouched por construcao;
 *  sem o backfill, o gate 'verified' novo trancaria os usuarios do Fabio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('person_type', 2)->nullable()->after('name'); // 'pf' | 'pj'
            $table->string('document', 14)->nullable()->unique()->after('person_type'); // CPF/CNPJ, so digitos
            $table->string('razao_social', 190)->nullable()->after('document'); // PJ
            $table->string('phone', 20)->nullable()->after('razao_social');
            $table->string('cep', 8)->nullable()->after('phone');
            $table->string('endereco', 190)->nullable()->after('cep');
            $table->string('numero', 20)->nullable()->after('endereco');
            $table->string('complemento', 100)->nullable()->after('numero');
            $table->string('bairro', 100)->nullable()->after('complemento');
            $table->string('cidade', 100)->nullable()->after('bairro');
            $table->string('uf', 2)->nullable()->after('cidade');
            $table->string('subscription_status', 20)->default('active')->after('uf');
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('is_platform_admin');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
        });

        // Backfill (so preenche NULL; nada e sobrescrito): pre-fatia-25 todo
        // usuario nasceu por console/admin — verificado por construcao.
        DB::table('users')->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Remove SO o que esta migration adicionou.
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'person_type', 'document', 'razao_social', 'phone', 'cep', 'endereco',
                'numero', 'complemento', 'bairro', 'cidade', 'uf',
                'subscription_status', 'trial_ends_at',
            ]);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
