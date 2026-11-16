<?php

namespace App\Services;

use App\Support\EdiStatusPagamento;
use App\Support\SimpleXlsxWriter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstabelecimentoUsoComparacaoService
{
    public const SITUACOES = [
        'parou' => 'Parou de usar',
        'abaixou' => 'Abaixou o uso',
        'aumentou' => 'Aumentou o uso',
        'comecou' => 'Começou a usar',
        'manteve' => 'Manteve o uso',
    ];

    /**
     * @return array{0: string, 1: string}
     */
    public function intervaloDoMes(string $mes): array
    {
        $inicio = Carbon::createFromFormat('Y-m', $mes)->startOfMonth();

        return [$inicio->toDateString(), $inicio->copy()->endOfMonth()->toDateString()];
    }

    /**
     * Meses do intervalo, em ordem cronológica, incluindo as duas pontas.
     *
     * @return list<string>
     */
    public function mesesDoIntervalo(string $mesInicio, string $mesFim): array
    {
        $inicio = Carbon::createFromFormat('Y-m', $mesInicio)->startOfMonth();
        $fim = Carbon::createFromFormat('Y-m', $mesFim)->startOfMonth();

        if ($fim->lt($inicio)) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        $meses = [];
        $cursor = $inicio->copy();

        while ($cursor->lte($fim)) {
            $meses[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $meses;
    }

    public function situacao(float $tpvBase, float $tpvComparado): string
    {
        $base = round($tpvBase, 2);
        $comparado = round($tpvComparado, 2);

        if ($base > 0 && $comparado <= 0) {
            return 'parou';
        }

        if ($base <= 0 && $comparado > 0) {
            return 'comecou';
        }

        if ($base > 0 && $comparado > 0 && $comparado < $base) {
            return 'abaixou';
        }

        if ($base > 0 && $comparado > 0 && $comparado > $base) {
            return 'aumentou';
        }

        if ($base > 0 && $comparado > 0) {
            return 'manteve';
        }

        return 'sem_uso';
    }

    public function variacaoPercentual(float $tpvBase, float $tpvComparado): ?float
    {
        if (round($tpvBase, 2) <= 0) {
            return null;
        }

        return (($tpvComparado - $tpvBase) / $tpvBase) * 100;
    }

    public function temPlanilha(string $mes): bool
    {
        [$inicio, $fim] = $this->intervaloDoMes($mes);

        return DB::table('conciliacoes')
            ->whereBetween('referencia_mes', [$inicio, $fim])
            ->exists();
    }

    /**
     * @param  list<string>  $meses
     */
    public function consultar(?int $marketplaceId, array $meses, string $mesBase, string $mesComparado, string $fonte = 'edi'): Collection
    {
        $agregar = $fonte === 'planilha'
            ? fn (string $inicio, string $fim) => $this->agregadoPlanilha($inicio, $fim)
            : fn (string $inicio, string $fim) => $this->agregadoEdi($inicio, $fim);

        $query = DB::table('estabelecimentos as e')
            ->leftJoin('usuarios as m', 'm.id', '=', 'e.marketplace_id');

        $aliases = [];

        foreach (array_values($meses) as $indice => $mes) {
            [$inicio, $fim] = $this->intervaloDoMes($mes);
            $alias = 'mes'.$indice;
            $aliases[$mes] = $alias;
            $query->leftJoinSub($agregar($inicio, $fim), $alias, $alias.'.estabelecimento_id', '=', 'e.id');
        }

        $query->where(function ($query) use ($aliases) {
            foreach ($aliases as $alias) {
                $query->orWhereNotNull($alias.'.estabelecimento_id');
            }
        });

        if ($marketplaceId) {
            $query->where('e.marketplace_id', $marketplaceId);
        }

        $colunas = [
            'e.id',
            'e.nome_fantasia',
            'e.razao_social',
            'e.nome_completo',
            'e.cnpj',
            'e.cpf',
            'e.token_pagseguro',
            'e.marketplace_id',
            'm.nome_fantasia as marketplace_fantasia',
            'm.razao_social as marketplace_razao',
            'm.nome_completo as marketplace_nome',
        ];

        foreach (array_values($meses) as $indice => $mes) {
            $alias = $aliases[$mes];
            $colunas[] = DB::raw('COALESCE('.$alias.'.qtd, 0) as qtd_'.$indice);
            $colunas[] = DB::raw('COALESCE('.$alias.'.tpv, 0) as tpv_'.$indice);
        }

        $aliasBase = $aliases[$mesBase];
        $aliasComparado = $aliases[$mesComparado];

        return $query
            ->orderByDesc(DB::raw('ABS(COALESCE('.$aliasComparado.'.tpv, 0) - COALESCE('.$aliasBase.'.tpv, 0))'))
            ->orderBy('e.id')
            ->get($colunas)
            ->map(function ($linha) use ($meses, $mesBase, $mesComparado) {
                $tpvs = [];
                $qtds = [];

                foreach (array_values($meses) as $indice => $mes) {
                    $tpvs[$mes] = (float) $linha->{'tpv_'.$indice};
                    $qtds[$mes] = (int) $linha->{'qtd_'.$indice};
                }

                $linha->tpvs = $tpvs;
                $linha->qtds = $qtds;
                $linha->tpv_base = $tpvs[$mesBase];
                $linha->tpv_comparado = $tpvs[$mesComparado];
                $linha->qtd_base = $qtds[$mesBase];
                $linha->qtd_comparado = $qtds[$mesComparado];
                $linha->variacao = $linha->tpv_comparado - $linha->tpv_base;
                $linha->variacao_percentual = $this->variacaoPercentual($linha->tpv_base, $linha->tpv_comparado);
                $linha->situacao = $this->situacao($linha->tpv_base, $linha->tpv_comparado);

                return $linha;
            })
            ->filter(fn ($linha) => $linha->situacao !== 'sem_uso')
            ->values();
    }

    public function filtrar(Collection $linhas, string $situacao): Collection
    {
        if ($situacao === 'mudancas') {
            return $linhas
                ->filter(fn ($linha) => in_array($linha->situacao, ['parou', 'abaixou', 'aumentou', 'comecou'], true))
                ->values();
        }

        if (! array_key_exists($situacao, self::SITUACOES)) {
            return $linhas;
        }

        return $linhas->filter(fn ($linha) => $linha->situacao === $situacao)->values();
    }

    /**
     * @param  list<string>  $meses
     * @return array<string, int|float|array<string, float>>
     */
    public function resumo(Collection $linhas, array $meses = []): array
    {
        $contar = fn (string $situacao) => $linhas->filter(fn ($linha) => $linha->situacao === $situacao)->count();
        $tpvPorMes = [];

        foreach ($meses as $mes) {
            $tpvPorMes[$mes] = (float) $linhas->sum(fn ($linha) => $linha->tpvs[$mes] ?? 0);
        }

        return [
            'total' => $linhas->count(),
            'parou' => $contar('parou'),
            'abaixou' => $contar('abaixou'),
            'aumentou' => $contar('aumentou'),
            'comecou' => $contar('comecou'),
            'manteve' => $contar('manteve'),
            'tpv_base' => (float) $linhas->sum('tpv_base'),
            'tpv_comparado' => (float) $linhas->sum('tpv_comparado'),
            'variacao' => (float) $linhas->sum('variacao'),
            'tpv_por_mes' => $tpvPorMes,
        ];
    }

    /**
     * @param  list<string>  $meses
     */
    public function gerarXlsx(Collection $linhas, array $meses): string
    {
        $cabecalhos = [
            'ID',
            'Estabelecimento',
            'Documento',
            'Marketplace',
            'Safepay ID',
            'Situação',
        ];

        foreach ($meses as $mes) {
            $rotulo = $this->rotuloMes($mes);
            $cabecalhos[] = 'Qtd '.$rotulo;
            $cabecalhos[] = 'TPV '.$rotulo;
        }

        $cabecalhos[] = 'Variação R$';
        $cabecalhos[] = 'Variação %';

        $dados = $linhas->map(function ($linha) use ($meses) {
            $registro = [
                (int) $linha->id,
                $this->nome($linha),
                $linha->cnpj ?: $linha->cpf ?: '',
                $this->marketplace($linha),
                (string) ($linha->token_pagseguro ?: ''),
                self::SITUACOES[$linha->situacao] ?? $linha->situacao,
            ];

            foreach ($meses as $mes) {
                $registro[] = (int) ($linha->qtds[$mes] ?? 0);
                $registro[] = round((float) ($linha->tpvs[$mes] ?? 0), 2);
            }

            $registro[] = round((float) $linha->variacao, 2);
            $registro[] = $linha->variacao_percentual === null ? '' : round((float) $linha->variacao_percentual, 2);

            return $registro;
        })->all();

        return SimpleXlsxWriter::binary($cabecalhos, $dados, 'Comparacao de uso');
    }

    public function nomeArquivo(string $mesBase, string $mesComparado, ?int $marketplaceId, string $fonte = 'edi'): string
    {
        $marketplace = $marketplaceId ? 'mkt-'.$marketplaceId : 'todos';
        $origem = $fonte === 'planilha' ? 'planilha' : 'edi';

        return 'uso-'.$origem.'-'.$marketplace.'-'.$mesBase.'-vs-'.$mesComparado.'.xlsx';
    }

    public function nome(object $linha): string
    {
        return $linha->nome_fantasia ?: $linha->razao_social ?: $linha->nome_completo ?: '—';
    }

    public function marketplace(object $linha): string
    {
        return $linha->marketplace_fantasia ?: $linha->marketplace_razao ?: $linha->marketplace_nome ?: '—';
    }

    public function rotuloMes(string $mes): string
    {
        return Carbon::createFromFormat('Y-m', $mes)->translatedFormat('m/Y');
    }

    private function agregadoEdi(string $inicio, string $fim)
    {
        $query = DB::table('edi_movimentos')
            ->select([
                'estabelecimento_id',
                DB::raw('COUNT(*) as qtd'),
                DB::raw('COALESCE(SUM(valor_total_transacao), 0) as tpv'),
            ])
            ->whereNotNull('estabelecimento_id')
            ->whereBetween('data_inicial_transacao', [$inicio, $fim])
            ->groupBy('estabelecimento_id');

        EdiStatusPagamento::aplicarSomenteFaturaveis($query);

        return $query;
    }

    private function agregadoPlanilha(string $inicio, string $fim)
    {
        $tokens = DB::table('estabelecimentos')
            ->whereNotNull('token_pagseguro')
            ->where('token_pagseguro', '!=', '')
            ->groupBy('token_pagseguro')
            ->selectRaw('MIN(id) as id, token_pagseguro');

        $linhas = DB::table('conciliacao_linhas as cl')
            ->join('conciliacoes as c', 'c.id', '=', 'cl.conciliacao_id')
            ->leftJoinSub($tokens, 'tok', function ($join) {
                $join->on('tok.token_pagseguro', '=', 'cl.id_cliente')
                    ->whereNull('cl.estabelecimento_id');
            })
            ->whereBetween('c.referencia_mes', [$inicio, $fim])
            ->selectRaw('COALESCE(cl.estabelecimento_id, tok.id) as estabelecimento_id, cl.tpv');

        return DB::query()
            ->fromSub($linhas, 'linhas')
            ->whereNotNull('estabelecimento_id')
            ->groupBy('estabelecimento_id')
            ->selectRaw('estabelecimento_id, COUNT(*) as qtd, COALESCE(SUM(tpv), 0) as tpv');
    }
}
