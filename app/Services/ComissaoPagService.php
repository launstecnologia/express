<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\Estabelecimento;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ComissaoPagService
{
    private const MESES = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];

    /**
     * @return Collection<int, object{valor: string, rotulo: string}>
     */
    public function mesesDisponiveis(?Usuario $usuario = null): Collection
    {
        return Conciliacao::query()
            ->whereNotNull('referencia_mes')
            ->orderByDesc('referencia_mes')
            ->get(['referencia_mes'])
            ->unique(fn (Conciliacao $c) => $c->referencia_mes?->format('Y-m'))
            ->map(fn (Conciliacao $c) => (object) [
                'valor' => $c->referencia_mes->format('Y-m'),
                'rotulo' => $this->formatarPeriodo((int) $c->referencia_mes->month, (int) $c->referencia_mes->year),
            ])
            ->values();
    }

    public function conciliacaoDoMes(?Carbon $referenciaMes): ?Conciliacao
    {
        if (! $referenciaMes) {
            return null;
        }

        return Conciliacao::query()
            ->whereDate('referencia_mes', $referenciaMes->copy()->startOfMonth()->toDateString())
            ->latest('id')
            ->first();
    }

    public function mesPadrao(): ?Carbon
    {
        $referencia = Conciliacao::query()
            ->whereNotNull('referencia_mes')
            ->orderByDesc('referencia_mes')
            ->value('referencia_mes');

        return $referencia ? Carbon::parse($referencia)->startOfMonth() : null;
    }

    /**
     * @return Collection<int, object{
     *     parceiro_id: int,
     *     parceiro_nome: string,
     *     parceiro_tipo: string,
     *     marketplace_id: int,
     *     marketplace_nome: string,
     *     ano: int,
     *     mes: int,
     *     periodo: string,
     *     total_faturamento: float,
     *     total_comissao_bruta: float,
     *     total_royalty: float,
     *     total_comissao: float,
     *     percentual_retencao: float,
     *     conciliado: bool
     * }>
     */
    public function extratoMarketplace(?Carbon $referenciaMes, ?Usuario $usuario = null, string $visao = 'marketplace'): Collection
    {
        $visao = in_array($visao, ['marketplace', 'revenda'], true) ? $visao : 'marketplace';

        if ($usuario && $usuario->tipo === 'revenda') {
            return $this->extratoParceiro($referenciaMes, $usuario);
        }

        if ($usuario && $usuario->tipo === 'marketplace') {
            return $visao === 'revenda'
                ? $this->extratoAgrupadoPorRevenda($referenciaMes, $usuario)
                : $this->extratoParceiro($referenciaMes, $usuario);
        }

        return $visao === 'revenda'
            ? $this->extratoAgrupadoPorRevenda($referenciaMes)
            : $this->extratoAgrupadoPorMarketplace($referenciaMes);
    }

    public function extratoParceiro(?Carbon $referenciaMes, Usuario $parceiro): Collection
    {
        if (! $referenciaMes || ! in_array($parceiro->tipo, ['marketplace', 'revenda'], true)) {
            return collect();
        }

        return $parceiro->tipo === 'revenda'
            ? $this->extratoRevendaConciliacao($referenciaMes, $parceiro)
            : $this->extratoMarketplacePagSeguro($referenciaMes, $parceiro);
    }

    private function extratoMarketplacePagSeguro(Carbon $referenciaMes, Usuario $marketplace): Collection
    {
        $row = $this->totaisConciliacaoPorCarteira($referenciaMes, 'marketplace_id', $marketplace->id);

        if (! $row) {
            return collect();
        }

        return collect([$this->montarLinhaParceiro(
            $marketplace,
            (int) $row->ano,
            (int) $row->mes,
            (float) $row->total_faturamento,
            (float) $row->total_comissao,
            $this->conciliacaoDoMes($referenciaMes) !== null,
        )]);
    }

    /**
     * Comissão da revenda na conciliação PagSeguro:
     * 1) soma ms_comissao dos clientes (ECs) da revenda
     * 2) desconta royalty do admin/master sobre a comissão do marketplace
     * 3) aplica o % da revenda sobre essa comissão líquida do marketplace
     *
     * Ex.: bruta 1000, admin 20% → marketplace 800; revenda 25% → 200.
     */
    private function extratoRevendaConciliacao(Carbon $referenciaMes, Usuario $revenda): Collection
    {
        $row = $this->totaisConciliacaoPorCarteira($referenciaMes, 'revenda_id', $revenda->id);

        if (! $row) {
            return collect();
        }

        $marketplace = $this->marketplaceDaRevenda($revenda);
        $calc = $this->comissaoRevendaDaCarteira(
            (float) $row->total_comissao,
            $marketplace,
            $revenda,
        );

        $conciliado = $this->conciliacaoDoMes($referenciaMes) !== null;

        return collect([$this->montarLinhaRevenda(
            $revenda,
            $marketplace,
            (int) $row->ano,
            (int) $row->mes,
            (float) $row->total_faturamento,
            $calc,
            $conciliado,
        )]);
    }

    private function extratoAgrupadoPorRevenda(?Carbon $referenciaMes, ?Usuario $marketplace = null): Collection
    {
        if (! $referenciaMes) {
            return collect();
        }

        $estabelecimentoIds = Estabelecimento::query()->pluck('id');

        if ($estabelecimentoIds->isEmpty()) {
            return collect();
        }

        $query = DB::table('conciliacao_linhas as cl')
            ->join('conciliacoes as c', 'c.id', '=', 'cl.conciliacao_id')
            ->join('estabelecimentos as e', 'e.id', '=', 'cl.estabelecimento_id')
            ->where('cl.sem_estabelecimento', false)
            ->whereNotNull('e.revenda_id')
            ->whereIn('e.id', $estabelecimentoIds)
            ->whereDate('c.referencia_mes', $referenciaMes->copy()->startOfMonth()->toDateString());

        if ($marketplace) {
            $query->where('e.marketplace_id', $marketplace->id);
        }

        $rows = $query
            ->selectRaw('
                e.revenda_id,
                YEAR(c.referencia_mes) as ano,
                MONTH(c.referencia_mes) as mes,
                SUM(cl.tpv) as total_faturamento,
                SUM(cl.ms_comissao) as total_comissao
            ')
            ->groupBy('e.revenda_id', 'ano', 'mes')
            ->orderByDesc('total_faturamento')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $conciliado = $this->conciliacaoDoMes($referenciaMes) !== null;

        $revendas = Usuario::query()
            ->with('hierarquia.pai.usuario')
            ->whereIn('id', $rows->pluck('revenda_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($revendas, $conciliado) {
            $revenda = $revendas->get($row->revenda_id);

            if (! $revenda) {
                return null;
            }

            $marketplace = $this->marketplaceDaRevenda($revenda);
            $calc = $this->comissaoRevendaDaCarteira(
                (float) $row->total_comissao,
                $marketplace,
                $revenda,
            );

            return $this->montarLinhaRevenda(
                $revenda,
                $marketplace,
                (int) $row->ano,
                (int) $row->mes,
                (float) $row->total_faturamento,
                $calc,
                $conciliado,
            );
        })->filter()->values();
    }

    /**
     * @param  array{
     *     marketplace_bruta: float,
     *     admin_royalty: float,
     *     marketplace_liquida: float,
     *     percentual_revenda: float,
     *     revenda: float
     * }  $calc
     */
    private function montarLinhaRevenda(
        Usuario $revenda,
        ?Usuario $marketplace,
        int $ano,
        int $mes,
        float $faturamento,
        array $calc,
        bool $conciliado,
    ): object {
        return (object) [
            'parceiro_id' => (int) $revenda->id,
            'parceiro_nome' => $revenda->nomeExibicao(),
            'parceiro_tipo' => 'revenda',
            'marketplace_id' => (int) ($marketplace?->id ?? 0),
            'marketplace_nome' => $marketplace?->nomeExibicao() ?? '—',
            'ano' => $ano,
            'mes' => $mes,
            'periodo' => $this->formatarPeriodo($mes, $ano),
            'total_faturamento' => round($faturamento, 2),
            'total_comissao_bruta' => $calc['marketplace_bruta'],
            'total_royalty' => $calc['admin_royalty'],
            'percentual_admin' => (float) ($marketplace?->percentual_retencao_pai ?? 0),
            'total_comissao' => $calc['revenda'],
            'percentual_retencao' => $calc['percentual_revenda'],
            'marketplace_liquida' => $calc['marketplace_liquida'],
            'conciliado' => $conciliado,
        ];
    }

    /**
     * @return object{ano: int, mes: int, total_faturamento: float, total_comissao: float}|null
     */
    private function totaisConciliacaoPorCarteira(Carbon $referenciaMes, string $coluna, int $parceiroId): ?object
    {
        $estabelecimentoIds = Estabelecimento::query()->pluck('id');

        if ($estabelecimentoIds->isEmpty()) {
            return null;
        }

        $row = DB::table('conciliacao_linhas as cl')
            ->join('conciliacoes as c', 'c.id', '=', 'cl.conciliacao_id')
            ->join('estabelecimentos as e', 'e.id', '=', 'cl.estabelecimento_id')
            ->where('cl.sem_estabelecimento', false)
            ->where('e.'.$coluna, $parceiroId)
            ->whereIn('e.id', $estabelecimentoIds)
            ->whereDate('c.referencia_mes', $referenciaMes->copy()->startOfMonth()->toDateString())
            ->selectRaw('
                YEAR(c.referencia_mes) as ano,
                MONTH(c.referencia_mes) as mes,
                SUM(cl.tpv) as total_faturamento,
                SUM(cl.ms_comissao) as total_comissao
            ')
            ->groupBy('ano', 'mes')
            ->first();

        if (! $row || ((float) $row->total_faturamento <= 0 && (float) $row->total_comissao <= 0)) {
            return null;
        }

        return $row;
    }

    /**
     * Valor que o parceiro recebe. Revenda sempre parte da ms_comissao da conciliação
     * (ou da bruta equivalente): % sobre a líquida do marketplace.
     */
    public function valorComissaoParceiro(float $comissaoBruta, Usuario $parceiro): float
    {
        if ($parceiro->tipo === 'revenda') {
            return $this->comissaoRevendaDaCarteira(
                $comissaoBruta,
                $this->marketplaceDaRevenda($parceiro),
                $parceiro,
            )['revenda'];
        }

        return $this->comissaoLiquidaParceiro($comissaoBruta, $parceiro)['liquida'];
    }

    /**
     * @return array{
     *     marketplace_bruta: float,
     *     admin_royalty: float,
     *     marketplace_liquida: float,
     *     percentual_revenda: float,
     *     revenda: float
     * }
     */
    public function comissaoRevendaDaCarteira(float $comissaoMarketplaceBruta, ?Usuario $marketplace, Usuario $revenda): array
    {
        $bruta = round($comissaoMarketplaceBruta, 4);
        $pctAdmin = (float) ($marketplace?->percentual_retencao_pai ?? 0);
        $adminRoyalty = $pctAdmin > 0 ? round($bruta * $pctAdmin / 100, 2) : 0.0;
        $marketplaceLiquida = round($bruta - $adminRoyalty, 2);

        $pctRevenda = (float) ($revenda->percentual_retencao_pai ?? 0);
        $revendaValor = $pctRevenda > 0
            ? round($marketplaceLiquida * $pctRevenda / 100, 2)
            : 0.0;

        return [
            'marketplace_bruta' => $bruta,
            'admin_royalty' => $adminRoyalty,
            'marketplace_liquida' => $marketplaceLiquida,
            'percentual_revenda' => $pctRevenda,
            'revenda' => $revendaValor,
        ];
    }

    /**
     * Comissão da revenda por plano, sempre a partir da ms_comissao da conciliação.
     *
     * @return Collection<int, float>
     */
    public function comissaoRevendaPorPlano(Carbon $referenciaMes, Usuario $revenda): Collection
    {
        $estabelecimentoIds = Estabelecimento::query()->pluck('id');

        if ($estabelecimentoIds->isEmpty()) {
            return collect();
        }

        $marketplace = $this->marketplaceDaRevenda($revenda);

        $rows = DB::table('conciliacao_linhas as cl')
            ->join('conciliacoes as c', 'c.id', '=', 'cl.conciliacao_id')
            ->join('estabelecimentos as e', 'e.id', '=', 'cl.estabelecimento_id')
            ->where('cl.sem_estabelecimento', false)
            ->where('e.revenda_id', $revenda->id)
            ->whereIn('e.id', $estabelecimentoIds)
            ->whereNotNull('e.plano_id')
            ->whereDate('c.referencia_mes', $referenciaMes->copy()->startOfMonth()->toDateString())
            ->selectRaw('e.plano_id, SUM(cl.ms_comissao) as total_comissao')
            ->groupBy('e.plano_id')
            ->get();

        return $rows->mapWithKeys(function ($row) use ($marketplace, $revenda) {
            $calc = $this->comissaoRevendaDaCarteira(
                (float) $row->total_comissao,
                $marketplace,
                $revenda,
            );

            return [(int) $row->plano_id => $calc['revenda']];
        });
    }

    private function marketplaceDaRevenda(Usuario $revenda): ?Usuario
    {
        $revenda->loadMissing('hierarquia.pai.usuario');

        $pai = $revenda->hierarquia?->pai?->usuario;

        return $pai && $pai->tipo === 'marketplace' ? $pai : null;
    }

    private function montarLinhaParceiro(
        Usuario $parceiro,
        int $ano,
        int $mes,
        float $faturamento,
        float $comissaoBruta,
        bool $conciliado,
    ): object {
        $liquida = $this->comissaoLiquidaParceiro($comissaoBruta, $parceiro);

        return (object) [
            'parceiro_id' => (int) $parceiro->id,
            'parceiro_nome' => $parceiro->nomeExibicao(),
            'parceiro_tipo' => $parceiro->tipo,
            'marketplace_id' => (int) $parceiro->id,
            'marketplace_nome' => $parceiro->nomeExibicao(),
            'ano' => $ano,
            'mes' => $mes,
            'periodo' => $this->formatarPeriodo($mes, $ano),
            'total_faturamento' => round($faturamento, 2),
            'total_comissao_bruta' => $liquida['bruta'],
            'total_royalty' => $liquida['royalty'],
            'total_comissao' => $liquida['liquida'],
            'percentual_retencao' => $liquida['percentual'],
            'conciliado' => $conciliado,
        ];
    }

    private function extratoAgrupadoPorMarketplace(?Carbon $referenciaMes): Collection
    {
        if (! $referenciaMes) {
            return collect();
        }

        $estabelecimentoIds = Estabelecimento::query()->pluck('id');

        if ($estabelecimentoIds->isEmpty()) {
            return collect();
        }

        $rows = DB::table('conciliacao_linhas as cl')
            ->join('conciliacoes as c', 'c.id', '=', 'cl.conciliacao_id')
            ->join('estabelecimentos as e', 'e.id', '=', 'cl.estabelecimento_id')
            ->where('cl.sem_estabelecimento', false)
            ->whereNotNull('e.marketplace_id')
            ->whereIn('e.id', $estabelecimentoIds)
            ->whereDate('c.referencia_mes', $referenciaMes->copy()->startOfMonth()->toDateString())
            ->selectRaw('
                e.marketplace_id,
                YEAR(c.referencia_mes) as ano,
                MONTH(c.referencia_mes) as mes,
                SUM(cl.tpv) as total_faturamento,
                SUM(cl.ms_comissao) as total_comissao
            ')
            ->groupBy('e.marketplace_id', 'ano', 'mes')
            ->orderBy('total_faturamento', 'desc')
            ->get();

        $conciliado = $this->conciliacaoDoMes($referenciaMes) !== null;

        $marketplaces = Usuario::query()
            ->whereIn('id', $rows->pluck('marketplace_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($marketplaces, $conciliado) {
            $marketplace = $marketplaces->get($row->marketplace_id);
            $comissaoBruta = round((float) $row->total_comissao, 4);
            $liquida = $this->comissaoLiquidaParceiro($comissaoBruta, $marketplace);

            return (object) [
                'parceiro_id' => (int) $row->marketplace_id,
                'parceiro_nome' => $marketplace?->nomeExibicao() ?? '—',
                'parceiro_tipo' => 'marketplace',
                'marketplace_id' => (int) $row->marketplace_id,
                'marketplace_nome' => $marketplace?->nomeExibicao() ?? '—',
                'ano' => (int) $row->ano,
                'mes' => (int) $row->mes,
                'periodo' => $this->formatarPeriodo((int) $row->mes, (int) $row->ano),
                'total_faturamento' => round((float) $row->total_faturamento, 2),
                'total_comissao_bruta' => $comissaoBruta,
                'total_royalty' => $liquida['royalty'],
                'total_comissao' => $liquida['liquida'],
                'percentual_retencao' => $liquida['percentual'],
                'conciliado' => $conciliado,
            ];
        });
    }

    /**
     * @return array{bruta: float, royalty: float, liquida: float, percentual: float}
     */
    public function comissaoLiquidaParceiro(float $comissaoBruta, ?Usuario $parceiro): array
    {
        $bruta = round($comissaoBruta, 4);
        $percentual = (float) ($parceiro?->percentual_retencao_pai ?? 0);

        if (! $parceiro || $bruta <= 0 || $percentual <= 0) {
            return [
                'bruta' => $bruta,
                'royalty' => 0.0,
                'liquida' => round($bruta, 2),
                'percentual' => $percentual,
            ];
        }

        $royalty = round($bruta * $percentual / 100, 2);

        return [
            'bruta' => $bruta,
            'royalty' => $royalty,
            'liquida' => round($bruta - $royalty, 2),
            'percentual' => $percentual,
        ];
    }

    /** @deprecated Use comissaoLiquidaParceiro */
    public function comissaoLiquidaMarketplace(float $comissaoBruta, ?Usuario $marketplace): array
    {
        return $this->comissaoLiquidaParceiro($comissaoBruta, $marketplace);
    }

    public function formatarPeriodo(int $mes, int $ano): string
    {
        return (self::MESES[$mes] ?? (string) $mes).'/'.$ano;
    }

    public function parseMesReferencia(?string $valor): ?Carbon
    {
        if (! filled($valor) || ! preg_match('/^\d{4}-\d{2}$/', $valor)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m', $valor)->startOfMonth();
    }
}
