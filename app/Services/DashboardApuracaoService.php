<?php

namespace App\Services;

use App\Support\EdiTransacaoCategoria;
use App\Support\ComissaoAdminSql;
use App\Support\InstituicaoFinanceira;
use App\Models\Estabelecimento;
use App\Models\Plano;
use App\Models\SubUsuario;
use App\Models\Usuario;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardApuracaoService
{
    private const CACHE_TTL_SECONDS = 300;

    public function periodoValido(int $dias): int
    {
        return in_array($dias, [7, 30, 90], true) ? $dias : 30;
    }

    /**
     * @return array{
     *     dias: int,
     *     planos: list<array{
     *         id: int,
     *         nome: string,
     *         faturamento: float,
     *         comissao: float,
     *         debito: float,
     *         credito: float,
     *         parcelado: float,
     *         pix: float
     *     }>,
     *     resumo: array{faturamento_total: float, comissao_total: float, pix_total: float, debito_total: float, credito_total: float, parcelado_total: float},
     *     transacoes_status: array{itens: list<array{label: string, cor: string, quantidade: int, percentual: float}>, gradiente: string, total: int},
     *     faturamento_bandeiras: list<array{codigo: string, label: string, valor: float, barra_pct: float, icon_url: ?string}>
     * }
     */
    public function apurar(int $dias = 30, ?Authenticatable $usuario = null): array
    {
        $dias = $this->periodoValido($dias);

        return Cache::remember(
            $this->cacheKey($dias, $usuario),
            self::CACHE_TTL_SECONDS,
            fn () => $this->calcular($dias, $usuario),
        );
    }

    /**
     * @return array{
     *     dias: int,
     *     planos: list<array<string, mixed>>,
     *     resumo: array<string, float>,
     *     transacoes_status: array<string, mixed>,
     *     faturamento_bandeiras: list<array<string, mixed>>
     * }
     */
    public function calcular(int $dias, ?Authenticatable $usuario): array
    {
        return $this->calcularApuracao($this->periodoValido($dias), $usuario);
    }

    /**
     * @return array{
     *     dias: int,
     *     planos: list<array{
     *         id: int,
     *         nome: string,
     *         faturamento: float,
     *         comissao: float,
     *         debito: float,
     *         credito: float,
     *         parcelado: float,
     *         pix: float
     *     }>,
     *     resumo: array{faturamento_total: float, comissao_total: float, pix_total: float, debito_total: float, credito_total: float, parcelado_total: float}
     * }
     */
    private function calcularApuracao(int $dias, ?Authenticatable $usuario): array
    {
        $desde = now()->subDays($dias)->toDateString();

        if (! $this->temEstabelecimentosVisiveis()) {
            $vazia = $this->respostaVazia($dias);

            return array_merge($vazia, [
                'transacoes_status' => ['itens' => [], 'gradiente' => 'conic-gradient(#e5e7eb 0 100%)', 'total' => 0],
                'faturamento_bandeiras' => [],
            ]);
        }

        $usuarioIdFiltro = $this->usuarioIdComissao($usuario);

        $faturamentoPorPlano = $this->agregarFaturamento($desde);
        $comissaoPorPlano = $usuarioIdFiltro
            ? $this->agregarComissao($desde, $usuarioIdFiltro)
            : $this->agregarComissaoAdmin($desde);

        $planoIds = $faturamentoPorPlano->keys()
            ->merge($comissaoPorPlano->keys())
            ->unique()
            ->filter()
            ->values();

        $planos = $planoIds->isEmpty()
            ? collect()
            : Plano::query()
                ->whereIn('id', $planoIds)
                ->where('ativo', true)
                ->orderBy('nome')
                ->get();

        $planosResumo = $planos->map(function (Plano $plano) use ($faturamentoPorPlano, $comissaoPorPlano) {
            $categorias = $faturamentoPorPlano->get($plano->id, collect());
            $debito = (float) ($categorias->get('debito') ?? 0);
            $credito = (float) ($categorias->get('credito') ?? 0);
            $parcelado = (float) ($categorias->get('parcelado') ?? 0);
            $pix = (float) ($categorias->get('pix') ?? 0);
            $faturamento = $debito + $credito + $parcelado + $pix;

            return [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'faturamento' => round($faturamento, 2),
                'comissao' => round((float) ($comissaoPorPlano->get($plano->id) ?? 0), 2),
                'debito' => round($debito, 2),
                'credito' => round($credito, 2),
                'parcelado' => round($parcelado, 2),
                'pix' => round($pix, 2),
            ];
        })
            ->filter(fn (array $plano) => $plano['faturamento'] > 0 || $plano['comissao'] > 0)
            ->sortByDesc('faturamento')
            ->values()
            ->all();

        $resumo = [
            'faturamento_total' => round(collect($planosResumo)->sum('faturamento'), 2),
            'comissao_total' => round(collect($planosResumo)->sum('comissao'), 2),
            'pix_total' => round(collect($planosResumo)->sum('pix'), 2),
            'debito_total' => round(collect($planosResumo)->sum('debito'), 2),
            'credito_total' => round(collect($planosResumo)->sum('credito'), 2),
            'parcelado_total' => round(collect($planosResumo)->sum('parcelado'), 2),
        ];

        return [
            'dias' => $dias,
            'planos' => $planosResumo,
            'resumo' => $resumo,
            'transacoes_status' => $this->transacoesPorStatus($desde),
            'faturamento_bandeiras' => $this->faturamentoPorBandeira($desde),
        ];
    }

    /**
     * @return array{itens: list<array{label: string, cor: string, quantidade: int, percentual: float}>, gradiente: string, total: int}
     */
    private function transacoesPorStatus(string $desde): array
    {
        $linhas = DB::table('edi_movimentos as em')
            ->where('em.data_inicial_transacao', '>=', $desde)
            ->whereIn('em.estabelecimento_id', $this->estabelecimentosVisiveisSubquery())
            ->selectRaw('COALESCE(NULLIF(em.status_pagamento, ""), "sem") as status_codigo, COUNT(*) as quantidade')
            ->groupByRaw('COALESCE(NULLIF(em.status_pagamento, ""), "sem")')
            ->orderByDesc('quantidade')
            ->get();

        $total = (int) $linhas->sum('quantidade');

        if ($total === 0) {
            return ['itens' => [], 'gradiente' => 'conic-gradient(#e5e7eb 0 100%)', 'total' => 0];
        }

        $offset = 0.0;
        $stops = [];
        $itens = [];

        foreach ($linhas as $linha) {
            $codigo = (string) $linha->status_codigo;
            $quantidade = (int) $linha->quantidade;
            $percentual = round(($quantidade / $total) * 100, 2);
            $cor = $this->corStatus($codigo);
            $fim = $offset + $percentual;

            $stops[] = "{$cor} {$offset}% {$fim}%";
            $offset = $fim;

            $itens[] = [
                'label' => $this->rotuloStatus($codigo),
                'cor' => $cor,
                'quantidade' => $quantidade,
                'percentual' => $percentual,
            ];
        }

        return [
            'itens' => $itens,
            'gradiente' => 'conic-gradient('.implode(', ', $stops).')',
            'total' => $total,
        ];
    }

    /**
     * @return list<array{codigo: string, label: string, valor: float, barra_pct: float, icon_url: ?string}>
     */
    private function faturamentoPorBandeira(string $desde): array
    {
        $linhas = DB::table('edi_movimentos as em')
            ->where('em.data_inicial_transacao', '>=', $desde)
            ->whereIn('em.estabelecimento_id', $this->estabelecimentosVisiveisSubquery())
            ->whereNotNull('em.instituicao_financeira')
            ->selectRaw('em.instituicao_financeira as instituicao, SUM(em.valor_total_transacao) as valor')
            ->groupBy('em.instituicao_financeira')
            ->orderByDesc('valor')
            ->get();

        $max = (float) ($linhas->max('valor') ?: 0);

        return $linhas->map(function ($linha) use ($max) {
            $codigo = (string) $linha->instituicao;
            $valor = (float) $linha->valor;

            return [
                'codigo' => $codigo,
                'label' => InstituicaoFinanceira::nome($codigo),
                'valor' => round($valor, 2),
                'barra_pct' => $max > 0 ? round(($valor / $max) * 100, 2) : 0,
                'icon_url' => InstituicaoFinanceira::iconUrl($codigo),
            ];
        })->values()->all();
    }

    private function rotuloStatus(string $codigo): string
    {
        return match ($codigo) {
            '03', '3' => 'Concluídas',
            '01', '1' => 'Novas',
            '02', '2' => 'Agendadas',
            '04', '4' => 'Canceladas',
            'sem' => 'Sem status',
            default => 'Status '.$codigo,
        };
    }

    private function corStatus(string $codigo): string
    {
        return match ($codigo) {
            '03', '3' => '#2563eb',
            '01', '1' => '#38bdf8',
            '02', '2' => '#0f766e',
            '04', '4' => '#ef4444',
            default => '#94a3b8',
        };
    }

    private function agregarFaturamento(string $desde): Collection
    {
        $categoriaSql = EdiTransacaoCategoria::sqlCategoria('em');

        return DB::table('edi_movimentos as em')
            ->join('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->where('em.data_inicial_transacao', '>=', $desde)
            ->whereIn('em.estabelecimento_id', $this->estabelecimentosVisiveisSubquery())
            ->whereNotNull('e.plano_id')
            ->selectRaw("e.plano_id, {$categoriaSql} as categoria, SUM(em.valor_total_transacao) as total")
            ->groupBy('e.plano_id', DB::raw($categoriaSql))
            ->get()
            ->filter(fn ($row) => $row->categoria !== 'outros')
            ->groupBy('plano_id')
            ->map(fn (Collection $rows) => $rows->pluck('total', 'categoria'));
    }

    private function agregarComissaoAdmin(string $desde): Collection
    {
        return ComissaoAdminSql::queryMovimentosComComissaoAdmin(function ($query) use ($desde) {
            $query->where('em.data_inicial_transacao', '>=', $desde)
                ->whereIn('em.estabelecimento_id', $this->estabelecimentosVisiveisSubquery())
                ->whereNotNull('e.plano_id');
        })
            ->selectRaw('e.plano_id, SUM('.ComissaoAdminSql::valor().') as total')
            ->groupBy('e.plano_id')
            ->get()
            ->pluck('total', 'plano_id');
    }

    private function agregarComissao(string $desde, ?int $usuarioIdFiltro): Collection
    {
        $query = DB::table('transacao_royalties as tr')
            ->join('edi_movimentos as em', 'em.id', '=', 'tr.edi_movimento_id')
            ->join('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->where('em.data_inicial_transacao', '>=', $desde)
            ->whereIn('em.estabelecimento_id', $this->estabelecimentosVisiveisSubquery())
            ->whereNotNull('e.plano_id')
            ->selectRaw('e.plano_id, SUM(tr.valor_royalty) as total')
            ->groupBy('e.plano_id');

        if ($usuarioIdFiltro) {
            $query->where('tr.usuario_id', $usuarioIdFiltro);
        }

        return $query->get()->pluck('total', 'plano_id');
    }

    private function usuarioIdComissao(?Authenticatable $usuario): ?int
    {
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        if ($usuario instanceof Usuario && $usuario->tipo !== 'admin') {
            return $usuario->id;
        }

        return null;
    }

    private function temEstabelecimentosVisiveis(): bool
    {
        return Estabelecimento::query()->exists();
    }

    /** @return Builder<Estabelecimento> */
    private function estabelecimentosVisiveisSubquery(): Builder
    {
        return Estabelecimento::query()->select('id');
    }

    private function cacheKey(int $dias, ?Authenticatable $usuario): string
    {
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $tipo = $usuario instanceof Usuario ? $usuario->tipo : 'guest';
        $id = $usuario?->id ?? 0;

        return "dashboard.apuracao.v2.{$tipo}.{$id}.{$dias}";
    }

    private function respostaVazia(int $dias): array
    {
        return [
            'dias' => $dias,
            'planos' => [],
            'resumo' => [
                'faturamento_total' => 0,
                'comissao_total' => 0,
                'pix_total' => 0,
                'debito_total' => 0,
                'credito_total' => 0,
                'parcelado_total' => 0,
            ],
        ];
    }
}
