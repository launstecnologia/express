<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conciliacoes', function (Blueprint $table) {
            $table->id();
            $table->date('referencia_mes')->index();
            $table->date('data_referencia')->nullable();
            $table->string('parceiro', 120)->nullable();
            $table->string('arquivo_nome')->nullable();
            $table->string('arquivo_path')->nullable();
            $table->unsignedInteger('total_linhas')->default(0);
            $table->unsignedInteger('total_clientes')->default(0);
            $table->decimal('total_tpv', 16, 2)->default(0);
            $table->decimal('total_comissao', 14, 4)->default(0);
            $table->decimal('total_chargeback', 14, 4)->default(0);
            $table->enum('status', ['importado', 'confrontado', 'erro'])->default('importado');
            $table->timestamp('confrontado_em')->nullable();
            $table->unsignedInteger('linhas_ok')->default(0);
            $table->unsignedInteger('linhas_divergentes')->default(0);
            $table->unsignedInteger('linhas_sem_estabelecimento')->default(0);
            $table->unsignedInteger('linhas_sem_edi')->default(0);
            $table->foreignId('importado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->timestamps();

            $table->unique('referencia_mes');
        });

        Schema::create('conciliacao_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conciliacao_id')->constrained('conciliacoes')->cascadeOnDelete();
            $table->string('chave', 255)->nullable();
            $table->string('link', 120)->nullable();
            $table->string('id_cliente', 64)->index();
            $table->string('meio_pagamento', 40)->nullable();
            $table->string('parcelamento_agrupado', 40)->nullable();
            $table->string('bandeira', 60)->nullable();
            $table->string('escrow', 20)->nullable();
            $table->string('mcc', 20)->nullable();
            $table->string('solucao', 40)->nullable();
            $table->decimal('tpv', 14, 2)->default(0);
            $table->decimal('ms_comissao', 14, 4)->default(0);
            $table->decimal('ms_chargeback', 14, 4)->default(0);
            $table->decimal('rebate_real', 12, 6)->nullable();
            $table->decimal('rebate_contrato', 12, 6)->nullable();
            $table->boolean('check1_sem_antec')->nullable();
            $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
            $table->boolean('sem_estabelecimento')->default(false);
            $table->string('chave_confronto', 64)->index();
            $table->decimal('edi_tpv', 14, 2)->nullable();
            $table->decimal('edi_comissao', 14, 4)->nullable();
            $table->unsignedInteger('edi_qtd')->nullable();
            $table->decimal('diff_tpv', 14, 2)->nullable();
            $table->decimal('diff_comissao', 14, 4)->nullable();
            $table->enum('status', ['pendente', 'ok', 'divergente', 'sem_estabelecimento', 'sem_edi'])->default('pendente');
            $table->timestamps();

            $table->index(['conciliacao_id', 'status']);
            $table->index(['conciliacao_id', 'id_cliente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conciliacao_linhas');
        Schema::dropIfExists('conciliacoes');
    }
};
