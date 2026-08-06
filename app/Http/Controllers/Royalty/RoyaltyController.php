<?php

namespace App\Http\Controllers\Royalty;

use App\Http\Controllers\Controller;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Services\ComissaoPagService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

class RoyaltyController extends Controller
{
    public function __construct(
        private readonly ComissaoPagService $comissaoPag,
    ) {}

    public function index(Request $request)
    {
        $usuario = Auth::user();
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $ehAdmin = $usuario instanceof Usuario && $usuario->tipo === 'admin';
        $ehMaster = $usuario instanceof Usuario && $usuario->tipo === 'master';
        $ehRevenda = $usuario instanceof Usuario && $usuario->tipo === 'revenda';
        $podeSelecionarVisao = $ehAdmin || $ehMaster;

        $visao = $podeSelecionarVisao && $request->input('visao') === 'revenda'
            ? 'revenda'
            : 'marketplace';

        $mesesDisponiveis = $this->comissaoPag->mesesDisponiveis(
            $usuario instanceof Usuario ? $usuario : null
        );
        $referenciaMes = $this->comissaoPag->parseMesReferencia($request->input('mes'))
            ?? ($mesesDisponiveis->first()?->valor
                ? $this->comissaoPag->parseMesReferencia($mesesDisponiveis->first()->valor)
                : $this->comissaoPag->mesPadrao());

        $usuarioFiltro = $usuario instanceof Usuario && in_array($usuario->tipo, ['marketplace', 'revenda'], true)
            ? $usuario
            : null;

        $linhas = $referenciaMes
            ? $this->comissaoPag->extratoMarketplace($referenciaMes, $usuarioFiltro, $visao)
            : collect();

        $conciliacao = $referenciaMes
            ? $this->comissaoPag->conciliacaoDoMes($referenciaMes)
            : null;

        $page = $request->integer('page', 1);
        $perPage = 50;
        $paginado = new LengthAwarePaginator(
            $linhas->forPage($page, $perPage)->values(),
            $linhas->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('relatorio.royalties', [
            'linhas' => $paginado,
            'mesesDisponiveis' => $mesesDisponiveis,
            'mesSelecionado' => $referenciaMes?->format('Y-m'),
            'periodoRotulo' => $referenciaMes
                ? $this->comissaoPag->formatarPeriodo((int) $referenciaMes->month, (int) $referenciaMes->year)
                : null,
            'conciliacao' => $conciliacao,
            'ehAdmin' => $ehAdmin,
            'ehRevenda' => $ehRevenda || $visao === 'revenda',
            'visao' => $visao,
            'podeSelecionarVisao' => $podeSelecionarVisao,
        ]);
    }
}
