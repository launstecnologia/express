<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\EstabelecimentoTransacaoRelatorioService;
use Illuminate\Http\Request;

class EstabelecimentoTransacaoRelatorioController extends Controller
{
    public function index(Request $request, EstabelecimentoTransacaoRelatorioService $relatorio)
    {
        $marketplaces = $this->marketplaces();
        $filtros = $this->filtros($request);

        $marketplace = null;
        $rows = collect();
        $resumo = null;

        if ($filtros['marketplace_id']) {
            $marketplace = Usuario::query()
                ->where('tipo', 'marketplace')
                ->find($filtros['marketplace_id']);

            if ($marketplace) {
                $rows = $relatorio->filtrar(
                    $relatorio->consultar($marketplace->id, $filtros['de'], $filtros['ate']),
                    $filtros['filtro'],
                );
                $resumo = $relatorio->resumo($rows);
            }
        }

        return view('admin.relatorios.estabelecimentos-transacoes', [
            'marketplaces' => $marketplaces,
            'filtros' => $filtros,
            'marketplace' => $marketplace,
            'rows' => $rows,
            'resumo' => $resumo,
            'relatorio' => $relatorio,
        ]);
    }

    public function excel(Request $request, EstabelecimentoTransacaoRelatorioService $relatorio)
    {
        $validado = $request->validate([
            'marketplace_id' => ['required', 'integer', 'exists:usuarios,id'],
            'de' => ['required', 'date'],
            'ate' => ['required', 'date', 'after_or_equal:de'],
            'filtro' => ['nullable', 'in:todos,com,sem'],
        ]);

        $marketplace = Usuario::query()
            ->where('tipo', 'marketplace')
            ->findOrFail((int) $validado['marketplace_id']);

        $de = now()->parse($validado['de'])->toDateString();
        $ate = now()->parse($validado['ate'])->toDateString();
        $filtro = $validado['filtro'] ?? 'todos';

        $rows = $relatorio->filtrar(
            $relatorio->consultar($marketplace->id, $de, $ate),
            $filtro === 'todos' ? null : $filtro,
        );

        $binario = $relatorio->gerarXlsx($marketplace, $rows, $de, $ate);
        $nome = $relatorio->nomeArquivo($marketplace, $de, $ate);

        return response($binario, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$nome.'"',
        ]);
    }

    /**
     * @return array{marketplace_id: ?int, de: string, ate: string, filtro: string}
     */
    private function filtros(Request $request): array
    {
        $ate = filled($request->input('ate'))
            ? now()->parse((string) $request->input('ate'))->toDateString()
            : now()->toDateString();

        $de = filled($request->input('de'))
            ? now()->parse((string) $request->input('de'))->toDateString()
            : now()->subDays(29)->toDateString();

        return [
            'marketplace_id' => filled($request->input('marketplace_id'))
                ? (int) $request->input('marketplace_id')
                : null,
            'de' => $de,
            'ate' => $ate,
            'filtro' => in_array($request->input('filtro'), ['com', 'sem'], true)
                ? (string) $request->input('filtro')
                : 'todos',
        ];
    }

    private function marketplaces()
    {
        return Usuario::query()
            ->where('tipo', 'marketplace')
            ->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')
            ->get()
            ->map(fn (Usuario $usuario) => [
                'id' => $usuario->id,
                'nome' => '#'.$usuario->id.' — '.$usuario->nomeExibicao(),
            ]);
    }
}
