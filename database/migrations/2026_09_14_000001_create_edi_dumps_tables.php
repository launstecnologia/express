<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edi_dumps', function (Blueprint $table) {
            $table->id();
            $table->date('competencia')->index();
            $table->enum('status', ['pendente', 'processando', 'concluido', 'erro'])->default('pendente')->index();
            $table->unsignedInteger('total_dias')->default(0);
            $table->unsignedInteger('dias_ok')->default(0);
            $table->unsignedInteger('dias_nao_validados')->default(0);
            $table->unsignedInteger('dias_erro')->default(0);
            $table->unsignedInteger('total_paginas')->default(0);
            $table->unsignedInteger('total_itens_api')->default(0);
            $table->unsignedInteger('total_linhas')->default(0);
            $table->foreignId('iniciado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('iniciado_por_nome', 200)->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('iniciado_em')->nullable();
            $table->timestamp('finalizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('edi_dump_dias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dump_id')->constrained('edi_dumps')->cascadeOnDelete();
            $table->date('data');
            $table->enum('status', ['pendente', 'ok', 'vazio', 'nao_validado', 'erro'])->default('pendente')->index();
            $table->unsignedInteger('paginas')->default(0);
            $table->unsignedInteger('total_itens_api')->default(0);
            $table->unsignedInteger('linhas')->default(0);
            $table->string('motivo', 255)->nullable();
            $table->timestamps();

            $table->unique(['dump_id', 'data']);
        });

        Schema::create('edi_dump_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dump_id')->constrained('edi_dumps')->cascadeOnDelete();
            $table->foreignId('dia_id')->nullable()->constrained('edi_dump_dias')->nullOnDelete();
            $table->date('data_referencia')->index();
            $table->unsignedInteger('pagina')->default(1);
            $table->string('estabelecimento', 64)->nullable()->index();
            $table->unsignedBigInteger('estabelecimento_id')->nullable()->index();
            $table->string('movimento_api_codigo', 64)->nullable();
            $table->date('data_inicial_transacao')->nullable();
            $table->string('tipo_transacao', 32)->nullable();
            $table->string('status_pagamento', 32)->nullable();
            $table->decimal('valor_total_transacao', 18, 2)->nullable();
            $table->decimal('valor_liquido_transacao', 18, 2)->nullable();
            $table->string('nsu', 64)->nullable();
            $table->timestamps();

            $table->index(['dump_id', 'estabelecimento_id']);
            $table->index(['dump_id', 'estabelecimento']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edi_dump_linhas');
        Schema::dropIfExists('edi_dump_dias');
        Schema::dropIfExists('edi_dumps');
    }
};
