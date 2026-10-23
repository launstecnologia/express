<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimento_aceites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->cascadeOnDelete();
            $table->foreignId('plano_id')->nullable()->constrained('planos')->nullOnDelete();
            $table->string('plano_nome')->nullable();
            $table->string('nome_responsavel', 150);
            $table->string('cpf_responsavel', 11);
            $table->string('condicao_representacao', 40);
            $table->text('declaracao');
            $table->json('resumo');
            $table->json('evidencias');
            $table->string('assinatura_path');
            $table->string('assinatura_sha256', 64);
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('fuso_horario', 80)->nullable();
            $table->string('data_hora_cliente', 40);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('precisao_metros', 8, 2)->nullable();
            $table->string('hash_confirmacao', 64)->unique();
            $table->json('payload_hash');
            $table->timestamp('aceite_em');
            $table->timestamps();

            $table->unique('estabelecimento_id');
        });

        if (! Schema::hasTable('email_templates')) {
            return;
        }

        $template = DB::table('email_templates')->where('slug', 'estabelecimento.cadastro')->first();

        if (! $template) {
            return;
        }

        $corpoAntigo = "Olá {nome},\n\nSeu cadastro na plataforma {app_name} foi recebido com sucesso.\n\nEstabelecimento: {estabelecimento}\nDocumento: {documento}\n\nEm breve nossa equipe dará continuidade ao processo de análise (KYC).";
        $corpoNovo = "Olá {nome},\n\nSeu cadastro na plataforma {app_name} foi recebido.\n\nEstabelecimento: {estabelecimento}\nDocumento: {documento}\n\nPara concluir, envie os documentos e assine o aceite das taxas, inclusive as condições de chargeback, neste link:\n\n{link_documentos}\n\nO link abre no celular. Nele você fotografa os documentos, confere as taxas e assina.";

        $atualizacao = [
            'placeholders_ajuda' => '{nome}, {estabelecimento}, {documento}, {link}, {link_documentos}, {app_name}',
            'updated_at' => now(),
        ];

        if ($template->corpo === $corpoAntigo) {
            $atualizacao['corpo'] = $corpoNovo;
        }

        if ($template->assunto === 'Cadastro recebido — {app_name}') {
            $atualizacao['assunto'] = 'Documentos e aceite das taxas — {app_name}';
        }

        if ($template->botao_texto === 'Acompanhar cadastro') {
            $atualizacao['botao_texto'] = 'Enviar documentos e assinar';
        }

        DB::table('email_templates')->where('id', $template->id)->update($atualizacao);
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_aceites');

        if (! Schema::hasTable('email_templates')) {
            return;
        }

        $corpoNovo = "Olá {nome},\n\nSeu cadastro na plataforma {app_name} foi recebido.\n\nEstabelecimento: {estabelecimento}\nDocumento: {documento}\n\nPara concluir, envie os documentos e assine o aceite das taxas, inclusive as condições de chargeback, neste link:\n\n{link_documentos}\n\nO link abre no celular. Nele você fotografa os documentos, confere as taxas e assina.";
        $corpoAntigo = "Olá {nome},\n\nSeu cadastro na plataforma {app_name} foi recebido com sucesso.\n\nEstabelecimento: {estabelecimento}\nDocumento: {documento}\n\nEm breve nossa equipe dará continuidade ao processo de análise (KYC).";

        $template = DB::table('email_templates')->where('slug', 'estabelecimento.cadastro')->first();

        if (! $template || $template->corpo !== $corpoNovo) {
            return;
        }

        DB::table('email_templates')->where('id', $template->id)->update([
            'assunto' => 'Cadastro recebido — {app_name}',
            'corpo' => $corpoAntigo,
            'botao_texto' => 'Acompanhar cadastro',
            'placeholders_ajuda' => '{nome}, {estabelecimento}, {documento}, {link}, {app_name}',
            'updated_at' => now(),
        ]);
    }
};
