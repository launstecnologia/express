<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\EstabelecimentoUsoComparacaoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstabelecimentoUsoComparacaoController extends Controller
{
    public function index(Request $request, EstabelecimentoUsoComparacaoService $relatorio)
    {
        $filtros = $this->filtros($request);
        $linhas = collect();
        $resumo = null;
        $consultou = $request->boolean('consultar');

        $avisos = [];

        if ($consultou) {
            $this->validar($request);
            $avisos = $this->avisosPlanilha($relatorio, $filtros);

            if ($avisos === []) {
                $todas = $relatorio->consultar(
                    $filtros['marketplace_id'],
                    $filtros['mes_base'],
                    $filtros['mes_comparado'],
                    $filtros['fonte'],
                );
                $resumo = $relatorio->resumo($todas);
                $linhas = $relatorio->filtrar($todas, $filtros['situacao']);
            }
        }

        return view('admin.relatorios.uso-comparacao', [
            'marketplaces' => $this->marketplaces(),
            'filtros' => $filtros,
            'linhas' => $linhas,
            'resumo' => $resumo,
            'consultou' => $consultou,
            'avisos' => $avisos,
            'relatorio' => $relatorio,
            'situacoes' => EstabelecimentoUsoComparacaoService::SITUACOES,
        ]);
    }

    public function excel(Request $request, EstabelecimentoUsoComparacaoService $relatorio)
    {
        $validado = $this->validar($request);
        $filtros = $this->filtros($request);
        $avisos = $this->avisosPlanilha($relatorio, $filtros);

        if ($avisos !== []) {
            return back()->withErrors(['fonte' => implode(' ', $avisos)]);
        }

        $linhas = $relatorio->filtrar(
            $relatorio->consultar($filtros['marketplace_id'], $validado['mes_base'], $validado['mes_comparado'], $filtros['fonte']),
            $filtros['situacao'],
        );

        return response($relatorio->gerarXlsx($linhas, $validado['mes_base'], $validado['mes_comparado']), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$relatorio->nomeArquivo($validado['mes_base'], $validado['mes_comparado'], $filtros['marketplace_id'], $filtros['fonte']).'"',
        ]);
    }

    /**
     * @return array{marketplace_id: ?int, mes_base: string, mes_comparado: string, situacao: string, fonte: string}
     */
    private function filtros(Request $request): array
    {
        $situacao = (string) $request->input('situacao', 'mudancas');
        $fonte = $request->input('fonte') === 'planilha' ? 'planilha' : 'edi';

        return [
            'marketplace_id' => filled($request->input('marketplace_id'))
                ? (int) $request->input('marketplace_id')
                : null,
            'mes_base' => filled($request->input('mes_base'))
                ? (string) $request->input('mes_base')
                : now()->subMonthsNoOverflow(2)->format('Y-m'),
            'mes_comparado' => filled($request->input('mes_comparado'))
                ? (string) $request->input('mes_comparado')
                : now()->subMonthsNoOverflow(1)->format('Y-m'),
            'situacao' => $situacao === 'mudancas' || array_key_exists($situacao, EstabelecimentoUsoComparacaoService::SITUACOES)
                ? $situacao
                : 'mudancas',
            'fonte' => $fonte,
        ];
    }

    /**
     * @param  array{fonte: string, mes_base: string, mes_comparado: string}  $filtros
     * @return list<string>
     */
    private function avisosPlanilha(EstabelecimentoUsoComparacaoService $relatorio, array $filtros): array
    {
        if ($filtros['fonte'] !== 'planilha') {
            return [];
        }

        $avisos = [];

        foreach (['mes_base' => 'de referência', 'mes_comparado' => 'comparado'] as $chave => $rotulo) {
            if (! $relatorio->temPlanilha($filtros[$chave])) {
                $avisos[] = 'Não há planilha PagBank importada para o mês '.$rotulo.' ('.$relatorio->rotuloMes($filtros[$chave]).').';
            }
        }

        return $avisos;
    }

    /**
     * @return array{marketplace_id: ?int, mes_base: string, mes_comparado: string, situacao: string}
     */
    private function validar(Request $request): array
    {
        $validado = $request->validate([
            'marketplace_id' => ['nullable', 'integer', Rule::exists('usuarios', 'id')->where('tipo', 'marketplace')],
            'mes_base' => ['required', 'date_format:Y-m'],
            'mes_comparado' => ['required', 'date_format:Y-m', 'different:mes_base'],
            'situacao' => ['nullable', 'string'],
        ], [
            'mes_comparado.different' => 'Escolha dois meses diferentes para comparar.',
        ]);

        return [
            'marketplace_id' => filled($validado['marketplace_id'] ?? null) ? (int) $validado['marketplace_id'] : null,
            'mes_base' => $validado['mes_base'],
            'mes_comparado' => $validado['mes_comparado'],
            'situacao' => (string) ($validado['situacao'] ?? 'mudancas'),
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
