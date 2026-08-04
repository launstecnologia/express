<?php

namespace App\Console\Commands;

use App\Services\DirectAdminService;
use App\Services\EdiPipefySolicitacaoService;
use Illuminate\Console\Command;

class EdiPipefySolicitarCommand extends Command
{
    protected $signature = 'edi:pipefy-solicitar
                            {--preview : Apenas lista IDs pendentes, sem abrir chamado}
                            {--force : Inclui IDs já enviados com sucesso no período}
                            {--criar-email : Garante a caixa edi@express.app.br no DirectAdmin}';

    protected $description = 'Abre chamado semanal no Pipefy EDI com Safepay IDs pendentes (desde a data de corte)';

    public function handle(EdiPipefySolicitacaoService $service, DirectAdminService $da): int
    {
        if ($this->option('criar-email')) {
            try {
                $resultado = $service->garantirEmailDevolutiva($da);
                $this->info($resultado['mensagem']);
                $this->line('E-mail: '.$resultado['email']);
                if (filled($resultado['senha'] ?? null)) {
                    $this->warn('Senha gerada (guarde agora): '.$resultado['senha']);
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('preview')) {
            $preview = $service->previewPendentes();
            $this->info("Pendentes desde {$preview['desde']}: {$preview['total']}");
            foreach ($preview['ids'] as $id) {
                $this->line($id);
            }

            return self::SUCCESS;
        }

        try {
            $solicitacao = $service->iniciar(null, (bool) $this->option('force'));
        } catch (\Throwable $e) {
            // Agenda semanal: zero pendentes não é falha operacional
            if (str_contains($e->getMessage(), 'Nenhum Safepay ID pendente')) {
                $this->info($e->getMessage());

                return self::SUCCESS;
            }

            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Solicitação #{$solicitacao->id} enfileirada com {$solicitacao->total_ids} ID(s).");
        $this->line('Acompanhe em Admin → EDI Pipefy.');

        return self::SUCCESS;
    }
}
