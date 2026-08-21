<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Models\Estabelecimento;
use App\Support\ComissaoAdminSql;
use App\Support\ConciliacaoDimensao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConciliacaoConfrontoService
{
    public const TOLERANCIA = 0.02;

    public function confrontar(Conciliacao $conciliacao): Conciliacao
    {
        @set_time_limit(900);

        $conciliacao->update([
            'confronto_status' => 'processando',
            'confronto_erro' => null,
            'confronto_iniciado_em' => now(),
        ]);

        // Religa clientes cadastrados depois da importação (token_pagseguro = id_cliente).
        $this->religarEstabelecimentos($conciliacao);

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();

        $agregados = $this->agregarEdi($inicio, $fim);

        $ok = 0;
        $divergentes = 0;
        $semEstabelecimento = 0;
        $semEdi = 0;

        $linhas = $conciliacao->linhas()->orderBy('id')->get();
        $totaisPorChave = [];

        foreach ($linhas as $linha) {
            if ($linha->sem_estabelecimento) {
                continue;
            }

            $chave = $this->chaveDaLinha($linha);

            if (! isset($totaisPorChave[$chave])) {
                $totaisPorChave[$chave] = ['tpv' => 0.0, 'comissao' => 0.0];
            }

            $totaisPorChave[$chave]['tpv'] += (float) $linha->tpv;
            $totaisPorChave[$chave]['comissao'] += (float) $linha->ms_comissao;
        }

        foreach ($linhas->chunk(500) as $loteLinhas) {
            $lote = [];

            foreach ($loteLinhas as $linha) {
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

                $chave = $this->chaveDaLinha($linha);
                $edi = $agregados->get($chave);
                $grupo = $totaisPorChave[$chave] ?? ['tpv' => 0.0, 'comissao' => 0.0];
                $grupoTpv = (float) $grupo['tpv'];
                $grupoComissao = (float) $grupo['comissao'];

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

                $bate = self::tpvCompativel($grupoTpv, (float) $edi['tpv'])
                    && self::valoresCompativeis($grupoComissao, (float) $edi['comissao']);

                if ($bate) {
                    $ok++;
                } else {
                    $divergentes++;
                }

                $ratioTpv = $grupoTpv > 0 ? (float) $linha->tpv / $grupoTpv : 0.0;
                $ediTpvLinha = round((float) $edi['tpv'] * $ratioTpv, 2);
                $ediComissaoLinha = self::ratear((float) $edi['comissao'], (float) $linha->tpv, $grupoTpv);

                $lote[] = [
                    'id' => (int) $linha->id,
                    'status' => $bate ? 'ok' : 'divergente',
                    'edi_tpv' => $ediTpvLinha,
                    'edi_comissao' => $ediComissaoLinha,
                    'edi_qtd' => (int) round($edi['qtd'] * $ratioTpv),
                    'diff_tpv' => round((float) $linha->tpv - $ediTpvLinha, 2),
                    'diff_comissao' => round((float) $linha->ms_comissao - $ediComissaoLinha, 4),
                ];
            }

            $this->aplicarLote($lote);
        }

        $conciliacao->update([
            'status' => 'confrontado',
            'confronto_status' => 'concluido',
            'confronto_erro' => null,
            'confrontado_em' => now(),
            'linhas_ok' => $ok,
            'linhas_divergentes' => $divergentes,
            'linhas_sem_estabelecimento' => $semEstabelecimento,
            'linhas_sem_edi' => $semEdi,
        ]);

        return $conciliacao->fresh();
    }

    /**
     * Vincula linhas ainda sem estabelecimento a cadastros novos/atualizados
     * pelo token PagSeguro (id_cliente da planilha).
     */
    public function religarEstabelecimentos(Conciliacao $conciliacao): int
    {
        $estabelecimentos = Estabelecimento::withoutGlobalScopes()
            ->whereNotNull('token_pagseguro')
            ->where('token_pagseguro', '!=', '')
            ->pluck('id', 'token_pagseguro');

        if ($estabelecimentos->isEmpty()) {
            return 0;
        }

        $atualizados = 0;
        $agora = now();

        ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->where(function ($q) {
                $q->where('sem_estabelecimento', true)
                    ->orWhereNull('estabelecimento_id');
            })
            ->orderBy('id')
            ->chunkById(500, function ($linhas) use ($estabelecimentos, &$atualizados, $agora) {
                foreach ($linhas as $linha) {
                    $estabelecimentoId = $estabelecimentos[$linha->id_cliente] ?? null;

                    if (! $estabelecimentoId) {
                        continue;
                    }

                    $linha->update([
                        'estabelecimento_id' => $estabelecimentoId,
                        'sem_estabelecimento' => false,
                        'status' => 'pendente',
                        'updated_at' => $agora,
                    ]);

                    $atualizados++;
                }
            });

        return $atualizados;
    }

    public static function tpvCompativel(float $tpvA, float $tpvB): bool
    {
        return self::valoresCompativeis($tpvA, $tpvB);
    }

    public static function valoresCompativeis(float $valorA, float $valorB): bool
    {
        return abs(round($valorA, 2) - round($valorB, 2)) <= self::TOLERANCIA;
    }

    /**
     * Rateia o total do grupo (TPV ou comissão registrada do EDI) pela participação da linha.
     */
    public static function ratear(float $totalGrupo, float $pesoLinha, float $pesoGrupo, int $casas = 4): float
    {
        if ($pesoGrupo <= 0) {
            return 0.0;
        }

        return round($totalGrupo * $pesoLinha / $pesoGrupo, $casas);
    }

    private function chaveDaLinha(ConciliacaoLinha $linha): string
    {
        return ConciliacaoDimensao::chaveConfrontoDaLinha(
            (string) $linha->id_cliente,
            $linha->meio_pagamento,
            $linha->parcelamento_agrupado,
            $linha->bandeira,
            $linha->escrow,
            $linha->solucao,
        );
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
        $comissaoDoPlano = ComissaoAdminSql::lookupPercentualPorChave();

        $query = DB::table('edi_movimentos as em')
            ->leftJoin('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->leftJoinSub($comissaoDoPlano, 'pc', function ($join) {
                $join->on('pc.plano_id', '=', 'e.plano_id')
                    ->on('pc.arranjo_ur', '=', 'em.arranjo_ur')
                    ->on('pc.parcelas', '=', DB::raw('COALESCE(NULLIF(em.quantidade_parcela, 0), 1)'));
            })
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->whereNotNull('em.estabelecimento_id')
            ->select([
                'em.id',
                'em.tipo_transacao',
                'em.meio_pagamento',
                'em.arranjo_ur',
                'em.quantidade_parcela',
                'em.instituicao_financeira',
                'em.meio_captura',
                'em.canal_entrada',
                'em.leitor',
                'em.pagamento_prazo',
                'em.plano',
                'em.valor_total_transacao',
                'em.comissao_valor',
                'pc.comissao_percentual',
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
            ]);

        $grupos = [];

        foreach ($query->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            $chave = ConciliacaoDimensao::chaveConfrontoDaLinha(
                $idCliente,
                ConciliacaoDimensao::meioDoEdi(
                    $mov->tipo_transacao,
                    $mov->meio_pagamento,
                    $mov->arranjo_ur,
                    $mov->quantidade_parcela,
                ),
                ConciliacaoDimensao::parcelamentoDoEdi($mov->quantidade_parcela),
                ConciliacaoDimensao::bandeiraDoEdi($mov->instituicao_financeira, $mov->tipo_transacao, $mov->arranjo_ur),
                ConciliacaoDimensao::escrowDoEdi($mov->pagamento_prazo, $mov->plano),
                ConciliacaoDimensao::solucaoDoEdi($mov->meio_captura, $mov->canal_entrada, $mov->leitor),
            );

            if (! isset($grupos[$chave])) {
                $grupos[$chave] = ['tpv' => 0.0, 'comissao' => 0.0, 'qtd' => 0];
            }

            $valor = (float) $mov->valor_total_transacao;
            $comissao = $mov->comissao_valor !== null
                ? (float) $mov->comissao_valor
                : $valor * (float) ($mov->comissao_percentual ?? 0) / 100;

            $grupos[$chave]['tpv'] += $valor;
            $grupos[$chave]['comissao'] += $comissao;
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
     *     pagseguro_clientes: int,
     *     tpv_so_relatorio: float,
     *     comissao_so_relatorio: float,
     *     por_status: array<string, array{linhas: int, tpv: float, comissao: float, edi_tpv: float, edi_comissao: float}>
     * }
     */
    public function resumoMensal(Conciliacao $conciliacao): array
    {
        $vazio = ['linhas' => 0, 'tpv' => 0.0, 'comissao' => 0.0, 'edi_tpv' => 0.0, 'edi_comissao' => 0.0];
        $porStatus = [
            'ok' => $vazio,
            'divergente' => $vazio,
            'sem_edi' => $vazio,
            'sem_estabelecimento' => $vazio,
            'pendente' => $vazio,
        ];

        $rows = ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->selectRaw('status, COUNT(*) as linhas, SUM(tpv) as tpv, SUM(ms_comissao) as comissao, SUM(COALESCE(edi_tpv, 0)) as edi_tpv, SUM(COALESCE(edi_comissao, 0)) as edi_comissao')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $porStatus[(string) $row->status] = [
                'linhas' => (int) $row->linhas,
                'tpv' => (float) $row->tpv,
                'comissao' => (float) $row->comissao,
                'edi_tpv' => (float) $row->edi_tpv,
                'edi_comissao' => (float) $row->edi_comissao,
            ];
        }

        $pagseguroTpv = (float) $conciliacao->total_tpv;
        $pagseguroComissao = (float) $conciliacao->total_comissao;
        $ediTpv = (float) $conciliacao->linhas()->sum('edi_tpv');
        $ediComissao = (float) $conciliacao->linhas()->sum('edi_comissao');

        return [
            'pagseguro_tpv' => $pagseguroTpv,
            'pagseguro_comissao' => $pagseguroComissao,
            'pagseguro_clientes' => (int) $conciliacao->total_clientes,
            'edi_tpv' => $ediTpv,
            'edi_comissao' => $ediComissao,
            'edi_clientes' => (int) $conciliacao->linhas()
                ->whereNotNull('estabelecimento_id')
                ->distinct('id_cliente')
                ->count('id_cliente'),
            'tpv_so_relatorio' => round($pagseguroTpv - $ediTpv, 2),
            'comissao_so_relatorio' => round($pagseguroComissao - $ediComissao, 4),
            'por_status' => $porStatus,
        ];
    }
}
