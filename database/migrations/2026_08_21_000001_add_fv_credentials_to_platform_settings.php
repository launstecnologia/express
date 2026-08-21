<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->string('automacao_fv_usuario', 120)->nullable()->after('pagbank_edi_user_producao');
            $table->text('automacao_fv_senha')->nullable()->after('automacao_fv_usuario');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn([
                'automacao_fv_usuario',
                'automacao_fv_senha',
            ]);
        });
    }
};
