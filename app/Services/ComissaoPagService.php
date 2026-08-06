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
    /**
     * @return Collection<int, object{valor: string, rotulo: string}>
     */
    public function mesesDisponiveis(?Usuario $usuario = null): Collection
    {
        $meses = Conciliacao::query()
            ->whereNotNull('referencia_mes')
            ->orderByDesc('referencia_mes')
            ->get(['referencia_mes'])
            ->mapWithKeys(fn (Conciliacao $c) => [
                $c->referencia_mes->format('Y-m') => (object) [
                    'valor' => $c->referencia_mes->format('Y-m'),
                    'rotulo' => $this->formatarPeriodo((int) $c->referencia_mes->month, (int) $c->referencia_mes->year),
                ],
            ]);

        // Revenda usa comissão do EDI — inclui meses com movimento na carteira.
        if ($usuario?->tipo === 'revenda') {
            $estabIds = Estabelecimento::query()
                ->where('revenda_id', $usuario->id)
                ->pluck('id');

            if ($estabIds->isNotEmpty()) {
                $mesesEdi = DB::table('edi_movimentos')
                    ->whereIn('estabelecimento_id', $estabIds)
                    ->selectRaw('YEAR(data_inicial_transacao) as ano, MONTH(data_inicial_transacao) as mes')
                    ->groupBy('ano', 'mes')
                    ->orderByDesc('ano')
                    ->orderByDesc('mes')
                    ->get();

                foreach ($mesesEdi as $row) {
                    $chave = sprintf('%04d-%02d', $row->ano, $row->mes);
                    $meses[$chave] = (object) [
                        'valor' => $chave,
                        'rotulo' => $this->formatarPeriodo((int) $row->mes, (int) $row->ano),
                    ];
                }
            }
        }

        return $meses->sortKeysDesc()->values();
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
     * Extrato PagSeguro para admin/master (todos marketplaces) ou um marketplace.
     *
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
    public function extratoMarketplace(?Carbon $referenciaMes, ?Usuario $usuario = null): Collection
    {
        if ($usuario && $usuario->tipo === 'revenda') {
            return $this->extratoParceiro($referenciaMes, $usuario);
        }

        if ($usuario && $usuario->tipo === 'marketplace') {
            return $this->extratoParceiro($referenciaMes, $usuario);
        }

        return $this->extratoAgrupadoPorMarketplace($referenciaMes);
    }

    /**
     * Extrato do próprio parceiro na carteira dele.
     * Marketplace: planilha PagSeguro. Revenda: faturamento/comissao do EDI.
     */
    public function extratoParceiro(?Carbon $referenciaMes, Usuario $parceiro): Collection
    {
        if (! $referenciaMes || ! in_array($parceiro->tipo, ['marketplace', 'revenda'], true)) {
            return collect();
        }

        return $parceiro->tipo === 'revenda'
            ? $this->extratoRevendaEdi($referenciaMes, $parceiro)
            : $this->extratoMarketplacePagSeguro($referenciaMes, $parceiro);
    }

    private function extratoMarketplacePagSeguro(Carbon $referenciaMes, Usuario $marketplace): Collection
    {
        $estabelecimentoIds = Estabelecimento::query()->pluck('id');

        if ($estabelecimentoIds->isEmpty()) {
            return collect();
        }

        $row = DB::table('conciliacao_linhas as cl')
            ->join('conciliacoes as c', 'c.id', '=', 'cl.conciliacao_id')
            ->join('estabelecimentos as e', 'e.id', '=', 'cl.estabelecimento_id')
            ->where('cl.sem_estabelecimento', false)
            ->where('e.marketplace_id', $marketplace->id)
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

    private function extratoRevendaEdi(Carbon $referenciaMes, Usuario $revenda): Collection
    {
        $estabelecimentoIds = Estabelecimento::query()
            ->where('revenda_id', $revenda->id)
            ->pluck('id');

        if ($estabelecimentoIds->isEmpty()) {
            return collect();
        }

        $inicio = $referenciaMes->copy()->startOfMonth()->toDateString();
        $fim = $referenciaMes->copy()->endOfMonth()->toDateString();

        $faturamento = (float) DB::table('edi_movimentos')
            ->whereIn('estabelecimento_id', $estabelecimentoIds)
            ->whereBetween('data_inicial_transacao', [$inicio, $fim])
            ->sum('valor_total_transacao');

        $comissaoRoyalties = (float) DB::table('transacao_royalties as tr')
            ->join('edi_movimentos as em', 'em.id', '=', 'tr.edi_movimento_id')
            ->where('tr.usuario_id', $revenda->id)
            ->whereIn('em.estabelecimento_id', $estabelecimentoIds)
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->sum('tr.valor_royalty');

        // Fallback: cadeia fixada × TPV quando ainda não há lançamentos de royalty.
        if ($comissaoRoyalties > 0) {
            $comissaoBruta = $comissaoRoyalties;
            $royalty = 0.0;
            $comissaoLiquida = round($comissaoRoyalties, 2);
            $percentual = 0.0;
        } else {
            $comissaoBruta = $faturamento > 0
                ? $this->comissaoCadeiaPeriodo($estabelecimentoIds->all(), $revenda->id, $inicio, $fim)
                : 0.0;
            $liquida = $this->comissaoLiquidaParceiro($comissaoBruta, $revenda);
            $royalty = $liquida['royalty'];
            $comissaoLiquida = $liquida['liquida'];
            $percentual = $liquida['percentual'];
        }

        if ($faturamento <= 0 && $comissaoLiquida <= 0) {
            return collect();
        }

        return collect([(object) [
            'parceiro_id' => (int) $revenda->id,
            'parceiro_nome' => $revenda->nomeExibicao(),
            'parceiro_tipo' => 'revenda',
            'marketplace_id' => (int) $revenda->id,
            'marketplace_nome' => $revenda->nomeExibicao(),
            'ano' => (int) $referenciaMes->year,
            'mes' => (int) $referenciaMes->month,
            'periodo' => $this->formatarPeriodo((int) $referenciaMes->month, (int) $referenciaMes->year),
            'total_faturamento' => round($faturamento, 2),
            'total_comissao_bruta' => round($comissaoBruta, 4),
            'total_royalty' => $royalty,
            'total_comissao' => $comissaoLiquida,
            'percentual_retencao' => $percentual,
            'conciliado' => true,
        ]]);
    }

    /**
     * @param  list<int>  $estabelecimentoIds
     */
    public function comissaoCadeiaPeriodo(array $estabelecimentoIds, int $usuarioId, string $inicio, string $fim): float
    {
        if ($estabelecimentoIds === []) {
            return 0.0;
        }

        return (float) DB::table('edi_movimentos as em')
            ->join('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->join('plano_taxas as pt', function ($join) {
                $join->on('pt.plano_id', '=', 'e.plano_id')
                    ->on('pt.arranjo_ur', '=', 'em.arranjo_ur')
                    ->on('pt.parcelas', '=', DB::raw('COALESCE(NULLIF(em.quantidade_parcela, 0), 1)'))
                    ->where('pt.ativo', true);
            })
            ->join('estabelecimento_royalties as er', function ($join) use ($usuarioId) {
                $join->on('er.estabelecimento_id', '=', 'e.id')
                    ->on('er.plano_taxa_id', '=', 'pt.id')
                    ->where('er.usuario_id', '=', $usuarioId);
            })
            ->whereIn('em.estabelecimento_id', $estabelecimentoIds)
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->sum(DB::raw('em.valor_total_transacao * er.percentual_royalty / 100'));
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
