<?php

use App\Jobs\AgregarFaturamentoJob;
use App\Jobs\BuscarEdiPagBankJob;
use App\Jobs\CalcularRoyaltiesJob;
use App\Jobs\RenovarTokenPagBankJob;
use App\Jobs\SincronizarEmailsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new RenovarTokenPagBankJob)->dailyAt('04:00');

foreach (['00:00', '06:00', '12:00', '18:00'] as $hora) {
    Schedule::job(new BuscarEdiPagBankJob)
        ->dailyAt($hora)
        ->name("buscar-edi-pagbank-{$hora}")
        ->withoutOverlapping()
        ->onOneServer();
}

Schedule::job(new CalcularRoyaltiesJob)->everyFifteenMinutes();
Schedule::job(new AgregarFaturamentoJob)->dailyAt('02:00');
Schedule::job(new SincronizarEmailsJob)->everyFiveMinutes();

// Mantém o cache do dashboard sempre quente (TTL é 5min) para que o admin
// nunca pague o custo da agregação em edi_movimentos ao abrir a tela.
Schedule::command('dashboard:warm-cache')
    ->everyFourMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
