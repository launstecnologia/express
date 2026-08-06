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

    public function __construct(
        private readonly RoyaltyCalculadorService $royaltyCalculador,
    ) {}

    /**
     * @return Collection<int, object{valor: string, rotulo: string}>
     */
    public function mesesDisponiveis(): Collection
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
    public function extratoMarketplace(?Carbon $referenciaMes, ?Usuario $usuario = null): Collection
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
            ->whereNotNull('e.marketplace_id')
            ->whereIn('e.id', $estabelecimentoIds)
            ->whereDate('c.referencia_mes', $referenciaMes->copy()->startOfMonth()->toDateString());

        if ($usuario && $usuario->tipo === 'marketplace') {
            $query->where('e.marketplace_id', $usuario->id);
        }

        $rows = $query
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

        $conciliacao = $this->conciliacaoDoMes($referenciaMes);
        $conciliado = $conciliacao !== null;

        $marketplaces = Usuario::query()
            ->with('hierarquia.pai.usuario')
            ->whereIn('id', $rows->pluck('marketplace_id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($marketplaces, $conciliado) {
            $marketplace = $marketplaces->get($row->marketplace_id);
            $comissaoBruta = round((float) $row->total_comissao, 4);
            $liquida = $this->comissaoLiquidaMarketplace($comissaoBruta, $marketplace);

            return (object) [
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
     * Aplica a retenção (royalty) do pai sobre a comissão bruta do marketplace.
     *
     * @return array{bruta: float, royalty: float, liquida: float, percentual: float}
     */
    public function comissaoLiquidaMarketplace(float $comissaoBruta, ?Usuario $marketplace): array
    {
        $bruta = round($comissaoBruta, 4);

        if (! $marketplace || $bruta <= 0) {
            return [
                'bruta' => $bruta,
                'royalty' => 0.0,
                'liquida' => round($bruta, 2),
                'percentual' => 0.0,
            ];
        }

        $retencao = $this->royaltyCalculador->calcularRetencaoPai($marketplace, $bruta);
        $royalty = (float) $retencao['valor'];

        return [
            'bruta' => $bruta,
            'royalty' => $royalty,
            'liquida' => round($bruta - $royalty, 2),
            'percentual' => (float) ($marketplace->percentual_retencao_pai ?? 0),
        ];
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
