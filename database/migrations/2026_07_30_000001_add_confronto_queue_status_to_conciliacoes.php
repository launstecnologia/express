<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conciliacoes', function (Blueprint $table) {
            $table->string('confronto_status', 30)
                ->default('nao_solicitado')
                ->after('status')
                ->index();
            $table->text('confronto_erro')->nullable()->after('confronto_status');
            $table->timestamp('confronto_iniciado_em')->nullable()->after('confronto_erro');
        });

        DB::table('conciliacoes')
            ->where('status', 'confrontado')
            ->update(['confronto_status' => 'concluido']);

        DB::table('conciliacoes')
            ->where('status', 'erro')
            ->update(['confronto_status' => 'erro']);
    }

    public function down(): void
    {
        Schema::table('conciliacoes', function (Blueprint $table) {
            $table->dropIndex(['confronto_status']);
            $table->dropColumn([
                'confronto_status',
                'confronto_erro',
                'confronto_iniciado_em',
            ]);
        });
    }
};
