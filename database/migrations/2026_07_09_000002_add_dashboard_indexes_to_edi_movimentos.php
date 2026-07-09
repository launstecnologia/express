<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edi_movimentos', function (Blueprint $table) {
            $table->index(['data_inicial_transacao', 'estabelecimento_id'], 'edi_movimentos_data_estab_idx');
        });

        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->index(['plano_id', 'id'], 'estabelecimentos_plano_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('edi_movimentos', function (Blueprint $table) {
            $table->dropIndex('edi_movimentos_data_estab_idx');
        });

        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->dropIndex('estabelecimentos_plano_id_idx');
        });
    }
};
