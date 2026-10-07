<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edi_dumps', function (Blueprint $table) {
            $table->decimal('total_valor', 18, 2)->nullable()->after('total_linhas');
            $table->unsignedInteger('linhas_unicas')->nullable()->after('total_valor');
        });

        Schema::table('edi_dump_linhas', function (Blueprint $table) {
            $table->string('codigo_transacao', 64)->nullable()->after('movimento_api_codigo');
            $table->string('tx_id', 128)->nullable()->after('codigo_transacao');
        });
    }

    public function down(): void
    {
        Schema::table('edi_dump_linhas', function (Blueprint $table) {
            $table->dropColumn(['codigo_transacao', 'tx_id']);
        });

        Schema::table('edi_dumps', function (Blueprint $table) {
            $table->dropColumn(['total_valor', 'linhas_unicas']);
        });
    }
};
