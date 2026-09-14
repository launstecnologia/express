<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExecutarEdiDumpJob;
use App\Models\EdiDump;
use App\Services\EdiDumpService;
use App\Support\PlatformSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EdiDumpController extends Controller
{
    public function index()
    {
        $dumps = EdiDump::query()
            ->latest('id')
            ->limit(30)
            ->get();

        return view('admin.edi-dump.index', [
            'dumps' => $dumps,
            'ediConfigurado' => PlatformSettings::ediConfigurado(),
            'mesNumero' => (int) now()->format('n'),
            'ano' => (int) now()->format('Y'),
        ]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'mes_numero' => ['required', 'integer', 'between:1,12'],
            'ano' => ['required', 'integer', 'between:2020,2100'],
        ]);

        if (! PlatformSettings::ediConfigurado()) {
            return back()->withErrors(['mes_numero' => 'Credenciais EDI não configuradas.']);
        }

        $emAndamento = EdiDump::query()
            ->whereIn('status', ['pendente', 'processando'])
            ->exists();

        if ($emAndamento) {
            return back()->withErrors(['mes_numero' => 'Já existe um dump em andamento. Espere terminar.']);
        }

        $competencia = Carbon::create((int) $dados['ano'], (int) $dados['mes_numero'], 1)->startOfMonth();
        $dias = $competencia->daysInMonth;

        $dump = EdiDump::query()->create([
            'competencia' => $competencia->toDateString(),
            'status' => 'pendente',
            'total_dias' => $dias,
            'iniciado_por_id' => $request->user()->id,
            'iniciado_por_nome' => $request->user()->nomeExibicao(),
        ]);

        ExecutarEdiDumpJob::dispatch($dump->id)->onQueue('default');

        return redirect()
            ->route('admin.edi-dump.show', $dump)
            ->with('status', "Dump #{$dump->id} enfileirado para {$competencia->translatedFormat('F/Y')} ({$dias} dias).");
    }

    public function show(Request $request, EdiDump $dump, EdiDumpService $service)
    {
        $dump->load(['dias' => fn ($q) => $q->orderBy('data')]);

        $id = trim((string) $request->input('id'));
        $soma = null;

        if ($id !== '') {
            $soma = $service->somarPorId($dump, $id);
        }

        return view('admin.edi-dump.show', [
            'dump' => $dump,
            'idBusca' => $id,
            'soma' => $soma,
        ]);
    }
}
