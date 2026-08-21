<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Models\Estabelecimento;
use App\Support\ComissaoAdminSql;
use App\Support\ConciliacaoDimensao;
use Illuminate\Database\Eloquent\Builder;
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
                $totaisPorChave[$chave] = 0.0;
            }

            $totaisPorChave[$chave] += (float) $linha->tpv;
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
                $grupoTpv = (float) ($totaisPorChave[$chave] ?? 0.0);
                $tpvLinha = (float) $linha->tpv;
                $comissaoPlanilha = (float) $linha->ms_comissao;

                $ediTpv = $edi !== null ? (float) $edi['tpv'] : 0.0;
                $mesmoVolume = $edi !== null && self::tpvCompativel($grupoTpv, $ediTpv);

                // TPV diferente = outra transação: a planilha fica sem EDI e o
                // volume do EDI aparece no recorte inverso / só no EDI.
                if ($edi === null || ! $mesmoVolume) {
                    $semEdi++;
                    $lote[] = [
                        'id' => (int) $linha->id,
                        'status' => 'sem_edi',
                        'edi_tpv' => 0,
                        'edi_comissao' => 0,
                        'edi_qtd' => 0,
                        'diff_tpv' => round($tpvLinha, 2),
                        'diff_comissao' => round($comissaoPlanilha, 4),
                    ];

                    continue;
                }

                $ok++;
                $ratioTpv = $grupoTpv > 0 ? $tpvLinha / $grupoTpv : 0.0;
                $ediTpvLinha = round($ediTpv * $ratioTpv, 2);
                $ediComissaoLinha = self::comissaoPlanilhaNoTpvEdi($comissaoPlanilha, $tpvLinha, $ediTpvLinha);

                $lote[] = [
                    'id' => (int) $linha->id,
                    'status' => 'ok',
                    'edi_tpv' => $ediTpvLinha,
                    'edi_comissao' => $ediComissaoLinha,
                    'edi_qtd' => (int) round($edi['qtd'] * $ratioTpv),
                    'diff_tpv' => round($tpvLinha - $ediTpvLinha, 2),
                    'diff_comissao' => round($comissaoPlanilha - $ediComissaoLinha, 4),
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
     * Comissão da planilha proporcional ao TPV encontrado no EDI.
     * Nunca usa a grade do plano — a fonte é o que o PagSeguro pagou (MS Comissão).
     */
    public static function comissaoPlanilhaNoTpvEdi(float $msComissao, float $tpvPlanilha, float $tpvEdi): float
    {
        if ($tpvPlanilha <= 0) {
            return 0.0;
        }

        return round($msComissao * $tpvEdi / $tpvPlanilha, 4);
    }

    /**
     * Rateia o total do grupo pela participação da linha.
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
     * @param  list<string>  $idClientes
     * @return Collection<string, array{tpv: float, qtd: int, comissao: float, id_cliente: string, estabelecimento_id: mixed, meio: string, parcelamento: string, bandeira: string, escrow: string, solucao: string}>
     */
    private function agregarEdi(string $inicio, string $fim, array $idClientes = []): Collection
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
            ->when($idClientes !== [], function ($q) use ($idClientes) {
                $q->where(function ($sub) use ($idClientes) {
                    $sub->whereIn('e.token_pagseguro', $idClientes)
                        ->orWhereIn('em.estabelecimento', $idClientes)
                        ->orWhereIn('em.id_cliente', $idClientes)
                        ->orWhereIn('e.id', array_filter($idClientes, 'ctype_digit'));
                });
            })
            ->select([
                'em.id',
                'em.estabelecimento_id',
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
                'pc.comissao_percentual',
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
            ]);

        $grupos = [];

        foreach ($query->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            $meio = ConciliacaoDimensao::meioDoEdi(
                $mov->tipo_transacao,
                $mov->meio_pagamento,
                $mov->arranjo_ur,
                $mov->quantidade_parcela,
            );
            $parcelamento = ConciliacaoDimensao::parcelamentoDoEdi($mov->quantidade_parcela);
            $bandeira = ConciliacaoDimensao::bandeiraDoEdi($mov->instituicao_financeira, $mov->tipo_transacao, $mov->arranjo_ur);
            $escrow = ConciliacaoDimensao::escrowDoEdi($mov->pagamento_prazo, $mov->plano);
            $solucao = ConciliacaoDimensao::solucaoDoEdi($mov->meio_captura, $mov->canal_entrada, $mov->leitor);

            $chave = ConciliacaoDimensao::chaveConfrontoDaLinha(
                $idCliente,
                $meio,
                $parcelamento,
                $bandeira,
                $escrow,
                $solucao,
            );

            if (! isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'tpv' => 0.0,
                    'qtd' => 0,
                    'comissao' => 0.0,
                    'id_cliente' => $idCliente,
                    'estabelecimento_id' => $mov->estabelecimento_id,
                    'meio' => $meio,
                    'parcelamento' => $parcelamento,
                    'bandeira' => $bandeira,
                    'escrow' => $escrow,
                    'solucao' => $solucao,
                ];
            }

            $valor = (float) $mov->valor_total_transacao;
            $grupos[$chave]['tpv'] += $valor;
            $grupos[$chave]['comissao'] += $valor * (float) ($mov->comissao_percentual ?? 0) / 100;
            $grupos[$chave]['qtd']++;
        }

        return collect($grupos)->map(fn (array $item) => [
            'tpv' => round($item['tpv'], 2),
            'qtd' => $item['qtd'],
            'comissao' => round($item['comissao'], 4),
            'id_cliente' => $item['id_cliente'],
            'estabelecimento_id' => $item['estabelecimento_id'],
            'meio' => $item['meio'],
            'parcelamento' => $item['parcelamento'],
            'bandeira' => $item['bandeira'],
            'escrow' => $item['escrow'],
            'solucao' => $item['solucao'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function queryLinhas(Conciliacao $conciliacao, array $filtros = []): Builder
    {
        $query = ConciliacaoLinha::query()
            ->where('conciliacao_linhas.conciliacao_id', $conciliacao->id);

        if ($this->temFiltroEstabelecimento($filtros)) {
            $query->leftJoin('estabelecimentos as e', 'e.id', '=', 'conciliacao_linhas.estabelecimento_id')
                ->select('conciliacao_linhas.*');
        }

        $status = trim((string) ($filtros['status'] ?? ''));
        if ($status !== '' && $status !== 'so_edi') {
            $query->where('conciliacao_linhas.status', $status);
        } elseif ($status === 'so_edi') {
            $query->whereRaw('0 = 1');
        }

        $idEstab = trim((string) ($filtros['estabelecimento_id'] ?? $filtros['id_cliente'] ?? ''));
        if ($idEstab !== '') {
            $tokens = $this->resolverIdentificadoresCliente($idEstab);
            $idsNumericos = array_values(array_filter($tokens, 'ctype_digit'));

            $query->where(function (Builder $q) use ($tokens, $idsNumericos) {
                $q->whereIn('conciliacao_linhas.id_cliente', $tokens)
                    ->orWhereIn('e.token_pagseguro', $tokens);

                if ($idsNumericos !== []) {
                    $q->orWhereIn('conciliacao_linhas.estabelecimento_id', $idsNumericos)
                        ->orWhereIn('e.id', $idsNumericos);
                }
            });
        }

        $nome = trim((string) ($filtros['nome'] ?? ''));
        if ($nome !== '') {
            $like = '%'.$nome.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('e.nome_fantasia', 'like', $like)
                    ->orWhere('e.razao_social', 'like', $like)
                    ->orWhere('e.nome_completo', 'like', $like);
            });
        }

        if (filled($filtros['marketplace_id'] ?? null)) {
            $query->where('e.marketplace_id', (int) $filtros['marketplace_id']);
        }

        if (filled($filtros['revenda_id'] ?? null)) {
            $query->where('e.revenda_id', (int) $filtros['revenda_id']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function identificadorEcUnico(array $filtros): ?string
    {
        $id = trim((string) ($filtros['estabelecimento_id'] ?? $filtros['id_cliente'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        if (! $this->temFiltroEstabelecimento($filtros)) {
            return null;
        }

        $estabs = $this->estabelecimentosDosFiltros($filtros);
        if ($estabs === null || $estabs->count() !== 1) {
            return null;
        }

        $estab = $estabs->first();

        return filled($estab->token_pagseguro) ? (string) $estab->token_pagseguro : (string) $estab->id;
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function temFiltroEstabelecimento(array $filtros): bool
    {
        return filled($filtros['nome'] ?? null)
            || filled($filtros['estabelecimento_id'] ?? null)
            || filled($filtros['id_cliente'] ?? null)
            || filled($filtros['marketplace_id'] ?? null)
            || filled($filtros['revenda_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, Estabelecimento>|null
     */
    public function estabelecimentosDosFiltros(array $filtros): ?Collection
    {
        if (! $this->temFiltroEstabelecimento($filtros)) {
            return null;
        }

        $query = Estabelecimento::withoutGlobalScopes();

        $idEstab = trim((string) ($filtros['estabelecimento_id'] ?? $filtros['id_cliente'] ?? ''));
        if ($idEstab !== '') {
            $tokens = $this->resolverIdentificadoresCliente($idEstab);
            $idsNumericos = array_values(array_filter($tokens, 'ctype_digit'));
            $query->where(function ($q) use ($tokens, $idsNumericos) {
                $q->whereIn('token_pagseguro', $tokens);
                if ($idsNumericos !== []) {
                    $q->orWhereIn('id', $idsNumericos);
                }
            });
        }

        $nome = trim((string) ($filtros['nome'] ?? ''));
        if ($nome !== '') {
            $like = '%'.$nome.'%';
            $query->where(function ($q) use ($like) {
                $q->where('nome_fantasia', 'like', $like)
                    ->orWhere('razao_social', 'like', $like)
                    ->orWhere('nome_completo', 'like', $like);
            });
        }

        if (filled($filtros['marketplace_id'] ?? null)) {
            $query->where('marketplace_id', (int) $filtros['marketplace_id']);
        }

        if (filled($filtros['revenda_id'] ?? null)) {
            $query->where('revenda_id', (int) $filtros['revenda_id']);
        }

        return $query->get(['id', 'token_pagseguro', 'nome_fantasia', 'razao_social', 'nome_completo']);
    }

    /**
     * Volume do EDI do mês que não aparece na planilha PagSeguro:
     * chaves sem linha correspondente, ou TPV a mais na mesma chave.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{so_edi: Collection, extra_edi: Collection}
     */
    public function recorteInversoEdi(Conciliacao $conciliacao, array $filtros = []): array
    {
        $grupos = $this->agruparRecorteInverso($conciliacao, $filtros);

        return [
            'so_edi' => $this->hidratarRecorteEdi($grupos['so_edi']),
            'extra_edi' => $this->hidratarRecorteEdi($grupos['extra_edi']),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{linhas: int, clientes: int, tpv: float, comissao: float}
     */
    public function resumoSoEdi(Conciliacao $conciliacao, array $filtros = []): array
    {
        $soEdi = $this->agruparRecorteInverso($conciliacao, $filtros)['so_edi'];

        return [
            'linhas' => (int) array_sum(array_column($soEdi, 'linhas')),
            'clientes' => count($soEdi),
            'tpv' => round((float) array_sum(array_column($soEdi, 'tpv')), 2),
            'comissao' => round((float) array_sum(array_column($soEdi, 'comissao')), 4),
        ];
    }

    /**
     * Relatório completo de um EC: OK, divergente, só planilha e só EDI.
     *
     * @return array{linhas: Collection, totais: array<string, array{linhas: int, tpv_ps: float, tpv_edi: float, comissao_ps: float, comissao_edi: float}>, estabelecimento: ?Estabelecimento}
     */
    public function detalheCliente(Conciliacao $conciliacao, string $identificador): array
    {
        $tokens = $this->resolverIdentificadoresCliente($identificador);
        $vazioTotais = ['linhas' => 0, 'tpv_ps' => 0.0, 'tpv_edi' => 0.0, 'comissao_ps' => 0.0, 'comissao_edi' => 0.0];
        $totais = [
            'ok' => $vazioTotais,
            'divergente' => $vazioTotais,
            'sem_edi' => $vazioTotais,
            'so_edi' => $vazioTotais,
            'sem_estabelecimento' => $vazioTotais,
            'geral' => $vazioTotais,
        ];

        $linhasPs = ConciliacaoLinha::query()
            ->with('estabelecimento:id,nome_fantasia,razao_social,nome_completo,token_pagseguro')
            ->where('conciliacao_id', $conciliacao->id)
            ->whereIn('id_cliente', $tokens)
            ->orderBy('status')
            ->orderByDesc('tpv')
            ->get();

        $tpvPorChave = [];
        foreach ($linhasPs as $linha) {
            $chave = $this->chaveDaLinha($linha);
            $tpvPorChave[$chave] = ($tpvPorChave[$chave] ?? 0.0) + (float) $linha->tpv;
        }

        $inicio = $conciliacao->referencia_mes?->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes?->copy()->endOfMonth()->toDateString();
        $ediGrupos = ($inicio && $fim) ? $this->agregarEdi($inicio, $fim, $tokens) : collect();
        $chavesPareadas = $this->chavesPareadas($tpvPorChave, $ediGrupos);

        $linhas = collect();

        foreach ($linhasPs as $linha) {
            $chave = $this->chaveDaLinha($linha);
            $pareada = isset($chavesPareadas[$chave]);
            $detalhe = $this->linhaDetalheDaPlanilha($linha, $pareada);
            $linhas->push($detalhe);
            $this->acumularTotaisDetalhe(
                $totais,
                $detalhe->status,
                (float) $detalhe->tpv,
                (float) $detalhe->edi_tpv,
                (float) $detalhe->ms_comissao,
                (float) $detalhe->edi_comissao,
            );
        }

        foreach ($ediGrupos as $chave => $edi) {
            if (isset($chavesPareadas[$chave])) {
                continue;
            }

            $linhas->push((object) [
                'status' => 'so_edi',
                'id_cliente' => $edi['id_cliente'],
                'estabelecimento_id' => $edi['estabelecimento_id'],
                'meio_pagamento' => $edi['meio'],
                'bandeira' => $edi['bandeira'],
                'parcelamento_agrupado' => $edi['parcelamento'],
                'solucao' => $edi['solucao'],
                'tpv' => 0.0,
                'edi_tpv' => $edi['tpv'],
                'ms_comissao' => 0.0,
                'edi_comissao' => $edi['comissao'],
                'diff_tpv' => round(0 - (float) $edi['tpv'], 2),
                'diff_comissao' => round(0 - (float) $edi['comissao'], 4),
                'edi_qtd' => $edi['qtd'],
                'estabelecimento' => null,
            ]);
            $this->acumularTotaisDetalhe($totais, 'so_edi', 0.0, (float) $edi['tpv'], 0.0, (float) $edi['comissao']);
        }

        $ids = $linhas->pluck('estabelecimento_id')->filter()->unique()->all();
        if ($linhasPs->isNotEmpty()) {
            $ids = array_unique(array_merge($ids, $linhasPs->pluck('estabelecimento_id')->filter()->all()));
        }

        $estabelecimentos = $ids === []
            ? collect()
            : Estabelecimento::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->get(['id', 'nome_fantasia', 'razao_social', 'nome_completo', 'token_pagseguro'])
                ->keyBy('id');

        foreach ($linhas as $linha) {
            if ($linha->estabelecimento) {
                continue;
            }
            $linha->estabelecimento = $estabelecimentos->get($linha->estabelecimento_id)
                ?? $linhasPs->first()?->estabelecimento;
        }

        $ordem = ['ok' => 0, 'divergente' => 1, 'sem_edi' => 2, 'so_edi' => 3, 'sem_estabelecimento' => 4, 'pendente' => 5];
        $linhas = $linhas
            ->sortBy(fn ($linha) => sprintf(
                '%d-%020.2f',
                $ordem[$linha->status] ?? 9,
                -((float) $linha->tpv + (float) $linha->edi_tpv),
            ))
            ->values();

        return [
            'linhas' => $linhas,
            'totais' => $totais,
            'estabelecimento' => $linhas->first()?->estabelecimento ?? $linhasPs->first()?->estabelecimento,
        ];
    }

    /**
     * @return list<string>
     */
    public function resolverIdentificadoresCliente(string $identificador): array
    {
        $valor = trim($identificador);
        $tokens = [$valor];

        $query = Estabelecimento::withoutGlobalScopes()
            ->where('token_pagseguro', $valor);

        if (ctype_digit($valor)) {
            $query->orWhere('id', $valor);
        }

        $encontrados = $query->get(['id', 'token_pagseguro']);

        foreach ($encontrados as $estab) {
            $tokens[] = (string) $estab->id;
            if (filled($estab->token_pagseguro)) {
                $tokens[] = (string) $estab->token_pagseguro;
            }
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * @param  array<string, float>  $tpvPlanilha
     * @param  Collection<string, array{tpv: float}>  $agregados
     * @return array<string, true>
     */
    private function chavesPareadas(array $tpvPlanilha, Collection $agregados): array
    {
        $pareadas = [];

        foreach ($agregados as $chave => $edi) {
            if (! isset($tpvPlanilha[$chave])) {
                continue;
            }

            if (self::tpvCompativel((float) $tpvPlanilha[$chave], (float) $edi['tpv'])) {
                $pareadas[$chave] = true;
            }
        }

        return $pareadas;
    }

    private function linhaDetalheDaPlanilha(ConciliacaoLinha $linha, bool $pareada): object
    {
        $status = $linha->status;

        if ($status !== 'sem_estabelecimento' && $status !== 'pendente' && ! $pareada) {
            $status = 'sem_edi';
        }

        $ediTpv = $pareada && $linha->edi_tpv !== null ? (float) $linha->edi_tpv : 0.0;
        $ediComissao = $pareada && $linha->edi_comissao !== null ? (float) $linha->edi_comissao : 0.0;

        return (object) [
            'status' => $status,
            'id_cliente' => $linha->id_cliente,
            'estabelecimento_id' => $linha->estabelecimento_id,
            'meio_pagamento' => $linha->meio_pagamento,
            'bandeira' => $linha->bandeira,
            'parcelamento_agrupado' => $linha->parcelamento_agrupado,
            'solucao' => $linha->solucao,
            'tpv' => (float) $linha->tpv,
            'edi_tpv' => $ediTpv,
            'ms_comissao' => (float) $linha->ms_comissao,
            'edi_comissao' => $ediComissao,
            'diff_tpv' => $pareada ? (float) ($linha->diff_tpv ?? 0) : round((float) $linha->tpv, 2),
            'diff_comissao' => $pareada ? (float) ($linha->diff_comissao ?? 0) : round((float) $linha->ms_comissao, 4),
            'edi_qtd' => $pareada ? $linha->edi_qtd : 0,
            'estabelecimento' => $linha->estabelecimento,
        ];
    }

    /**
     * @param  array<string, array{linhas: int, tpv_ps: float, tpv_edi: float, comissao_ps: float, comissao_edi: float}>  $totais
     */
    private function acumularTotaisDetalhe(array &$totais, string $status, float $tpvPs, float $tpvEdi, float $comPs, float $comEdi): void
    {
        if (! isset($totais[$status])) {
            $status = 'pendente';
            $totais[$status] ??= ['linhas' => 0, 'tpv_ps' => 0.0, 'tpv_edi' => 0.0, 'comissao_ps' => 0.0, 'comissao_edi' => 0.0];
        }

        foreach ([$status, 'geral'] as $chave) {
            $totais[$chave]['linhas']++;
            $totais[$chave]['tpv_ps'] += $tpvPs;
            $totais[$chave]['tpv_edi'] += $tpvEdi;
            $totais[$chave]['comissao_ps'] += $comPs;
            $totais[$chave]['comissao_edi'] += $comEdi;
        }
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array{so_edi: array<string, array>, extra_edi: array<string, array>}
     */
    private function agruparRecorteInverso(Conciliacao $conciliacao, array $filtros = []): array
    {
        $vazio = ['so_edi' => [], 'extra_edi' => []];

        if (! $conciliacao->referencia_mes) {
            return $vazio;
        }

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();
        $agregados = $this->agregarEdi($inicio, $fim, $this->escopoEdiDosFiltros($filtros));

        $planilha = [];
        $filtrosPlanilha = $filtros;
        unset($filtrosPlanilha['status']);

        foreach ($this->queryLinhas($conciliacao, $filtrosPlanilha)->orderBy('conciliacao_linhas.id')->cursor() as $linha) {
            $chave = $this->chaveDaLinha($linha);

            if (! isset($planilha[$chave])) {
                $planilha[$chave] = 0.0;
            }

            $planilha[$chave] += (float) $linha->tpv;
        }

        $soEdi = [];
        $extraEdi = [];

        $chavesPareadas = $this->chavesPareadas($planilha, $agregados);

        foreach ($agregados as $chave => $edi) {
            $grupoChave = (string) ($edi['estabelecimento_id'] ?: $edi['id_cliente']);

            if (isset($chavesPareadas[$chave])) {
                continue;
            }

            $this->acumularRecorteEdi($soEdi, $grupoChave, $edi, (float) $edi['tpv']);
        }

        return [
            'so_edi' => $soEdi,
            'extra_edi' => $extraEdi,
        ];
    }

    /**
     * @param  array<string, array{id_cliente: string, estabelecimento_id: mixed, linhas: int, vendas: int, tpv: float, comissao: float}>  $grupos
     * @param  array{tpv: float, qtd: int, comissao: float, id_cliente: string, estabelecimento_id: mixed}  $edi
     */
    private function acumularRecorteEdi(array &$grupos, string $grupoChave, array $edi, float $tpv): void
    {
        if (! isset($grupos[$grupoChave])) {
            $grupos[$grupoChave] = [
                'id_cliente' => $edi['id_cliente'],
                'estabelecimento_id' => $edi['estabelecimento_id'],
                'linhas' => 0,
                'vendas' => 0,
                'tpv' => 0.0,
                'comissao' => 0.0,
            ];
        }

        $tpvGrupo = (float) $edi['tpv'];
        $ratio = $tpvGrupo > 0 ? $tpv / $tpvGrupo : 0.0;

        $grupos[$grupoChave]['linhas']++;
        $grupos[$grupoChave]['vendas'] += (int) $edi['qtd'];
        $grupos[$grupoChave]['tpv'] += $tpv;
        $grupos[$grupoChave]['comissao'] += (float) $edi['comissao'] * $ratio;
    }

    /**
     * @param  array<string, array{id_cliente: string, estabelecimento_id: mixed, linhas: int, vendas: int, tpv: float, comissao: float}>  $grupos
     */
    private function hidratarRecorteEdi(array $grupos): Collection
    {
        $linhas = collect($grupos)
            ->map(fn (array $item) => (object) [
                'id_cliente' => $item['id_cliente'],
                'estabelecimento_id' => $item['estabelecimento_id'],
                'linhas' => $item['linhas'],
                'vendas' => $item['vendas'],
                'tpv' => round($item['tpv'], 2),
                'comissao' => round($item['comissao'], 4),
            ])
            ->sortByDesc('tpv')
            ->values();

        $ids = $linhas->pluck('estabelecimento_id')->filter()->unique()->all();

        if ($ids === []) {
            return $linhas;
        }

        $estabelecimentos = Estabelecimento::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get(['id', 'nome_fantasia', 'razao_social', 'nome_completo', 'token_pagseguro'])
            ->keyBy('id');

        foreach ($linhas as $linha) {
            $linha->estabelecimento = $estabelecimentos->get($linha->estabelecimento_id);
        }

        return $linhas;
    }

    /**
     * @return array{
     *     com_estabelecimento: array{linhas: int, clientes: int, tpv: float, comissao: float},
     *     sem_estabelecimento: array{linhas: int, clientes: int, tpv: float, comissao: float}
     * }
     */
    public function resumoEstabelecimentos(Conciliacao $conciliacao, array $filtros = []): array
    {
        $vazio = ['linhas' => 0, 'clientes' => 0, 'tpv' => 0.0, 'comissao' => 0.0];
        $resumo = [
            'com_estabelecimento' => $vazio,
            'sem_estabelecimento' => $vazio,
        ];

        $filtrosCards = $filtros;
        unset($filtrosCards['status']);

        $rows = $this->clonarSemSelect($this->queryLinhas($conciliacao, $filtrosCards))
            ->selectRaw('conciliacao_linhas.sem_estabelecimento, COUNT(*) as linhas, COUNT(DISTINCT conciliacao_linhas.id_cliente) as clientes, SUM(conciliacao_linhas.tpv) as tpv, SUM(conciliacao_linhas.ms_comissao) as comissao')
            ->groupBy('conciliacao_linhas.sem_estabelecimento')
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
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function estabelecimentosSemEdi(Conciliacao $conciliacao): Collection
    {
        $linhas = ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->where('status', 'sem_edi')
            ->selectRaw('id_cliente, estabelecimento_id, COUNT(*) as linhas, SUM(tpv) as tpv, SUM(ms_comissao) as comissao')
            ->groupBy('id_cliente', 'estabelecimento_id')
            ->orderByDesc('tpv')
            ->get();

        $linhas->load('estabelecimento:id,nome_fantasia,razao_social,nome_completo,token_pagseguro');

        return $linhas;
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
    public function resumoMensal(Conciliacao $conciliacao, array $filtros = []): array
    {
        $vazio = ['linhas' => 0, 'tpv' => 0.0, 'comissao' => 0.0, 'edi_tpv' => 0.0, 'edi_comissao' => 0.0];
        $porStatus = [
            'ok' => $vazio,
            'divergente' => $vazio,
            'sem_edi' => $vazio,
            'sem_estabelecimento' => $vazio,
            'pendente' => $vazio,
        ];

        $filtrosCards = $filtros;
        unset($filtrosCards['status']);
        $query = $this->queryLinhas($conciliacao, $filtrosCards);

        $rows = $this->clonarSemSelect($query)
            ->selectRaw('conciliacao_linhas.status, COUNT(*) as linhas, SUM(conciliacao_linhas.tpv) as tpv, SUM(conciliacao_linhas.ms_comissao) as comissao, SUM(COALESCE(conciliacao_linhas.edi_tpv, 0)) as edi_tpv, SUM(COALESCE(conciliacao_linhas.edi_comissao, 0)) as edi_comissao')
            ->groupBy('conciliacao_linhas.status')
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

        $totais = $this->clonarSemSelect($query)
            ->selectRaw('SUM(conciliacao_linhas.tpv) as pagseguro_tpv, SUM(conciliacao_linhas.ms_comissao) as pagseguro_comissao, COUNT(DISTINCT conciliacao_linhas.id_cliente) as pagseguro_clientes, SUM(COALESCE(conciliacao_linhas.edi_tpv, 0)) as edi_tpv, SUM(COALESCE(conciliacao_linhas.edi_comissao, 0)) as edi_comissao, COUNT(DISTINCT CASE WHEN conciliacao_linhas.estabelecimento_id IS NOT NULL THEN conciliacao_linhas.id_cliente END) as edi_clientes')
            ->first();

        $pagseguroTpv = (float) ($totais->pagseguro_tpv ?? 0);
        $pagseguroComissao = (float) ($totais->pagseguro_comissao ?? 0);
        $ediTpv = (float) ($totais->edi_tpv ?? 0);
        $ediComissao = (float) ($totais->edi_comissao ?? 0);

        return [
            'pagseguro_tpv' => $pagseguroTpv,
            'pagseguro_comissao' => $pagseguroComissao,
            'pagseguro_clientes' => (int) ($totais->pagseguro_clientes ?? 0),
            'edi_tpv' => $ediTpv,
            'edi_comissao' => $ediComissao,
            'edi_clientes' => (int) ($totais->edi_clientes ?? 0),
            'tpv_so_relatorio' => round($pagseguroTpv - $ediTpv, 2),
            'comissao_so_relatorio' => round($pagseguroComissao - $ediComissao, 4),
            'por_status' => $porStatus,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return list<string>
     */
    private function escopoEdiDosFiltros(array $filtros): array
    {
        if (! $this->temFiltroEstabelecimento($filtros)) {
            return [];
        }

        $tokens = [];
        $idEstab = trim((string) ($filtros['estabelecimento_id'] ?? $filtros['id_cliente'] ?? ''));
        if ($idEstab !== '') {
            $tokens = $this->resolverIdentificadoresCliente($idEstab);
        }

        $estabs = $this->estabelecimentosDosFiltros($filtros) ?? collect();
        foreach ($estabs as $estab) {
            $tokens[] = (string) $estab->id;
            if (filled($estab->token_pagseguro)) {
                $tokens[] = (string) $estab->token_pagseguro;
            }
        }

        $tokens = array_values(array_unique(array_filter($tokens)));

        return $tokens === [] ? ['__nenhum__'] : $tokens;
    }

    private function clonarSemSelect(Builder $query): Builder
    {
        $clone = clone $query;
        $clone->getQuery()->columns = null;
        $clone->getQuery()->orders = null;

        return $clone;
    }
}
