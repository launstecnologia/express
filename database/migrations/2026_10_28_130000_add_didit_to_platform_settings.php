<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->boolean('didit_ativo')->default(true)->after('kyc_ativo');
            $table->text('didit_api_key')->nullable()->after('didit_ativo');
            $table->string('didit_api_url')->nullable()->after('didit_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['didit_ativo', 'didit_api_key', 'didit_api_url']);
        });
    }
};
