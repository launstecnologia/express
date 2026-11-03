<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimento_aceites', function (Blueprint $table) {
            $table->string('assinatura_path')->nullable()->change();
            $table->string('assinatura_sha256', 64)->nullable()->change();
            $table->decimal('latitude', 10, 7)->nullable()->change();
            $table->decimal('longitude', 10, 7)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('estabelecimento_aceites', function (Blueprint $table) {
            $table->string('assinatura_path')->nullable(false)->change();
            $table->string('assinatura_sha256', 64)->nullable(false)->change();
            $table->decimal('latitude', 10, 7)->nullable(false)->change();
            $table->decimal('longitude', 10, 7)->nullable(false)->change();
        });
    }
};
