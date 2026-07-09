<?php

namespace App\Jobs;

use App\Services\EdiProcessadorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuscarEdiPagBankJob implements ShouldQueue
{
    use Queueable;

    /**
     * Janela deslizante (em dias) buscada a cada execução agendada (4x/dia).
     *
     * O EDI do PagBank costuma ser validado com atraso (D+1/D+2). Buscar apenas
     * D-1 deixa furos permanentes quando o arquivo do dia ainda não está validado.
     * Como a importação é idempotente (upsert por movimento_api_codigo), reprocessar
     * uma janela curta captura dias validados com atraso sem duplicar dados.
     */
    public function __construct(public int $diasJanela = 3) {}

    public function handle(EdiProcessadorService $service): void
    {
        $janela = max(1, $this->diasJanela);

        for ($i = 1; $i <= $janela; $i++) {
            $service->buscarEdiPorData(now()->subDays($i));
        }
    }
}
