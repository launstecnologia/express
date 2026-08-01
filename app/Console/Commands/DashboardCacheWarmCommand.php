<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\DashboardService;
use Illuminate\Console\Command;

/**
 * Recalcula e regrava o cache do dashboard (cards + apuração) em background,
 * antes que o TTL de 5 minutos expire.
 */
class DashboardCacheWarmCommand extends Command
{
    protected $signature = 'dashboard:warm-cache';

    protected $description = 'Aquece o cache do dashboard (resumo + apuração) para os usuários admin';

    public function handle(DashboardService $dashboardService): int
    {
        $admins = Usuario::query()->where('tipo', 'admin')->get();

        if ($admins->isEmpty()) {
            $this->warn('Nenhum usuário admin encontrado — nada para aquecer.');

            return self::SUCCESS;
        }

        $inicio = microtime(true);

        foreach ($admins as $admin) {
            $dashboardService->aquecerCacheAdmin($admin);
        }

        $duracao = round(microtime(true) - $inicio, 2);

        $this->info("Cache do dashboard aquecido para {$admins->count()} admin(s) em {$duracao}s.");

        return self::SUCCESS;
    }
}
