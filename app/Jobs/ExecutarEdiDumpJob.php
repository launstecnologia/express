<?php

namespace App\Jobs;

use App\Models\EdiDump;
use App\Services\EdiDumpService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExecutarEdiDumpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 21600;

    public function __construct(public int $dumpId) {}

    public function handle(EdiDumpService $dumpService): void
    {
        $dump = EdiDump::query()->find($this->dumpId);

        if (! $dump) {
            return;
        }

        $dumpService->executar($dump);
    }

    public function failed(\Throwable $exception): void
    {
        EdiDump::query()->whereKey($this->dumpId)->update([
            'status' => 'erro',
            'erro' => mb_substr($exception->getMessage(), 0, 2000),
            'finalizado_em' => now(),
        ]);

        Log::error('EDI dump falhou', [
            'dump_id' => $this->dumpId,
            'erro' => $exception->getMessage(),
        ]);
    }
}
