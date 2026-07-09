<?php

namespace App\Http\Controllers\Relatorio;

use App\Http\Controllers\Controller;
use App\Models\AggregatedRevenue;
use App\Models\EdiMovimento;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Support\ComissaoAdminSql;
use App\Support\EdiMovimentoDetalhe;
use App\Support\InstituicaoFinanceira;
use App\Services\RoyaltyCalculadorService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RelatorioController extends Controller
{
    public function faturamento(Request $request)
    {
        $request = $this->normalizarFiltrosFaturamento($request);

        $query = AggregatedRevenue::query()
            ->with([
                'estabelecimento.plano',
                'marketplace',
                'master',
                'revenda',
            ])
            ->latest('data');

        $this->aplicarFiltrosFaturamento($query, $request);

        $usuario = $request->user();
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $totais = (clone $query)->selectRaw('
            COALESCE(SUM(total_transacoes), 0) as total_transacoes,
            COALESCE(SUM(total_valor), 0) as total_valor,
            COALESCE(SUM(total_royalty), 0) as total_royalty
        ')->first();

        $linhas = $query->paginate(50)->withQueryString();
        $this->preencherComissoesExibidas($linhas->getCollection(), $usuario);

        $ehAdmin = ! ($usuario instanceof Usuario) || $usuario->tipo === 'admin';
        $totalRoyaltyExibido = $ehAdmin
            ? $this->totalComissaoAdmin($request)
            : $this->totalComissaoParceiro($request, $usuario->id);

        $filtros = $request->only([
            'estabelecimento',
            'master_id',
            'marketplace_id',
            'revenda_id',
            'tipo_transacao',
            'instituicao',
            'status_pagamento',
            'data_inicio',
            'data_fim',
            'ano',
            'mes',
            'todos_periodos',
        ]);

        return view('relatorio.faturamento', [
            'linhas' => $linhas,
            'totais' => $totais,
            'totalRoyaltyExibido' => $totalRoyaltyExibido,
            'filtros' => $filtros,
            'periodo' => $this->periodoFaturamento($request),
            'filtrosAtivos' => $this->contarFiltrosAtivosFaturamento($filtros),
            'masters' => $this->usuariosPorTipo('master'),
            'marketplaces' => $this->usuariosPorTipo('marketplace'),
            'revendas' => $this->usuariosPorTipo('revenda'),
            'instituicoes' => InstituicaoFinanceira::codigos(),
            'tiposTransacao' => ['debito', 'credito', 'pix'],
        ]);
    }

    public function faturamentoDetalhe(AggregatedRevenue $linha, Request $request, RoyaltyCalculadorService $royaltyService)
    {
        $usuario = $request->user();
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $movimentos = EdiMovimento::withoutGlobalScopes()
            ->with(['estabelecimento.plano', 'royalties.usuario'])
            ->where('estabelecimento_id', $linha->estabelecimento_id)
            ->whereDate('data_inicial_transacao', $linha->data)
            ->where('instituicao_financeira', $linha->instituicao)
            ->where('tipo_transacao', $linha->tipo_transacao)
            ->where('status_pagamento', $linha->status_pagamento)
            ->orderBy('hora_inicial_transacao')
            ->get()
            ->map(function (EdiMovimento $movimento) use ($royaltyService) {
                $taxa = $royaltyService->planoTaxaDoMovimento($movimento);

                return [
                    'id' => $movimento->id,
                    'codigo' => $movimento->movimento_api_codigo,
                    'valor_total' => (float) $movimento->valor_total_transacao,
                    'campos_edi' => EdiMovimentoDetalhe::campos($movimento),
                    'plano_taxa' => $taxa ? [
                        'id' => $taxa->id,
                        'taxa_percentual' => (float) $taxa->taxa_percentual,
                        'comissao_percentual' => $taxa->comissaoAdminPercentual(),
                        'arranjo_ur' => $taxa->arranjo_ur,
                        'instituicao' => $taxa->instituicao,
                        'tipo_transacao' => $taxa->tipo_transacao,
                        'parcelas' => $taxa->parcelas,
                    ] : null,
                    'comissoes' => $movimento->royalties->map(fn ($royalty) => [
                        'usuario' => $royalty->usuario?->nomeExibicao() ?? '—',
                        'nivel' => $royalty->nivel,
                        'percentual' => (float) $royalty->percentual_royalty,
                        'valor' => (float) $royalty->valor_royalty,
                    ])->values(),
                ];
            });

        return response()->json([
            'resumo' => [
                'data' => $linha->data?->format('d/m/Y'),
                'instituicao' => $linha->instituicao,
                'tipo_transacao' => $linha->tipo_transacao,
                'total_transacoes' => $linha->total_transacoes,
                'total_valor' => (float) $linha->total_valor,
                'comissao' => $this->comissaoExibida($linha, $usuario),
                'estabelecimento' => $linha->estabelecimento?->nome_fantasia
                    ?: $linha->estabelecimento?->razao_social
                    ?: $linha->estabelecimento?->nome_completo,
                'plano' => $linha->estabelecimento?->plano?->nome,
                'marketplace' => $linha->marketplace?->nomeExibicao(),
            ],
            'movimentos' => $movimentos,
        ]);
    }

    private function aplicarFiltrosFaturamento(EloquentBuilder $query, Request $request): void
    {
        if ($request->filled('estabelecimento')) {
            $termo = '%'.$request->string('estabelecimento')->trim().'%';
            $query->whereHas('estabelecimento', function (EloquentBuilder $estabelecimento) use ($termo) {
                $estabelecimento->where(function (EloquentBuilder $q) use ($termo) {
                    $q->where('nome_fantasia', 'like', $termo)
                        ->orWhere('razao_social', 'like', $termo)
                        ->orWhere('nome_completo', 'like', $termo);
                });
            });
        }

        if ($request->filled('master_id')) {
            $query->where('master_id', $request->integer('master_id'));
        }

        if ($request->filled('marketplace_id')) {
            $query->where('marketplace_id', $request->integer('marketplace_id'));
        }

        if ($request->filled('revenda_id')) {
            $query->where('revenda_id', $request->integer('revenda_id'));
        }

        if ($request->filled('tipo_transacao')) {
            $query->where('tipo_transacao', $request->string('tipo_transacao'));
        }

        if ($request->filled('instituicao')) {
            $query->where('instituicao', $request->string('instituicao'));
        }

        if ($request->filled('status_pagamento')) {
            $query->where('status_pagamento', $request->string('status_pagamento'));
        }

        if ($request->filled('data_inicio')) {
            $query->where('data', '>=', $request->date('data_inicio')->toDateString());
        }

        if ($request->filled('data_fim')) {
            $query->where('data', '<=', $request->date('data_fim')->toDateString());
        }

        if ($request->filled('ano')) {
            $query->where('ano', $request->integer('ano'));
        }

        if ($request->filled('mes')) {
            $query->where('mes', $request->integer('mes'));
        }
    }

    private function usuariosPorTipo(string $tipo)
    {
        return Cache::remember("relatorio.usuarios.{$tipo}", 300, function () use ($tipo) {
            return Usuario::query()
                ->where('tipo', $tipo)
                ->where('ativo', true)
                ->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')
                ->get()
                ->map(fn (Usuario $usuario) => [
                    'id' => $usuario->id,
                    'nome' => $usuario->nomeExibicao(),
                ]);
        });
    }

    private function comissaoExibida(AggregatedRevenue $linha, Usuario|SubUsuario|null $usuario): float
    {
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $ehAdmin = ! ($usuario instanceof Usuario) || $usuario->tipo === 'admin';

        // Admin enxerga a comissão da plataforma (comissao_percentual da taxa do plano),
        // que independe de cadeia comercial. Parceiro enxerga o próprio royalty repassado.
        if ($ehAdmin) {
            return $this->comissaoAdminLinha($linha);
        }

        return (float) DB::table('transacao_royalties')
            ->join('edi_movimentos', 'edi_movimentos.id', '=', 'transacao_royalties.edi_movimento_id')
            ->whereDate('edi_movimentos.data_inicial_transacao', $linha->data)
            ->where('edi_movimentos.estabelecimento_id', $linha->estabelecimento_id)
            ->where('edi_movimentos.instituicao_financeira', $linha->instituicao)
            ->where('edi_movimentos.tipo_transacao', $linha->tipo_transacao)
            ->where('edi_movimentos.status_pagamento', $linha->status_pagamento)
            ->where('transacao_royalties.usuario_id', $usuario->id)
            ->sum('transacao_royalties.valor_royalty');
    }

    /**
     * Base de movimentos EDI com os mesmos filtros do relatório (aplicados em
     * edi_movimentos/estabelecimentos), para somar a comissão de todos os
     * resultados, não apenas da página exibida.
     */
    private function baseMovimentosFiltrados(Request $request): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('edi_movimentos as em')
            ->join('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id');

        $this->aplicarFiltrosMovimentosBase($query, $request);

        return $query;
    }

    private function aplicarFiltrosMovimentosBase(\Illuminate\Database\Query\Builder $query, Request $request): void
    {
        if ($request->filled('estabelecimento')) {
            $termo = '%'.$request->string('estabelecimento')->trim().'%';
            $query->where(function ($q) use ($termo) {
                $q->where('e.nome_fantasia', 'like', $termo)
                    ->orWhere('e.razao_social', 'like', $termo)
                    ->orWhere('e.nome_completo', 'like', $termo);
            });
        }

        if ($request->filled('master_id')) {
            $query->where('e.master_id', $request->integer('master_id'));
        }

        if ($request->filled('marketplace_id')) {
            $query->where('e.marketplace_id', $request->integer('marketplace_id'));
        }

        if ($request->filled('revenda_id')) {
            $query->where('e.revenda_id', $request->integer('revenda_id'));
        }

        if ($request->filled('tipo_transacao')) {
            $query->where('em.tipo_transacao', $request->string('tipo_transacao'));
        }

        if ($request->filled('instituicao')) {
            $query->where('em.instituicao_financeira', $request->string('instituicao'));
        }

        if ($request->filled('status_pagamento')) {
            $query->where('em.status_pagamento', $request->string('status_pagamento'));
        }

        if ($request->filled('data_inicio')) {
            $query->where('em.data_inicial_transacao', '>=', $request->date('data_inicio')->toDateString());
        }

        if ($request->filled('data_fim')) {
            $query->where('em.data_inicial_transacao', '<=', $request->date('data_fim')->toDateString());
        }

        if ($request->filled('ano') && $request->filled('mes')) {
            $ano = $request->integer('ano');
            $mes = $request->integer('mes');
            $inicio = sprintf('%04d-%02d-01', $ano, $mes);
            $fim = date('Y-m-t', strtotime($inicio));
            $query->whereBetween('em.data_inicial_transacao', [$inicio, $fim]);
        } elseif ($request->filled('ano')) {
            $ano = $request->integer('ano');
            $query->whereBetween('em.data_inicial_transacao', ["{$ano}-01-01", "{$ano}-12-31"]);
        } elseif ($request->filled('mes')) {
            $query->whereMonth('em.data_inicial_transacao', $request->integer('mes'));
        }
    }

    private function totalComissaoAdmin(Request $request): float
    {
        return (float) ComissaoAdminSql::queryMovimentosComComissaoAdmin(function (QueryBuilder $query) use ($request) {
            $this->aplicarFiltrosMovimentosBase($query, $request);
            $query->whereNotNull('e.plano_id');
        })->sum(DB::raw(ComissaoAdminSql::valor()));
    }

    private function totalComissaoParceiro(Request $request, int $usuarioId): float
    {
        return (float) $this->baseMovimentosFiltrados($request)
            ->join('transacao_royalties as tr', 'tr.edi_movimento_id', '=', 'em.id')
            ->where('tr.usuario_id', $usuarioId)
            ->sum('tr.valor_royalty');
    }

    private function comissaoAdminLinha(AggregatedRevenue $linha): float
    {
        return (float) ComissaoAdminSql::queryMovimentosComComissaoAdmin()
            ->whereDate('em.data_inicial_transacao', $linha->data)
            ->where('em.estabelecimento_id', $linha->estabelecimento_id)
            ->where('em.instituicao_financeira', $linha->instituicao)
            ->where('em.tipo_transacao', $linha->tipo_transacao)
            ->where('em.status_pagamento', $linha->status_pagamento)
            ->sum(DB::raw(ComissaoAdminSql::valor()));
    }

    private function normalizarFiltrosFaturamento(Request $request): Request
    {
        if ($request->boolean('todos_periodos')) {
            return $request;
        }

        $temPeriodo = $request->filled('data_inicio')
            || $request->filled('data_fim')
            || $request->filled('ano')
            || $request->filled('mes');

        if (! $temPeriodo) {
            $request->merge([
                'ano' => now()->year,
                'mes' => now()->month,
            ]);
        }

        return $request;
    }

    /**
     * @return array{modo: string, label: ?string, ano: ?int, mes: ?int}
     */
    private function periodoFaturamento(Request $request): array
    {
        if ($request->boolean('todos_periodos')) {
            return ['modo' => 'todos', 'label' => 'Todo o histórico', 'ano' => null, 'mes' => null];
        }

        if ($request->filled('data_inicio') || $request->filled('data_fim')) {
            $inicio = $request->date('data_inicio')?->format('d/m/Y') ?? '…';
            $fim = $request->date('data_fim')?->format('d/m/Y') ?? '…';

            return [
                'modo' => 'intervalo',
                'label' => "{$inicio} até {$fim}",
                'ano' => null,
                'mes' => null,
            ];
        }

        if ($request->filled('ano') && $request->filled('mes')) {
            $data = now()->setDate($request->integer('ano'), $request->integer('mes'), 1);

            return [
                'modo' => 'mes',
                'label' => $data->translatedFormat('F/Y'),
                'ano' => $request->integer('ano'),
                'mes' => $request->integer('mes'),
            ];
        }

        if ($request->filled('ano')) {
            return [
                'modo' => 'ano',
                'label' => (string) $request->integer('ano'),
                'ano' => $request->integer('ano'),
                'mes' => null,
            ];
        }

        return ['modo' => 'mes', 'label' => now()->translatedFormat('F/Y'), 'ano' => now()->year, 'mes' => now()->month];
    }

    private function contarFiltrosAtivosFaturamento(array $filtros): int
    {
        $ignorar = ['ano', 'mes', 'todos_periodos'];

        $ativos = collect($filtros)
            ->reject(fn ($valor, $chave) => in_array($chave, $ignorar, true))
            ->filter(fn ($valor) => $valor !== null && $valor !== '')
            ->count();

        $periodoCustomizado = ($filtros['todos_periodos'] ?? false)
            || filled($filtros['data_inicio'] ?? null)
            || filled($filtros['data_fim'] ?? null)
            || (int) ($filtros['ano'] ?? now()->year) !== now()->year
            || (int) ($filtros['mes'] ?? now()->month) !== now()->month;

        return $ativos + ($periodoCustomizado ? 1 : 0);
    }

    private function preencherComissoesExibidas(Collection $linhas, Usuario|SubUsuario|null $usuario): void
    {
        if ($linhas->isEmpty()) {
            return;
        }

        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $ehAdmin = ! ($usuario instanceof Usuario) || $usuario->tipo === 'admin';
        $mapa = $ehAdmin
            ? $this->comissoesAdminPorLinhas($linhas)
            : $this->comissoesParceiroPorLinhas($linhas, $usuario->id);

        $linhas->transform(function (AggregatedRevenue $linha) use ($mapa) {
            $linha->setAttribute('comissao_exibida', $mapa->get($this->chaveLinhaFaturamento($linha), 0.0));

            return $linha;
        });
    }

    private function chaveLinhaFaturamento(AggregatedRevenue $linha): string
    {
        return implode('|', [
            $linha->data?->format('Y-m-d') ?? '',
            $linha->estabelecimento_id ?? '',
            $linha->instituicao ?? '',
            $linha->tipo_transacao ?? '',
            $linha->status_pagamento ?? '',
        ]);
    }

    /**
     * @return Collection<string, float>
     */
    private function comissoesAdminPorLinhas(Collection $linhas): Collection
    {
        $estabelecimentoIds = $linhas->pluck('estabelecimento_id')->filter()->unique()->values();
        $dataMin = $linhas->min(fn (AggregatedRevenue $linha) => $linha->data?->toDateString());
        $dataMax = $linhas->max(fn (AggregatedRevenue $linha) => $linha->data?->toDateString());

        if ($estabelecimentoIds->isEmpty() || ! $dataMin || ! $dataMax) {
            return collect();
        }

        return ComissaoAdminSql::queryMovimentosComComissaoAdmin()
            ->whereIn('em.estabelecimento_id', $estabelecimentoIds)
            ->whereBetween('em.data_inicial_transacao', [$dataMin, $dataMax])
            ->selectRaw('
                DATE(em.data_inicial_transacao) as data,
                em.estabelecimento_id,
                em.instituicao_financeira as instituicao,
                em.tipo_transacao,
                em.status_pagamento,
                SUM('.ComissaoAdminSql::valor().') as comissao
            ')
            ->groupBy('data', 'em.estabelecimento_id', 'em.instituicao_financeira', 'em.tipo_transacao', 'em.status_pagamento')
            ->get()
            ->mapWithKeys(fn ($row) => [
                implode('|', [
                    $row->data,
                    $row->estabelecimento_id,
                    $row->instituicao,
                    $row->tipo_transacao,
                    $row->status_pagamento,
                ]) => (float) $row->comissao,
            ]);
    }

    /**
     * @return Collection<string, float>
     */
    private function comissoesParceiroPorLinhas(Collection $linhas, int $usuarioId): Collection
    {
        $estabelecimentoIds = $linhas->pluck('estabelecimento_id')->filter()->unique()->values();
        $dataMin = $linhas->min(fn (AggregatedRevenue $linha) => $linha->data?->toDateString());
        $dataMax = $linhas->max(fn (AggregatedRevenue $linha) => $linha->data?->toDateString());

        if ($estabelecimentoIds->isEmpty() || ! $dataMin || ! $dataMax) {
            return collect();
        }

        return DB::table('transacao_royalties as tr')
            ->join('edi_movimentos as em', 'em.id', '=', 'tr.edi_movimento_id')
            ->where('tr.usuario_id', $usuarioId)
            ->whereIn('em.estabelecimento_id', $estabelecimentoIds)
            ->whereBetween('em.data_inicial_transacao', [$dataMin, $dataMax])
            ->selectRaw('
                DATE(em.data_inicial_transacao) as data,
                em.estabelecimento_id,
                em.instituicao_financeira as instituicao,
                em.tipo_transacao,
                em.status_pagamento,
                SUM(tr.valor_royalty) as comissao
            ')
            ->groupBy('data', 'em.estabelecimento_id', 'em.instituicao_financeira', 'em.tipo_transacao', 'em.status_pagamento')
            ->get()
            ->mapWithKeys(fn ($row) => [
                implode('|', [
                    $row->data,
                    $row->estabelecimento_id,
                    $row->instituicao,
                    $row->tipo_transacao,
                    $row->status_pagamento,
                ]) => (float) $row->comissao,
            ]);
    }
}
