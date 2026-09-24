<?php

namespace App\Http\Controllers\Royalty;

use App\Http\Controllers\Controller;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Services\ComissaoPagService;
use App\Services\ConciliacaoConfrontoService;
use App\Support\SimpleXlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoyaltyController extends Controller
{
    public function __construct(
        private readonly ComissaoPagService $comissaoPag,
    ) {}

    public function index(Request $request)
    {
        if ($request->boolean('export')) {
            return $this->excel($request, app(ConciliacaoConfrontoService::class));
        }

        $ctx = $this->contexto($request);
        $visao = $ctx['visao'];
        $referenciaMes = $ctx['referenciaMes'];
        $mesesDisponiveis = $ctx['mesesDisponiveis'];
        $usuarioFiltro = $ctx['usuarioFiltro'];
        $ehAdmin = $ctx['ehAdmin'];
        $ehMarketplace = $ctx['ehMarketplace'];
        $ehRevenda = $ctx['ehRevenda'];
        $podeSelecionarVisao = $ctx['podeSelecionarVisao'];

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
            'ehMarketplace' => $ehMarketplace,
            'ehRevenda' => $ehRevenda || $visao === 'revenda',
            'visao' => $visao,
            'podeSelecionarVisao' => $podeSelecionarVisao,
        ]);
    }

    public function excel(Request $request, ConciliacaoConfrontoService $confronto): Response
    {
        @set_time_limit(900);

        $ctx = $this->contexto($request);
        $referenciaMes = $ctx['referenciaMes'];
        $paramsLista = array_filter([
            'mes' => $referenciaMes?->format('Y-m'),
            'visao' => $ctx['visao'],
        ]);

        if ($referenciaMes === null) {
            return redirect()->route('comissoes.index', $paramsLista)
                ->withErrors(['excel' => 'Nenhuma planilha PagSeguro neste mês.']);
        }

        $conciliacao = $this->comissaoPag->conciliacaoDoMes($referenciaMes);
        if ($conciliacao === null) {
            return redirect()->route('comissoes.index', $paramsLista)
                ->withErrors(['excel' => 'Nenhuma planilha PagSeguro importada para o mês.']);
        }

        $filtros = $this->filtrosExcel($request, $ctx);
        $planilha = $confronto->planilhaPorMarketplace($conciliacao, $filtros);
        if ($planilha['planilhas'] === []) {
            return redirect()->route('comissoes.index', $paramsLista)
                ->withErrors(['excel' => 'Não foi possível montar a planilha deste marketplace.']);
        }

        $caminho = SimpleXlsxWriter::fileSheets($planilha['planilhas']);

        return response()->download($caminho, $planilha['nome_arquivo'], [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * @return array{
     *     usuario: mixed,
     *     visao: string,
     *     referenciaMes: \Illuminate\Support\Carbon|null,
     *     mesesDisponiveis: \Illuminate\Support\Collection,
     *     usuarioFiltro: ?Usuario,
     *     ehAdmin: bool,
     *     ehMarketplace: bool,
     *     ehRevenda: bool,
     *     podeSelecionarVisao: bool
     * }
     */
    private function contexto(Request $request): array
    {
        $usuario = Auth::user();
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $ehAdmin = $usuario instanceof Usuario && $usuario->tipo === 'admin';
        $ehMaster = $usuario instanceof Usuario && $usuario->tipo === 'master';
        $ehMarketplace = $usuario instanceof Usuario && $usuario->tipo === 'marketplace';
        $ehRevenda = $usuario instanceof Usuario && $usuario->tipo === 'revenda';
        $podeSelecionarVisao = $ehAdmin || $ehMaster || $ehMarketplace;

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

        return [
            'usuario' => $usuario,
            'visao' => $visao,
            'referenciaMes' => $referenciaMes,
            'mesesDisponiveis' => $mesesDisponiveis,
            'usuarioFiltro' => $usuarioFiltro,
            'ehAdmin' => $ehAdmin,
            'ehMaster' => $ehMaster,
            'ehMarketplace' => $ehMarketplace,
            'ehRevenda' => $ehRevenda,
            'podeSelecionarVisao' => $podeSelecionarVisao,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, int>
     */
    private function filtrosExcel(Request $request, array $ctx): array
    {
        $filtros = [];
        $usuario = $ctx['usuario'];

        if ($usuario instanceof Usuario && $usuario->tipo === 'marketplace') {
            $filtros['marketplace_id'] = (int) $usuario->id;
        }

        if ($usuario instanceof Usuario && $usuario->tipo === 'revenda') {
            $filtros['revenda_id'] = (int) $usuario->id;
        }

        $marketplaceId = (int) $request->input('marketplace_id', 0);
        if ($marketplaceId > 0 && ($ctx['ehAdmin'] || ($ctx['ehMaster'] ?? false) || ($filtros['marketplace_id'] ?? 0) === $marketplaceId)) {
            $filtros['marketplace_id'] = $marketplaceId;
        }

        $revendaId = (int) $request->input('revenda_id', 0);
        if ($revendaId > 0) {
            if ($ctx['ehAdmin'] || ($ctx['ehMaster'] ?? false) || ($ctx['ehMarketplace'] ?? false) || ($filtros['revenda_id'] ?? 0) === $revendaId) {
                $filtros['revenda_id'] = $revendaId;
            }
        }

        return $filtros;
    }
}
