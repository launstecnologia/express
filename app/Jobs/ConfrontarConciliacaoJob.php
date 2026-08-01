<?php

namespace App\Jobs;

use App\Models\Conciliacao;
use App\Services\ConciliacaoConfrontoService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConfrontarConciliacaoJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1200;

    public function __construct(public int $conciliacaoId) {}

    public function uniqueId(): string
    {
        return (string) $this->conciliacaoId;
    }

    public function handle(ConciliacaoConfrontoService $confronto): void
    {
        $conciliacao = Conciliacao::query()->find($this->conciliacaoId);

        if (! $conciliacao) {
            return;
        }

        $confronto->confrontar($conciliacao);
    }

    public function failed(?Throwable $exception): void
    {
        Conciliacao::query()
            ->whereKey($this->conciliacaoId)
            ->update([
                'status' => 'erro',
                'confronto_status' => 'erro',
                'confronto_erro' => mb_substr(
                    $exception?->getMessage() ?? 'Falha desconhecida no confronto.',
                    0,
                    2000,
                ),
            ]);
    }
}
