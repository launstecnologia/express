<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConsultaCnpjTransacoesService;
use App\Support\DocumentoBrasil;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConsultaCnpjTransacoesController extends Controller
{
    public function index(Request $request, ConsultaCnpjTransacoesService $consulta)
    {
        $filtros = $this->filtros($request);
        $parceiros = collect();
        $estabelecimentos = collect();
        $origemEstabelecimentos = collect();
        $transacoes = null;
        $totais = null;
        $consultou = filled($filtros['cnpj']);

        if ($consultou) {
            $request->validate([
                'cnpj' => ['required', 'string', 'max:18'],
                'mes_numero' => ['nullable', 'integer', 'between:1,12'],
                'ano' => ['nullable', 'integer', 'between:2020,2100'],
                'rede' => ['nullable'],
            ]);

            $digitos = DocumentoBrasil::apenasDigitos($filtros['cnpj']);
            if (! in_array(strlen($digitos), [11, 14], true)) {
                return redirect()
                    ->route('admin.relatorios.consulta-cnpj')
                    ->withInput()
                    ->withErrors(['cnpj' => 'Informe um CNPJ (14 dígitos) ou CPF (11 dígitos) válido.']);
            }

            $resultado = $consulta->resolverConsulta($filtros['cnpj'], $filtros['rede']);
            $parceiros = $resultado['parceiros'];
            $origemEstabelecimentos = $resultado['origem_estabelecimentos'];
            $estabelecimentos = $resultado['estabelecimentos'];

            if ($estabelecimentos->isNotEmpty()) {
                $query = $consulta->movimentosQuery($estabelecimentos, $filtros['inicio'], $filtros['fim']);
                $totais = $consulta->totais($query);
                $transacoes = $consulta->paginar($query);
            }
        }

        return view('admin.relatorios.consulta-cnpj', [
            'filtros' => $filtros,
            'consultou' => $consultou,
            'parceiros' => $parceiros,
            'origemEstabelecimentos' => $origemEstabelecimentos,
            'estabelecimentos' => $estabelecimentos,
            'transacoes' => $transacoes,
            'totais' => $totais,
            'consulta' => $consulta,
        ]);
    }

    public function excel(Request $request, ConsultaCnpjTransacoesService $consulta): Response
    {
        $validado = $request->validate([
            'cnpj' => ['required', 'string', 'max:18'],
            'mes_numero' => ['required', 'integer', 'between:1,12'],
            'ano' => ['required', 'integer', 'between:2020,2100'],
            'rede' => ['nullable'],
        ]);

        $digitos = DocumentoBrasil::apenasDigitos($validado['cnpj']);
        abort_unless(in_array(strlen($digitos), [11, 14], true), 422, 'Informe um CNPJ ou CPF válido.');

        $incluirRede = $request->boolean('rede');
        $resultado = $consulta->resolverConsulta($validado['cnpj'], $incluirRede);
        $estabelecimentos = $resultado['estabelecimentos'];
        abort_if($estabelecimentos->isEmpty(), 404, 'Nenhum estabelecimento encontrado com esse documento.');

        $mes = Carbon::create((int) $validado['ano'], (int) $validado['mes_numero'], 1)->startOfMonth();
        $caminho = $consulta->gerarExcel(
            $estabelecimentos,
            $mes->toDateString(),
            $mes->copy()->endOfMonth()->toDateString(),
        );

        return response()->download($caminho, $consulta->nomeArquivo($estabelecimentos, $mes, $validado['cnpj'], $incluirRede), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{cnpj: string, mes_numero: int, ano: int, inicio: string, fim: string, rede: bool}
     */
    private function filtros(Request $request): array
    {
        $ano = filled($request->input('ano')) ? (int) $request->input('ano') : (int) now()->format('Y');
        $mesNumero = filled($request->input('mes_numero')) ? (int) $request->input('mes_numero') : (int) now()->format('n');
        $mes = Carbon::create($ano, $mesNumero, 1)->startOfMonth();

        return [
            'cnpj' => trim((string) $request->input('cnpj')),
            'mes_numero' => (int) $mes->format('n'),
            'ano' => (int) $mes->format('Y'),
            'inicio' => $mes->toDateString(),
            'fim' => $mes->copy()->endOfMonth()->toDateString(),
            'rede' => $request->boolean('rede'),
        ];
    }
}
