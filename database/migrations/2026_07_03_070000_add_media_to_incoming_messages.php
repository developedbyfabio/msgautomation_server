<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt 13 — midia recebida, Fatia 2. ADITIVA: guarda o binario baixado da
 * midia RECEBIDA (imagem cheia / audio) no disco privado, escopado por conta.
 *  - media_path   : caminho no disk('local') (media/incoming/{conta}/{numero}/{uuid}.ext)
 *  - media_mime   : content-type real do binario baixado
 *  - media_name   : nome original quando o provedor informa (Evolution documentMessage etc.)
 *  - media_status : null=nao tentado · stored · failed · unsupported (visibilidade + idempotencia)
 * O raw_payload (com jpegThumbnail) continua valendo — nada e removido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incoming_messages', function (Blueprint $table) {
            $table->string('media_path')->nullable()->after('text');
            $table->string('media_mime', 128)->nullable()->after('media_path');
            $table->string('media_name')->nullable()->after('media_mime');
            $table->string('media_status', 16)->nullable()->after('media_name');
        });
    }

    public function down(): void
    {
        Schema::table('incoming_messages', function (Blueprint $table) {
            $table->dropColumn(['media_path', 'media_mime', 'media_name', 'media_status']);
        });
    }
};
