<?php

namespace App\Console\Commands;

use App\Services\ConciliacaoImportService;
use Illuminate\Console\Command;

class ConciliacaoImportCommand extends Command
{
    protected $signature = 'conciliacao:import
                            {arquivo : Caminho do XLSX da PagSeguro}
                            {--sem-confronto : Apenas importa, sem confrontar com EDI}';

    protected $description = 'Importa relatório PagSeguro (aba Validação V2) para conciliação de comissão';

    public function handle(ConciliacaoImportService $import): int
    {
        $arquivo = $this->argument('arquivo');

        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        try {
            $conciliacao = $import->importarArquivo(
                $arquivo,
                null,
                ! $this->option('sem-confronto'),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Conciliação importada: '.$conciliacao->referenciaFormatada());
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Linhas', $conciliacao->total_linhas],
                ['Clientes', $conciliacao->total_clientes],
                ['TPV', 'R$ '.number_format((float) $conciliacao->total_tpv, 2, ',', '.')],
                ['Comissão', 'R$ '.number_format((float) $conciliacao->total_comissao, 2, ',', '.')],
                ['Sem estabelecimento', $conciliacao->linhas_sem_estabelecimento],
                ['Status', $conciliacao->status],
            ],
        );

        return self::SUCCESS;
    }
}
