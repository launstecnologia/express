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
        $estabelecimentos = collect();
        $transacoes = null;
        $totais = null;
        $consultou = filled($filtros['cnpj']);

        if ($consultou) {
            $request->validate([
                'cnpj' => ['required', 'string', 'max:18'],
                'mes_numero' => ['nullable', 'integer', 'between:1,12'],
                'ano' => ['nullable', 'integer', 'between:2020,2100'],
            ]);

            $digitos = DocumentoBrasil::apenasDigitos($filtros['cnpj']);
            if (! in_array(strlen($digitos), [11, 14], true)) {
                return redirect()
                    ->route('admin.relatorios.consulta-cnpj')
                    ->withInput()
                    ->withErrors(['cnpj' => 'Informe um CNPJ (14 dígitos) ou CPF (11 dígitos) válido.']);
            }

            $estabelecimentos = $consulta->buscarEstabelecimentos($filtros['cnpj']);

            if ($estabelecimentos->isNotEmpty()) {
                $query = $consulta->movimentosQuery($estabelecimentos, $filtros['inicio'], $filtros['fim']);
                $totais = $consulta->totais($query);
                $transacoes = $consulta->paginar($query);
            }
        }

        return view('admin.relatorios.consulta-cnpj', [
            'filtros' => $filtros,
            'consultou' => $consultou,
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
        ]);

        $digitos = DocumentoBrasil::apenasDigitos($validado['cnpj']);
        abort_unless(in_array(strlen($digitos), [11, 14], true), 422, 'Informe um CNPJ ou CPF válido.');

        $estabelecimentos = $consulta->buscarEstabelecimentos($validado['cnpj']);
        abort_if($estabelecimentos->isEmpty(), 404, 'Nenhum estabelecimento encontrado com esse documento.');

        $mes = Carbon::create((int) $validado['ano'], (int) $validado['mes_numero'], 1)->startOfMonth();
        $caminho = $consulta->gerarExcel(
            $estabelecimentos,
            $mes->toDateString(),
            $mes->copy()->endOfMonth()->toDateString(),
        );

        return response()->download($caminho, $consulta->nomeArquivo($estabelecimentos, $mes), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{cnpj: string, mes_numero: int, ano: int, inicio: string, fim: string}
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
        ];
    }
}
