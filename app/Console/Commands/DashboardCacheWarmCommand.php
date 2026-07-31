<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\DashboardApuracaoService;
use App\Services\DashboardResumoService;
use Illuminate\Console\Command;

/**
 * Recalcula e regrava o cache do dashboard (cards de resumo + apuração de
 * transações) em background, antes que o TTL de 5 minutos expire.
 *
 * Sem isso, cada visita ao dashboard fora dessa janela de 5 min paga o custo
 * cheio das agregações em `edi_movimentos` na hora do request (~10-30s).
 * Rodando esse comando a cada 4 minutos via scheduler, o usuário sempre
 * encontra o cache quente.
 */
class DashboardCacheWarmCommand extends Command
{
    protected $signature = 'dashboard:warm-cache';

    protected $description = 'Aquece o cache do dashboard (resumo + apuração) para os usuários admin';

    private const PERIODOS = [7, 30, 90];

    public function handle(DashboardApuracaoService $apuracaoService, DashboardResumoService $resumoService): int
    {
        $admins = Usuario::query()->where('tipo', 'admin')->get();

        if ($admins->isEmpty()) {
            $this->warn('Nenhum usuário admin encontrado — nada para aquecer.');

            return self::SUCCESS;
        }

        $inicio = microtime(true);

        foreach ($admins as $admin) {
            $resumoService->resumo($admin);

            foreach (self::PERIODOS as $dias) {
                $apuracaoService->apurar($dias, $admin);
            }
        }

        $duracao = round(microtime(true) - $inicio, 2);

        $this->info("Cache do dashboard aquecido para {$admins->count()} admin(s) em {$duracao}s.");

        return self::SUCCESS;
    }
}
