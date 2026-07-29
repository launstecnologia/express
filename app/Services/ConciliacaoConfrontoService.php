<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Support\ComissaoAdminSql;
use App\Support\ConciliacaoDimensao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConciliacaoConfrontoService
{
    private const TOLERANCIA = 0.02;

    public function confrontar(Conciliacao $conciliacao): Conciliacao
    {
        @set_time_limit(300);

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();

        $agregados = $this->agregarEdi($inicio, $fim);

        $ok = 0;
        $divergentes = 0;
        $semEstabelecimento = 0;
        $semEdi = 0;

        $conciliacao->linhas()->orderBy('id')->chunkById(500, function ($linhas) use (
            $agregados,
            &$ok,
            &$divergentes,
            &$semEstabelecimento,
            &$semEdi,
        ) {
            $lote = [];

            foreach ($linhas as $linha) {
                if ($linha->sem_estabelecimento) {
                    $semEstabelecimento++;
                    $lote[] = [
                        'id' => (int) $linha->id,
                        'status' => 'sem_estabelecimento',
                        'edi_tpv' => null,
                        'edi_comissao' => null,
                        'edi_qtd' => null,
                        'diff_tpv' => null,
                        'diff_comissao' => null,
                    ];

                    continue;
                }

                $edi = $agregados->get($linha->chave_confronto);

                if ($edi === null) {
                    $semEdi++;
                    $lote[] = [
                        'id' => (int) $linha->id,
                        'status' => 'sem_edi',
                        'edi_tpv' => 0,
                        'edi_comissao' => 0,
                        'edi_qtd' => 0,
                        'diff_tpv' => round((float) $linha->tpv, 2),
                        'diff_comissao' => round((float) $linha->ms_comissao, 4),
                    ];

                    continue;
                }

                $diffTpv = round((float) $linha->tpv - (float) $edi['tpv'], 2);
                $diffComissao = round((float) $linha->ms_comissao - (float) $edi['comissao'], 4);
                $bate = abs($diffTpv) <= self::TOLERANCIA && abs($diffComissao) <= self::TOLERANCIA;

                if ($bate) {
                    $ok++;
                } else {
                    $divergentes++;
                }

                $lote[] = [
                    'id' => (int) $linha->id,
                    'status' => $bate ? 'ok' : 'divergente',
                    'edi_tpv' => $edi['tpv'],
                    'edi_comissao' => $edi['comissao'],
                    'edi_qtd' => $edi['qtd'],
                    'diff_tpv' => $diffTpv,
                    'diff_comissao' => $diffComissao,
                ];
            }

            $this->aplicarLote($lote);
        });

        $conciliacao->update([
            'status' => 'confrontado',
            'confrontado_em' => now(),
            'linhas_ok' => $ok,
            'linhas_divergentes' => $divergentes,
            'linhas_sem_estabelecimento' => $semEstabelecimento,
            'linhas_sem_edi' => $semEdi,
        ]);

        return $conciliacao->fresh();
    }

    /**
     * @param  list<array{
     *     id: int,
     *     status: string,
     *     edi_tpv: float|int|null,
     *     edi_comissao: float|int|null,
     *     edi_qtd: int|null,
     *     diff_tpv: float|int|null,
     *     diff_comissao: float|int|null
     * }>  $lote
     */
    private function aplicarLote(array $lote): void
    {
        if ($lote === []) {
            return;
        }

        $ids = array_column($lote, 'id');
        $campos = ['status', 'edi_tpv', 'edi_comissao', 'edi_qtd', 'diff_tpv', 'diff_comissao'];
        $cases = array_fill_keys($campos, '');

        foreach ($lote as $row) {
            $id = (int) $row['id'];

            foreach ($campos as $campo) {
                $cases[$campo] .= ' WHEN '.$id.' THEN '.$this->sqlLiteral($row[$campo]);
            }
        }

        $sets = [];

        foreach ($campos as $campo) {
            $sets[] = "{$campo} = CASE id{$cases[$campo]} END";
        }

        $sets[] = 'updated_at = NOW()';

        DB::update(
            'UPDATE conciliacao_linhas SET '.implode(', ', $sets)
            .' WHERE id IN ('.implode(',', $ids).')'
        );
    }

    private function sqlLiteral(mixed $valor): string
    {
        if ($valor === null) {
            return 'NULL';
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        return DB::getPdo()->quote((string) $valor);
    }

    /**
     * @return Collection<string, array{tpv: float, comissao: float, qtd: int}>
     */
    private function agregarEdi(string $inicio, string $fim): Collection
    {
        $query = DB::table('edi_movimentos as em')
            ->leftJoin('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->leftJoin('plano_taxas as pt', function ($join) {
                ComissaoAdminSql::joinPlanoTaxa($join);
            });

        ComissaoAdminSql::joinRoyaltyAdmin($query)
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->whereNotNull('em.estabelecimento_id')
            ->select([
                'em.id',
                'em.tipo_transacao',
                'em.quantidade_parcela',
                'em.instituicao_financeira',
                'em.arranjo_ur',
                'em.meio_captura',
                'em.canal_entrada',
                'em.leitor',
                'em.pagamento_prazo',
                'em.valor_total_transacao',
                DB::raw(ComissaoAdminSql::percentual().' as comissao_percentual'),
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
                DB::raw('e.segmento as mcc_estabelecimento'),
            ]);

        $grupos = [];

        foreach ($query->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            $chave = ConciliacaoDimensao::chaveConfronto(
                $idCliente,
                strtolower(trim((string) $mov->tipo_transacao)),
                ConciliacaoDimensao::parcelamentoDoEdi($mov->quantidade_parcela),
                ConciliacaoDimensao::bandeiraDoEdi($mov->instituicao_financeira, $mov->tipo_transacao, $mov->arranjo_ur),
                ConciliacaoDimensao::escrowDoEdi($mov->pagamento_prazo),
                ConciliacaoDimensao::mccDoEstabelecimento($mov->mcc_estabelecimento),
                ConciliacaoDimensao::solucaoDoEdi($mov->meio_captura, $mov->canal_entrada, $mov->leitor),
            );

            if (! isset($grupos[$chave])) {
                $grupos[$chave] = ['tpv' => 0.0, 'comissao' => 0.0, 'qtd' => 0];
            }

            $valor = (float) $mov->valor_total_transacao;
            $comissaoPct = (float) ($mov->comissao_percentual ?? 0);

            $grupos[$chave]['tpv'] += $valor;
            $grupos[$chave]['comissao'] += $valor * $comissaoPct / 100;
            $grupos[$chave]['qtd']++;
        }

        return collect($grupos)->map(fn (array $item) => [
            'tpv' => round($item['tpv'], 2),
            'comissao' => round($item['comissao'], 4),
            'qtd' => $item['qtd'],
        ]);
    }

    /**
     * @return array{
     *     com_estabelecimento: array{linhas: int, clientes: int, tpv: float, comissao: float},
     *     sem_estabelecimento: array{linhas: int, clientes: int, tpv: float, comissao: float}
     * }
     */
    public function resumoEstabelecimentos(Conciliacao $conciliacao): array
    {
        $vazio = ['linhas' => 0, 'clientes' => 0, 'tpv' => 0.0, 'comissao' => 0.0];
        $resumo = [
            'com_estabelecimento' => $vazio,
            'sem_estabelecimento' => $vazio,
        ];

        $rows = ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->selectRaw('sem_estabelecimento, COUNT(*) as linhas, COUNT(DISTINCT id_cliente) as clientes, SUM(tpv) as tpv, SUM(ms_comissao) as comissao')
            ->groupBy('sem_estabelecimento')
            ->get();

        foreach ($rows as $row) {
            $chave = $row->sem_estabelecimento ? 'sem_estabelecimento' : 'com_estabelecimento';
            $resumo[$chave] = [
                'linhas' => (int) $row->linhas,
                'clientes' => (int) $row->clientes,
                'tpv' => (float) $row->tpv,
                'comissao' => (float) $row->comissao,
            ];
        }

        return $resumo;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{id_cliente: string, linhas: int, tpv: float, comissao: float}>
     */
    public function clientesSemEstabelecimento(Conciliacao $conciliacao): Collection
    {
        return ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->where('sem_estabelecimento', true)
            ->selectRaw('id_cliente, COUNT(*) as linhas, SUM(tpv) as tpv, SUM(ms_comissao) as comissao')
            ->groupBy('id_cliente')
            ->orderByDesc('tpv')
            ->get();
    }

    /**
     * @return array{
     *     edi_tpv: float,
     *     edi_comissao: float,
     *     edi_clientes: int,
     *     pagseguro_tpv: float,
     *     pagseguro_comissao: float,
     *     pagseguro_clientes: int
     * }
     */
    public function resumoMensal(Conciliacao $conciliacao): array
    {
        return [
            'pagseguro_tpv' => (float) $conciliacao->total_tpv,
            'pagseguro_comissao' => (float) $conciliacao->total_comissao,
            'pagseguro_clientes' => (int) $conciliacao->total_clientes,
            'edi_tpv' => (float) $conciliacao->linhas()->sum('edi_tpv'),
            'edi_comissao' => (float) $conciliacao->linhas()->sum('edi_comissao'),
            'edi_clientes' => (int) $conciliacao->linhas()
                ->whereNotNull('estabelecimento_id')
                ->distinct('id_cliente')
                ->count('id_cliente'),
        ];
    }
}
