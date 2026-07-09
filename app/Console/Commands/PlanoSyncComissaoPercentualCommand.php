<?php

namespace App\Console\Commands;

use App\Models\PlanoTaxa;
use Illuminate\Console\Command;

class PlanoSyncComissaoPercentualCommand extends Command
{
    protected $signature = 'plano:sync-comissao-percentual
                            {--dry-run : Apenas mostra o que seria atualizado}';

    protected $description = 'Sincroniza comissao_percentual e ativo nas plano_taxas a partir da grade salva';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $atualizadas = 0;
        $ativadas = 0;

        PlanoTaxa::query()
            ->with(['royalties' => fn ($q) => $q->where('nivel', 'admin')])
            ->chunkById(200, function ($taxas) use ($dryRun, &$atualizadas, &$ativadas) {
                foreach ($taxas as $taxa) {
                    $comissaoRoyalty = $taxa->royalties->first()?->percentual;
                    $updates = [];

                    if ($taxa->comissao_percentual === null && $comissaoRoyalty !== null) {
                        $updates['comissao_percentual'] = $comissaoRoyalty;
                    }

                    $temComissao = ($updates['comissao_percentual'] ?? $taxa->comissao_percentual) !== null
                        || $comissaoRoyalty !== null;

                    if (! $taxa->ativo && ((float) $taxa->taxa_percentual > 0 || $temComissao)) {
                        $updates['ativo'] = true;
                    }

                    if ($updates === []) {
                        continue;
                    }

                    if (isset($updates['comissao_percentual'])) {
                        $atualizadas++;
                    }

                    if (isset($updates['ativo'])) {
                        $ativadas++;
                    }

                    $this->line(sprintf(
                        'Taxa #%d plano #%d parcelas %dx %s → %s',
                        $taxa->id,
                        $taxa->plano_id,
                        $taxa->parcelas,
                        $taxa->arranjo_ur,
                        json_encode($updates, JSON_UNESCAPED_UNICODE),
                    ));

                    if (! $dryRun) {
                        $taxa->update($updates);
                    }
                }
            });

        $this->info("Comissão sincronizada em {$atualizadas} taxa(s); ativadas {$ativadas} taxa(s).".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
