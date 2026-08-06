<?php

namespace App\Console\Commands;

use App\Jobs\CalcularRoyaltiesJob;
use App\Models\EdiMovimento;
use App\Models\Estabelecimento;
use App\Models\TransacaoRoyalty;
use App\Models\Usuario;
use App\Services\RoyaltyCalculadorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RoyaltyReprocessarCommand extends Command
{
    protected $signature = 'royalty:reprocessar
                            {--revenda= : ID da revenda}
                            {--marketplace= : ID do marketplace}
                            {--mes= : Mês Y-m (ex: 2026-08)}
                            {--sync : Processa na hora em vez de enfileirar}';

    protected $description = 'Refixa a cadeia e reprocessa royalties de movimentos (útil quando a comissão da revenda está zerada)';

    public function handle(RoyaltyCalculadorService $royalty): int
    {
        $query = Estabelecimento::withoutGlobalScopes()->whereNotNull('plano_id');

        if ($this->option('revenda')) {
            $query->where('revenda_id', (int) $this->option('revenda'));
        }

        if ($this->option('marketplace')) {
            $query->where('marketplace_id', (int) $this->option('marketplace'));
        }

        $estabelecimentos = $query->with('plano.taxas.royalties')->get();

        if ($estabelecimentos->isEmpty()) {
            $this->warn('Nenhum estabelecimento encontrado com os filtros.');

            return self::FAILURE;
        }

        $this->info("Refixando cadeia em {$estabelecimentos->count()} estabelecimento(s)...");

        foreach ($estabelecimentos as $estabelecimento) {
            $royalty->fixarCadeia($estabelecimento);
        }

        $movQuery = EdiMovimento::withoutGlobalScopes()
            ->whereIn('estabelecimento_id', $estabelecimentos->pluck('id'));

        if ($mes = $this->option('mes')) {
            if (! preg_match('/^\d{4}-\d{2}$/', $mes)) {
                $this->error('Use --mes=YYYY-MM');

                return self::FAILURE;
            }

            $inicio = $mes.'-01';
            $fim = date('Y-m-t', strtotime($inicio));
            $movQuery->whereBetween('data_inicial_transacao', [$inicio, $fim]);
        }

        $ids = $movQuery->pluck('id');

        if ($ids->isEmpty()) {
            $this->warn('Nenhum movimento EDI no período.');

            return self::SUCCESS;
        }

        $this->info("Resetando {$ids->count()} movimento(s) para reprocessar...");

        foreach ($ids->chunk(1000) as $lote) {
            TransacaoRoyalty::query()->whereIn('edi_movimento_id', $lote)->delete();
            EdiMovimento::withoutGlobalScopes()->whereIn('id', $lote)->update(['processado' => false]);
        }

        if ($this->option('sync')) {
            $total = 0;
            do {
                // Movimentos já foram marcados processado=false; processa o lote pendente.
                $n = $royalty->calcularPendentes(500);
                $total += $n;
            } while ($n > 0);

            $this->info("Processados {$total} movimento(s) sincronamente.");
        } else {
            CalcularRoyaltiesJob::dispatch();
            $this->info('Job de royalties enfileirado.');
        }

        if ($revendaId = $this->option('revenda')) {
            $revenda = Usuario::query()->find($revendaId);
            $soma = (float) DB::table('transacao_royalties')
                ->where('usuario_id', $revendaId)
                ->sum('valor_royalty');
            $this->line('Revenda: '.($revenda?->nomeExibicao() ?? $revendaId));
            $this->line('Total royalties atuais: R$ '.number_format($soma, 2, ',', '.'));
        }

        return self::SUCCESS;
    }
}
