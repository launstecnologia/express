<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Services\ConciliacaoConfrontoService;
use App\Services\ConciliacaoImportService;
use Illuminate\Http\Request;

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

        $clientesSemCadastro = ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->where('sem_estabelecimento', true)
            ->distinct('id_cliente')
            ->count('id_cliente');

        return view('admin.conciliacoes.show', compact(
            'conciliacao',
            'linhas',
            'resumo',
            'filtros',
            'clientesSemCadastro',
        ));
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
