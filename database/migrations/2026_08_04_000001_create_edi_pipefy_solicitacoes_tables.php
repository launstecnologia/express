<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('edi_pipefy_solicitacoes')) {
            Schema::create('edi_pipefy_solicitacoes', function (Blueprint $table) {
                $table->id();
                $table->string('status', 30)->default('pendente')->index();
                $table->string('tipo', 60)->default('replicacao_token');
                $table->string('email_devolutiva', 255);
                $table->string('id_origem', 64);
                $table->unsignedInteger('total_ids')->default(0);
                $table->string('automacao_job_id', 36)->nullable()->index();
                $table->string('pipefy_card_id', 64)->nullable();
                $table->text('descricao')->nullable();
                $table->text('erro')->nullable();
                $table->json('resultado')->nullable();
                $table->json('screenshots')->nullable();
                $table->foreignId('solicitado_por_id')->nullable()->constrained('usuarios')->nullOnDelete();
                $table->timestamp('disparado_em')->nullable();
                $table->timestamp('concluido_em')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('edi_pipefy_solicitacao_itens')) {
            Schema::create('edi_pipefy_solicitacao_itens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('solicitacao_id')->constrained('edi_pipefy_solicitacoes')->cascadeOnDelete();
                $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
                $table->string('token_pagseguro', 64)->index();
                $table->timestamps();

                $table->unique(['solicitacao_id', 'token_pagseguro'], 'edi_pipefy_item_unico');
                $table->index(['token_pagseguro', 'solicitacao_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('edi_pipefy_solicitacao_itens');
        Schema::dropIfExists('edi_pipefy_solicitacoes');
    }
};
