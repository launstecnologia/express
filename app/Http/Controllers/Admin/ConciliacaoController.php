<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ConfrontarConciliacaoJob;
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
        $request->validate([
            'arquivo' => ['required', 'file', 'mimes:xlsx', 'max:20480'],
        ]);

        $deveConfrontar = $request->boolean('confrontar', true);

        try {
            // Importa sem confronto para não perder o arquivo se o EDI estourar tempo/memória.
            $conciliacao = $import->importarArquivo(
                $request->file('arquivo'),
                $request->user()?->id,
                false,
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->withErrors([
                'arquivo' => 'Falha ao importar: '.$e->getMessage(),
            ]);
        }

        if (! $deveConfrontar) {
            return redirect()
                ->route('admin.conciliacoes.show', $conciliacao)
                ->with('status', 'Conciliação importada. Use “Reconfrontar EDI” quando quiser confrontar.');
        }

        try {
            $this->enfileirarConfronto($conciliacao);
        } catch (\Throwable $e) {
            report($e);

            $conciliacao->update([
                'status' => 'erro',
                'confronto_status' => 'erro',
                'confronto_erro' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return redirect()
                ->route('admin.conciliacoes.show', $conciliacao)
                ->withErrors(['confronto' => 'A importação foi salva, mas não foi possível adicionar o confronto à fila.']);
        }

        return redirect()
            ->route('admin.conciliacoes.show', $conciliacao)
            ->with('status', 'Conciliação importada. O confronto com EDI entrou na fila e continuará em segundo plano.');
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
        $resumoSoEdi = $confronto->resumoSoEdi($conciliacao);

        return view('admin.conciliacoes.show', compact(
            'conciliacao',
            'linhas',
            'resumo',
            'resumoEstabelecimentos',
            'resumoSoEdi',
            'filtros',
        ));
    }

    public function diferenca(Conciliacao $conciliacao, ConciliacaoConfrontoService $confronto)
    {
        $resumo = $confronto->resumoMensal($conciliacao);
        $semCadastro = $confronto->clientesSemEstabelecimento($conciliacao);
        $semEdi = $confronto->estabelecimentosSemEdi($conciliacao);
        $inversoEdi = $confronto->recorteInversoEdi($conciliacao);
        $soEdi = $inversoEdi['so_edi'];
        $extraEdi = $inversoEdi['extra_edi'];

        return view('admin.conciliacoes.diferenca', compact(
            'conciliacao',
            'resumo',
            'semCadastro',
            'semEdi',
            'soEdi',
            'extraEdi',
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

    public function relatorioSemEdi(Conciliacao $conciliacao, ConciliacaoConfrontoService $confronto): StreamedResponse
    {
        $clientes = $confronto->estabelecimentosSemEdi($conciliacao);
        $mes = $conciliacao->referencia_mes?->format('Y-m') ?? 'conciliacao';
        $nomeArquivo = "estabelecimentos-sem-edi-{$mes}.csv";

        return response()->streamDownload(function () use ($clientes) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, ['id_cliente', 'estabelecimento', 'linhas', 'tpv', 'comissao'], ';');

            foreach ($clientes as $cliente) {
                $estab = $cliente->estabelecimento;
                $nome = $estab?->nome_fantasia
                    ?: $estab?->razao_social
                    ?: $estab?->nome_completo
                    ?: '';

                fputcsv($handle, [
                    $cliente->id_cliente,
                    $nome,
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

    public function relatorioSoEdi(Conciliacao $conciliacao, ConciliacaoConfrontoService $confronto): StreamedResponse
    {
        $clientes = $confronto->recorteInversoEdi($conciliacao)['so_edi'];
        $mes = $conciliacao->referencia_mes?->format('Y-m') ?? 'conciliacao';
        $nomeArquivo = "estabelecimentos-so-edi-{$mes}.csv";

        return response()->streamDownload(function () use ($clientes) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
                    fputcsv($handle, ['id_cliente', 'estabelecimento', 'linhas', 'vendas', 'tpv', 'comissao'], ';');

            foreach ($clientes as $cliente) {
                $estab = $cliente->estabelecimento;
                $nome = $estab?->nome_fantasia
                    ?: $estab?->razao_social
                    ?: $estab?->nome_completo
                    ?: '';

                fputcsv($handle, [
                    $cliente->id_cliente,
                    $nome,
                    $cliente->linhas,
                    $cliente->vendas,
                    number_format((float) $cliente->tpv, 2, '.', ''),
                    number_format((float) $cliente->comissao, 4, '.', ''),
                ], ';');
            }

            fclose($handle);
        }, $nomeArquivo, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function confrontar(Conciliacao $conciliacao)
    {
        if (in_array($conciliacao->confronto_status, ['na_fila', 'processando'], true)) {
            return redirect()
                ->route('admin.conciliacoes.show', $conciliacao)
                ->with('status', 'O confronto desta conciliação já está na fila ou em processamento.');
        }

        try {
            $this->enfileirarConfronto($conciliacao);
        } catch (\Throwable $e) {
            report($e);

            $conciliacao->update([
                'status' => 'erro',
                'confronto_status' => 'erro',
                'confronto_erro' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            return redirect()
                ->route('admin.conciliacoes.show', $conciliacao)
                ->withErrors(['confronto' => 'Não foi possível adicionar o confronto à fila.']);
        }

        return redirect()
            ->route('admin.conciliacoes.show', $conciliacao)
            ->with('status', 'Atualização enfileirada: os estabelecimentos cadastrados depois da importação serão vinculados e o EDI confrontado de novo. Você pode sair desta página.');
    }

    public function destroy(Conciliacao $conciliacao)
    {
        $conciliacao->delete();

        return redirect()
            ->route('admin.conciliacoes.index')
            ->with('status', 'Conciliação removida.');
    }

    private function enfileirarConfronto(Conciliacao $conciliacao): void
    {
        $conciliacao->update([
            'status' => 'importado',
            'confronto_status' => 'na_fila',
            'confronto_erro' => null,
            'confronto_iniciado_em' => null,
        ]);

        ConfrontarConciliacaoJob::dispatch($conciliacao->id)->onQueue('conciliacao');
    }
}
