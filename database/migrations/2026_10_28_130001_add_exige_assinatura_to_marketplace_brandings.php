<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_brandings', function (Blueprint $table) {
            $table->boolean('exige_assinatura')->default(true)->after('whitelabel_ativo');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_brandings', function (Blueprint $table) {
            $table->dropColumn('exige_assinatura');
        });
    }
};
