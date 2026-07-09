<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_brandings', function (Blueprint $table) {
            $table->boolean('logo_oculta')->default(false)->after('logo_white_path');
            $table->boolean('logo_white_oculta')->default(false)->after('logo_oculta');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_brandings', function (Blueprint $table) {
            $table->dropColumn(['logo_oculta', 'logo_white_oculta']);
        });
    }
};
