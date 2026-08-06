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
        $mesesDisponiveis = $this->comissaoPag->mesesDisponiveis();
        $referenciaMes = $this->comissaoPag->parseMesReferencia($request->input('mes'))
            ?? $this->comissaoPag->mesPadrao();

        $usuario = Auth::user();
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $usuarioFiltro = $usuario instanceof Usuario && $usuario->tipo === 'marketplace'
            ? $usuario
            : null;

        $linhas = $referenciaMes
            ? $this->comissaoPag->extratoMarketplace($referenciaMes, $usuarioFiltro)
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

        $ehAdmin = $usuario instanceof Usuario && $usuario->tipo === 'admin';

        return view('relatorio.royalties', [
            'linhas' => $paginado,
            'mesesDisponiveis' => $mesesDisponiveis,
            'mesSelecionado' => $referenciaMes?->format('Y-m'),
            'periodoRotulo' => $referenciaMes
                ? $this->comissaoPag->formatarPeriodo((int) $referenciaMes->month, (int) $referenciaMes->year)
                : null,
            'conciliacao' => $conciliacao,
            'ehAdmin' => $ehAdmin,
        ]);
    }
}
