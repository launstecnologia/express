<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edi_movimentos', function (Blueprint $table) {
            $table->decimal('comissao_percentual', 5, 2)->nullable()->after('tarifa_intermediacao');
            $table->decimal('comissao_valor', 14, 4)->nullable()->after('comissao_percentual');
        });
    }

    public function down(): void
    {
        Schema::table('edi_movimentos', function (Blueprint $table) {
            $table->dropColumn(['comissao_percentual', 'comissao_valor']);
        });
    }
};
