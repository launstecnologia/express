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
        $this->comporMeses($request);
        $filtros = $this->filtros($request);
        $linhas = collect();
        $resumo = null;
        $consultou = $request->boolean('consultar');

        $avisos = [];

        $meses = [];

        if ($consultou) {
            $this->validar($request);
            $meses = $relatorio->mesesDoIntervalo($filtros['mes_base'], $filtros['mes_comparado']);
            $mesInicio = $meses[0];
            $mesFim = $meses[array_key_last($meses)];
            $avisos = $this->avisosPlanilha($relatorio, $meses);

            if ($avisos === []) {
                $todas = $relatorio->consultar(
                    $filtros['marketplace_id'],
                    $meses,
                    $mesInicio,
                    $mesFim,
                    $filtros['fonte'],
                );
                $resumo = $relatorio->resumo($todas, $meses);
                $linhas = $relatorio->filtrar($todas, $filtros['situacao']);
            }
        }

        return view('admin.relatorios.uso-comparacao', [
            'marketplaces' => $this->marketplaces(),
            'filtros' => $filtros,
            'meses' => $meses,
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
        $this->comporMeses($request);
        $validado = $this->validar($request);
        $filtros = $this->filtros($request);
        $meses = $relatorio->mesesDoIntervalo($validado['mes_base'], $validado['mes_comparado']);
        $avisos = $this->avisosPlanilha($relatorio, $meses);

        if ($avisos !== []) {
            return back()->withErrors(['fonte' => implode(' ', $avisos)]);
        }

        $linhas = $relatorio->filtrar(
            $relatorio->consultar(
                $filtros['marketplace_id'],
                $meses,
                $meses[0],
                $meses[array_key_last($meses)],
                $filtros['fonte'],
            ),
            $filtros['situacao'],
        );

        return response($relatorio->gerarXlsx($linhas, $meses), 200, [
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
     * @param  list<string>  $meses
     * @return list<string>
     */
    private function avisosPlanilha(EstabelecimentoUsoComparacaoService $relatorio, array $meses): array
    {
        if (request()->input('fonte') !== 'planilha') {
            return [];
        }

        $avisos = [];

        foreach ($meses as $mes) {
            if (! $relatorio->temPlanilha($mes)) {
                $avisos[] = 'Não há planilha PagBank importada para '.$relatorio->rotuloMes($mes).'.';
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
            'mes_base.date_format' => 'Escolha um mês e um ano válidos.',
            'mes_comparado.date_format' => 'Escolha um mês e um ano válidos.',
        ]);

        $quantidade = count(app(EstabelecimentoUsoComparacaoService::class)->mesesDoIntervalo(
            $validado['mes_base'],
            $validado['mes_comparado'],
        ));

        if ($quantidade > 12) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'mes_comparado' => 'O intervalo pode ter no máximo 12 meses.',
            ]);
        }

        return [
            'marketplace_id' => filled($validado['marketplace_id'] ?? null) ? (int) $validado['marketplace_id'] : null,
            'mes_base' => $validado['mes_base'],
            'mes_comparado' => $validado['mes_comparado'],
            'situacao' => (string) ($validado['situacao'] ?? 'mudancas'),
        ];
    }

    private function comporMeses(Request $request): void
    {
        foreach (['mes_base', 'mes_comparado'] as $campo) {
            $mes = $request->input($campo.'_mes');
            $ano = $request->input($campo.'_ano');

            if (! filled($mes) || ! filled($ano)) {
                continue;
            }

            $request->merge([
                $campo => sprintf('%04d-%02d', (int) $ano, (int) $mes),
            ]);
        }
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
