<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Services\ConciliacaoConfrontoService;
use App\Services\ConciliacaoImportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConciliacaoController extends Controller
{
    public function index()
    {
        $conciliacoes = Conciliacao::query()
            ->with('importadoPor')
            ->orderByDesc('referencia_mes')
            ->paginate(20);

        return view('admin.conciliacoes.index', compact('conciliacoes'));
    }

    public function create()
    {
        return view('admin.conciliacoes.create');
    }

    public function store(Request $request, ConciliacaoImportService $import)
    {
        $dados = $request->validate([
            'arquivo' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ]);

        try {
            $conciliacao = $import->importarArquivo(
                $request->file('arquivo'),
                $request->user()?->id,
                $request->boolean('confrontar', true),
            );
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['arquivo' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.conciliacoes.show', $conciliacao)
            ->with('status', 'Conciliação importada com sucesso.');
    }

    public function show(Request $request, Conciliacao $conciliacao, ConciliacaoConfrontoService $confronto)
    {
        $filtros = $request->only(['status', 'id_cliente', 'busca']);

        $linhas = ConciliacaoLinha::query()
            ->with('estabelecimento:id,nome_fantasia,razao_social,nome_completo,token_pagseguro')
            ->where('conciliacao_id', $conciliacao->id)
            ->when(filled($filtros['status'] ?? null), fn ($q) => $q->where('status', $filtros['status']))
            ->when(filled($filtros['id_cliente'] ?? null), fn ($q) => $q->where('id_cliente', $filtros['id_cliente']))
            ->when(filled($filtros['busca'] ?? null), function ($q) use ($filtros) {
                $busca = '%'.$filtros['busca'].'%';
                $q->where(function ($sub) use ($busca) {
                    $sub->where('id_cliente', 'like', $busca)
                        ->orWhere('chave', 'like', $busca)
                        ->orWhere('bandeira', 'like', $busca);
                });
            })
            ->orderBy('status')
            ->orderByDesc('tpv')
            ->paginate(50)
            ->withQueryString();

        $resumo = $confronto->resumoMensal($conciliacao);
        $resumoEstabelecimentos = $confronto->resumoEstabelecimentos($conciliacao);

        return view('admin.conciliacoes.show', compact(
            'conciliacao',
            'linhas',
            'resumo',
            'resumoEstabelecimentos',
            'filtros',
        ));
    }

    public function relatorioSemEstabelecimento(Conciliacao $conciliacao, ConciliacaoConfrontoService $confronto): StreamedResponse
    {
        $clientes = $confronto->clientesSemEstabelecimento($conciliacao);
        $mes = $conciliacao->referencia_mes?->format('Y-m') ?? 'conciliacao';
        $nomeArquivo = "clientes-sem-estabelecimento-{$mes}.csv";

        return response()->streamDownload(function () use ($clientes) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['id_cliente', 'linhas', 'tpv', 'comissao'], ';');

            foreach ($clientes as $cliente) {
                fputcsv($handle, [
                    $cliente->id_cliente,
                    $cliente->linhas,
                    number_format((float) $cliente->tpv, 2, '.', ''),
                    number_format((float) $cliente->comissao, 4, '.', ''),
                ], ';');
            }

            fclose($handle);
        }, $nomeArquivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function confrontar(Conciliacao $conciliacao, ConciliacaoConfrontoService $confronto)
    {
        $confronto->confrontar($conciliacao);

        return redirect()
            ->route('admin.conciliacoes.show', $conciliacao)
            ->with('status', 'Confronto com EDI atualizado.');
    }

    public function destroy(Conciliacao $conciliacao)
    {
        $conciliacao->delete();

        return redirect()
            ->route('admin.conciliacoes.index')
            ->with('status', 'Conciliação removida.');
    }
}
