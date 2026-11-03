<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            if (! Schema::hasColumn('estabelecimentos', 'aceite_dispensado_em')) {
                $table->timestamp('aceite_dispensado_em')->nullable();
            }
            if (! Schema::hasColumn('estabelecimentos', 'aceite_dispensado_por')) {
                $table->unsignedBigInteger('aceite_dispensado_por')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $colunas = array_values(array_filter(
                ['aceite_dispensado_por', 'aceite_dispensado_em'],
                fn (string $coluna) => Schema::hasColumn('estabelecimentos', $coluna),
            ));

            if ($colunas !== []) {
                $table->dropColumn($colunas);
            }
        });
    }
};
