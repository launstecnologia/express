<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Models\Estabelecimento;
use App\Models\Usuario;
use App\Support\ComissaoAdminSql;
use App\Support\ConciliacaoDimensao;
use App\Support\DocumentoBrasil;
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
        $planilha = [];

        foreach ($linhas as $linha) {
            if ($linha->sem_estabelecimento) {
                continue;
            }

            $this->acrescentarGrupoPlanilha($planilha, $linha);
        }

        $mapaPareamento = $this->mapaPareamento($planilha, $agregados);

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
                $ediChave = $mapaPareamento[$chave] ?? null;
                $edi = $ediChave !== null ? $agregados->get($ediChave) : null;
                $grupoTpv = (float) ($planilha[$chave]['tpv'] ?? 0.0);
                $tpvLinha = (float) $linha->tpv;
                $comissaoPlanilha = (float) $linha->ms_comissao;
                $exato = $ediChave === $chave;

                $ediTpv = $edi !== null ? (float) $edi['tpv'] : 0.0;

                if ($edi === null) {
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

                $ratioTpv = $grupoTpv > 0 ? $tpvLinha / $grupoTpv : 0.0;
                $ediTpvLinha = round($ediTpv * $ratioTpv, 2);
                $ediComissaoLinha = self::comissaoPlanilhaNoTpvEdi($comissaoPlanilha, $tpvLinha, $ediTpvLinha);
                $mesmoVolume = $exato && self::tpvCompativel($grupoTpv, $ediTpv);

                if ($mesmoVolume) {
                    $ok++;
                } else {
                    $divergentes++;
                }

                $lote[] = [
                    'id' => (int) $linha->id,
                    'status' => $mesmoVolume ? 'ok' : 'divergente',
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
                'em.nsu',
                'em.codigo_transacao',
                'em.tx_id',
                'em.data_inicial_transacao',
                'pc.comissao_percentual',
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
            ]);

        $grupos = [];
        $idsVistos = [];
        $vendasVistas = [];

        foreach ($query->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            if ($this->movimentoEdiJaContado($mov, $idsVistos, $vendasVistas)) {
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
     * Relatório completo da conciliação: planilha PagSeguro + EDI sem casar,
     * com coluna de status para filtrar no Excel.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{cabecalhos: list<string>, linhas: iterable<int, list<string|int|float|null>>}
     */
    public function relatorioCompleto(Conciliacao $conciliacao, array $filtros = []): array
    {
        @set_time_limit(900);
        unset($filtros['status']);

        return [
            'cabecalhos' => $this->cabecalhosExcelCompleto(),
            'linhas' => $this->iterarRelatorioCompleto($conciliacao, $filtros),
        ];
    }

    /**
     * Planilha no formato DSPAY: uma aba por marketplace (ID, marketplace,
     * representante, documento, EC, faturamento e markup).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{nome_arquivo: string, planilhas: list<array{nome: string, linhas: list<list<string|int|float|null>>, autoFiltro: bool}>}
     */
    public function planilhaPorMarketplace(Conciliacao $conciliacao, array $filtros = []): array
    {
        @set_time_limit(900);
        unset($filtros['status']);

        $grupos = $this->agregarPorMarketplace($conciliacao, $filtros);

        if ($grupos === []) {
            return [
                'nome_arquivo' => 'planilha-marketplace.xlsx',
                'planilhas' => [],
            ];
        }

        $comissao = app(ComissaoPagService::class);
        $planilhas = [];

        if (count($grupos) > 1) {
            $planilhas[] = [
                'nome' => 'Resumo',
                'autoFiltro' => true,
                'linhas' => $this->linhasResumoMarketplace($grupos, $comissao),
            ];
        }

        foreach ($grupos as $grupo) {
            $planilhas[] = [
                'nome' => $grupo['nome_aba'],
                'autoFiltro' => false,
                'linhas' => $this->linhasAbaMarketplace($grupo, $comissao),
            ];
        }

        return [
            'nome_arquivo' => $this->nomeArquivoMarketplace($conciliacao, $grupos, $comissao),
            'planilhas' => $planilhas,
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return list<array{
     *     marketplace_id: int,
     *     marketplace: ?Usuario,
     *     nome: string,
     *     nome_aba: string,
     *     faturamento: float,
     *     markup: float,
     *     ecs: list<array{id: string, marketplace: string, representante: string, documento: string, nome: string, faturamento: float, markup: float}>
     * }>
     */
    private function agregarPorMarketplace(Conciliacao $conciliacao, array $filtros): array
    {
        $ecs = [];

        foreach ($this->queryLinhasExcel($conciliacao, $filtros)->orderBy('conciliacao_linhas.id')->cursor() as $linha) {
            if ($linha->sem_estabelecimento || ! $linha->estabelecimento_id) {
                continue;
            }

            $ecId = (int) $linha->estabelecimento_id;
            if (! isset($ecs[$ecId])) {
                $ecs[$ecId] = [
                    'estabelecimento_id' => $ecId,
                    'marketplace_id' => (int) ($linha->marketplace_id ?? 0),
                    'id' => (string) ($linha->token_pagseguro ?: $linha->id_cliente ?: $ecId),
                    'marketplace' => (string) ($linha->marketplace_nome ?? ''),
                    'representante' => (string) ($linha->revenda_nome ?? ''),
                    'documento' => DocumentoBrasil::formatarCpfOuCnpj((string) ($linha->estabelecimento_documento ?? '')),
                    'nome' => (string) ($linha->estabelecimento_nome ?: 'Estabelecimento #'.$ecId),
                    'faturamento' => 0.0,
                    'markup' => 0.0,
                ];
            }

            $ecs[$ecId]['faturamento'] += (float) $linha->tpv;
            $ecs[$ecId]['markup'] += (float) $linha->ms_comissao;
        }

        if ($ecs === []) {
            return [];
        }

        $mktIds = collect($ecs)->pluck('marketplace_id')->filter()->unique()->all();
        $marketplaces = $mktIds === []
            ? collect()
            : Usuario::query()->whereIn('id', $mktIds)->get()->keyBy('id');

        $grupos = [];
        foreach ($ecs as $ec) {
            $mktId = (int) $ec['marketplace_id'];
            if (! isset($grupos[$mktId])) {
                $marketplace = $marketplaces->get($mktId);
                $nome = $marketplace?->nomeExibicao() ?: ($ec['marketplace'] !== '' ? $ec['marketplace'] : 'Sem marketplace');
                $grupos[$mktId] = [
                    'marketplace_id' => $mktId,
                    'marketplace' => $marketplace,
                    'nome' => $nome,
                    'nome_aba' => $nome,
                    'faturamento' => 0.0,
                    'markup' => 0.0,
                    'ecs' => [],
                ];
            }

            $ec['faturamento'] = round($ec['faturamento'], 2);
            $ec['markup'] = round($ec['markup'], 4);
            $grupos[$mktId]['faturamento'] += $ec['faturamento'];
            $grupos[$mktId]['markup'] += $ec['markup'];
            $grupos[$mktId]['ecs'][] = $ec;
        }

        foreach ($grupos as &$grupo) {
            $grupo['faturamento'] = round($grupo['faturamento'], 2);
            $grupo['markup'] = round($grupo['markup'], 4);
            usort($grupo['ecs'], fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
        }
        unset($grupo);

        uasort($grupos, function (array $a, array $b) {
            if ($a['marketplace_id'] === 0) {
                return 1;
            }
            if ($b['marketplace_id'] === 0) {
                return -1;
            }

            return strcasecmp($a['nome'], $b['nome']);
        });

        return $this->completarEcsSemVolume(array_values($grupos), $filtros);
    }

    /**
     * Inclui ECs do marketplace sem movimento no mês, como na planilha DSPAY.
     *
     * @param  list<array<string, mixed>>  $grupos
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    private function completarEcsSemVolume(array $grupos, array $filtros): array
    {
        $mktIds = collect($grupos)->pluck('marketplace_id')->filter()->unique()->values()->all();

        if ($mktIds === []) {
            return $grupos;
        }

        $query = Estabelecimento::query()
            ->with(['marketplace', 'revenda'])
            ->whereIn('marketplace_id', $mktIds);

        if (filled($filtros['revenda_id'] ?? null)) {
            $query->where('revenda_id', (int) $filtros['revenda_id']);
        }

        $indicePorMkt = [];
        $presentes = [];

        foreach ($grupos as $indice => $grupo) {
            $mktId = (int) $grupo['marketplace_id'];
            $indicePorMkt[$mktId] = $indice;
            $presentes[$mktId] = [];

            foreach ($grupo['ecs'] as $ec) {
                $presentes[$mktId][(int) ($ec['estabelecimento_id'] ?? 0)] = true;
            }
        }

        foreach ($query->get([
            'id', 'marketplace_id', 'revenda_id', 'token_pagseguro',
            'nome_fantasia', 'razao_social', 'nome_completo', 'cnpj', 'cpf',
        ]) as $estab) {
            $mktId = (int) $estab->marketplace_id;

            if (! isset($indicePorMkt[$mktId], $presentes[$mktId])) {
                continue;
            }

            if (isset($presentes[$mktId][(int) $estab->id])) {
                continue;
            }

            $indice = $indicePorMkt[$mktId];
            $grupos[$indice]['ecs'][] = [
                'estabelecimento_id' => (int) $estab->id,
                'marketplace_id' => $mktId,
                'id' => (string) ($estab->token_pagseguro ?: $estab->id),
                'marketplace' => $estab->marketplace?->nomeExibicao() ?: $grupos[$indice]['nome'],
                'representante' => $estab->revenda?->nomeExibicao() ?: '',
                'documento' => DocumentoBrasil::formatarCpfOuCnpj((string) ($estab->cnpj ?: $estab->cpf ?: '')),
                'nome' => (string) ($estab->nome_fantasia ?: $estab->razao_social ?: $estab->nome_completo ?: 'Estabelecimento #'.$estab->id),
                'faturamento' => 0.0,
                'markup' => 0.0,
            ];
            $presentes[$mktId][(int) $estab->id] = true;
        }

        foreach ($grupos as &$grupo) {
            usort($grupo['ecs'], fn ($a, $b) => strcasecmp($a['nome'], $b['nome']));
        }
        unset($grupo);

        return $grupos;
    }

    /**
     * @param  list<array<string, mixed>>  $grupos
     * @return list<list<string|int|float|null>>
     */
    private function linhasResumoMarketplace(array $grupos, ComissaoPagService $comissao): array
    {
        $linhas = [[
            'Marketplace',
            'Faturamento',
            'Markup',
            'Retenção',
            'Royalty',
            'Comissão',
            'ECs',
        ]];

        foreach ($grupos as $grupo) {
            $calc = $comissao->comissaoLiquidaParceiro((float) $grupo['markup'], $grupo['marketplace']);
            $linhas[] = [
                $grupo['nome'],
                (float) $grupo['faturamento'],
                (float) $grupo['markup'],
                $calc['percentual'] > 0 ? round($calc['percentual'] / 100, 4) : 0,
                $calc['royalty'],
                $calc['liquida'],
                count($grupo['ecs']),
            ];
        }

        return $linhas;
    }

    /**
     * @param  array<string, mixed>  $grupo
     * @return list<list<string|int|float|null>>
     */
    private function linhasAbaMarketplace(array $grupo, ComissaoPagService $comissao): array
    {
        $calc = $comissao->comissaoLiquidaParceiro((float) $grupo['markup'], $grupo['marketplace']);

        $linhas = [
            [], [], [], [], [], [],
            ['', '', '', 'PAGSEGURO'],
            [], [],
            ['', '', 'FATURAMENTO', 'MARKUP', $calc['percentual'] > 0 ? round($calc['percentual']).'%' : '0%', 'COMISSÃO'],
            ['', '', (float) $grupo['faturamento'], (float) $grupo['markup'], $calc['royalty'], $calc['liquida']],
            ['', 'ID', 'MARKETPLACE', 'REPRESENTANTE', 'CPF/CNPJ-EC', 'NOME EC', 'FATURAMENTO', 'MARKUP'],
        ];

        foreach ($grupo['ecs'] as $ec) {
            $linhas[] = [
                '',
                $ec['id'],
                $ec['marketplace'] !== '' ? $ec['marketplace'] : $grupo['nome'],
                $ec['representante'],
                $ec['documento'],
                $ec['nome'],
                $ec['faturamento'] ?: '',
                $ec['markup'] ?: '',
            ];
        }

        return $linhas;
    }

    /**
     * @param  list<array<string, mixed>>  $grupos
     */
    private function nomeArquivoMarketplace(Conciliacao $conciliacao, array $grupos, ComissaoPagService $comissao): string
    {
        $mes = $conciliacao->referencia_mes?->format('Y-m') ?? 'conciliacao';

        if (count($grupos) !== 1) {
            return "planilha-marketplace-{$mes}.xlsx";
        }

        $grupo = $grupos[0];
        $calc = $comissao->comissaoLiquidaParceiro((float) $grupo['markup'], $grupo['marketplace']);
        $nome = preg_replace('/[^\pL\pN\s\-\.]+/u', '', (string) $grupo['nome']) ?: 'marketplace';
        $valor = number_format((float) $calc['liquida'], 2, ',', '.');

        return trim($nome).' R$ '.$valor.'.xlsx';
    }

    /**
     * Transações EDI do mês cuja chave não casou com a planilha PagSeguro.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{cabecalhos: list<string>, linhas: iterable<int, list<string|int|float|null>>}
     */
    public function transacoesSoEdi(Conciliacao $conciliacao, array $filtros = []): array
    {
        @set_time_limit(900);

        $cabecalhos = $this->cabecalhosExcelSoEdi();

        if (! $conciliacao->referencia_mes) {
            return ['cabecalhos' => $cabecalhos, 'linhas' => []];
        }

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();
        $chavesSoEdi = $this->chavesEdiNaoPareadas($conciliacao, $filtros);

        if ($chavesSoEdi === []) {
            return ['cabecalhos' => $cabecalhos, 'linhas' => []];
        }

        return [
            'cabecalhos' => $cabecalhos,
            'linhas' => $this->iterarTransacoesSoEdi($inicio, $fim, $filtros, $chavesSoEdi),
        ];
    }

    /**
     * Transações EDI cuja chave não existe na planilha PagSeguro, para exibir na tela.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function coletarTransacoesSoEdi(Conciliacao $conciliacao, array $filtros = []): Collection
    {
        $planilha = $this->transacoesSoEdi($conciliacao, $filtros);
        $linhas = collect();

        foreach ($planilha['linhas'] as $row) {
            $mapa = array_combine($planilha['cabecalhos'], $row) ?: [];

            $linhas->push((object) [
                'id' => (int) ($mapa['EDI ID'] ?? 0),
                'nsu' => (string) ($mapa['NSU'] ?? ''),
                'codigo_autorizacao' => (string) ($mapa['Código autorização'] ?? ''),
                'data' => (string) ($mapa['Data transação'] ?? ''),
                'hora' => (string) ($mapa['Hora transação'] ?? ''),
                'id_cliente' => (string) ($mapa['ID cliente'] ?? ''),
                'estabelecimento_id' => $mapa['Estabelecimento ID'] ?? '',
                'estabelecimento' => (string) ($mapa['Estabelecimento'] ?? ''),
                'marketplace' => (string) ($mapa['Marketplace'] ?? ''),
                'meio' => (string) ($mapa['Meio'] ?? ''),
                'parcelamento' => (string) ($mapa['Parcelamento'] ?? ''),
                'parcela' => (string) ($mapa['Parcela'] ?? ''),
                'quantidade_parcela' => (string) ($mapa['Quantidade parcelas'] ?? ''),
                'bandeira' => (string) ($mapa['Bandeira'] ?? ''),
                'tipo' => (string) ($mapa['Tipo transação'] ?? ''),
                'instituicao' => (string) ($mapa['Instituição financeira'] ?? ''),
                'status' => (string) ($mapa['Status pagamento'] ?? ''),
                'valor' => (float) ($mapa['Valor total'] ?? 0),
            ]);
        }

        return $linhas;
    }

    /**
     * @return list<string>
     */
    private function cabecalhosExcelCompleto(): array
    {
        return [
            'Status',
            'Origem',
            'ID cliente',
            'Estabelecimento ID',
            'Estabelecimento',
            'Documento',
            'Marketplace',
            'Revenda',
            'Meio pagamento',
            'Bandeira',
            'Parcelamento',
            'Escrow',
            'Solução',
            'MCC',
            'TPV PagSeguro',
            'TPV EDI',
            'Diff TPV',
            'Comissão PagSeguro',
            'Comissão EDI',
            'Diff comissão',
            'Qtd EDI',
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return \Generator<int, list<string|int|float|null>>
     */
    private function iterarRelatorioCompleto(Conciliacao $conciliacao, array $filtros): \Generator
    {
        $planilha = [];
        $query = $this->queryLinhasExcel($conciliacao, $filtros);

        foreach ($query->orderBy('conciliacao_linhas.id')->cursor() as $linha) {
            $this->acrescentarGrupoPlanilha($planilha, $linha);

            yield $this->linhaExcelCompleto(
                status: (string) $linha->status,
                origem: 'Planilha PagSeguro',
                idCliente: (string) $linha->id_cliente,
                estabelecimentoId: $linha->estabelecimento_id,
                estabelecimento: (string) ($linha->estabelecimento_nome ?? ''),
                documento: (string) ($linha->estabelecimento_documento ?? ''),
                marketplace: (string) ($linha->marketplace_nome ?? ''),
                revenda: (string) ($linha->revenda_nome ?? ''),
                meio: (string) ($linha->meio_pagamento ?? ''),
                bandeira: (string) ($linha->bandeira ?? ''),
                parcelamento: (string) ($linha->parcelamento_agrupado ?? ''),
                escrow: (string) ($linha->escrow ?? ''),
                solucao: (string) ($linha->solucao ?? ''),
                mcc: (string) ($linha->mcc ?? ''),
                tpvPs: (float) $linha->tpv,
                tpvEdi: $linha->edi_tpv !== null ? (float) $linha->edi_tpv : 0.0,
                diffTpv: $linha->diff_tpv !== null ? (float) $linha->diff_tpv : round((float) $linha->tpv, 2),
                comissaoPs: (float) $linha->ms_comissao,
                comissaoEdi: $linha->edi_comissao !== null ? (float) $linha->edi_comissao : 0.0,
                diffComissao: $linha->diff_comissao !== null ? (float) $linha->diff_comissao : round((float) $linha->ms_comissao, 4),
                qtdEdi: (int) ($linha->edi_qtd ?? 0),
            );
        }

        if (! $conciliacao->referencia_mes) {
            return;
        }

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();
        $agregados = $this->agregarEdi($inicio, $fim, $this->escopoEdiDosFiltros($filtros));
        $ediPareados = array_fill_keys(array_values($this->mapaPareamento($planilha, $agregados)), true);

        $idsEdi = [];
        foreach ($agregados as $chave => $edi) {
            if (isset($ediPareados[$chave])) {
                continue;
            }
            if (filled($edi['estabelecimento_id'] ?? null)) {
                $idsEdi[] = (int) $edi['estabelecimento_id'];
            }
        }

        $ecs = $idsEdi === []
            ? collect()
            : Estabelecimento::withoutGlobalScopes()
                ->with(['marketplace', 'revenda'])
                ->whereIn('id', array_values(array_unique($idsEdi)))
                ->get(['id', 'nome_fantasia', 'razao_social', 'nome_completo', 'cnpj', 'cpf', 'marketplace_id', 'revenda_id'])
                ->keyBy('id');

        foreach ($agregados as $chave => $edi) {
            if (isset($ediPareados[$chave])) {
                continue;
            }

            $ec = $ecs->get($edi['estabelecimento_id'] ?? null);

            yield $this->linhaExcelCompleto(
                status: 'so_edi',
                origem: 'Só no EDI',
                idCliente: (string) ($edi['id_cliente'] ?? ''),
                estabelecimentoId: $edi['estabelecimento_id'] ?? null,
                estabelecimento: $ec
                    ? (string) ($ec->nome_fantasia ?: $ec->razao_social ?: $ec->nome_completo ?: '')
                    : '',
                documento: $ec ? (string) ($ec->cnpj ?: $ec->cpf ?: '') : '',
                marketplace: $ec?->marketplace?->nomeExibicao() ?: '',
                revenda: $ec?->revenda?->nomeExibicao() ?: '',
                meio: (string) ($edi['meio'] ?? ''),
                bandeira: (string) ($edi['bandeira'] ?? ''),
                parcelamento: (string) ($edi['parcelamento'] ?? ''),
                escrow: (string) ($edi['escrow'] ?? ''),
                solucao: (string) ($edi['solucao'] ?? ''),
                mcc: '',
                tpvPs: 0.0,
                tpvEdi: (float) $edi['tpv'],
                diffTpv: round(0 - (float) $edi['tpv'], 2),
                comissaoPs: 0.0,
                comissaoEdi: (float) $edi['comissao'],
                diffComissao: round(0 - (float) $edi['comissao'], 4),
                qtdEdi: (int) ($edi['qtd'] ?? 0),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    private function queryLinhasExcel(Conciliacao $conciliacao, array $filtros): Builder
    {
        $query = $this->queryLinhas($conciliacao, $filtros);

        if (! $this->temFiltroEstabelecimento($filtros)) {
            $query->leftJoin('estabelecimentos as e', 'e.id', '=', 'conciliacao_linhas.estabelecimento_id')
                ->select('conciliacao_linhas.*');
        }

        return $query
            ->leftJoin('usuarios as mkt', 'mkt.id', '=', 'e.marketplace_id')
            ->leftJoin('usuarios as rev', 'rev.id', '=', 'e.revenda_id')
            ->addSelect([
                'e.marketplace_id as marketplace_id',
                'e.token_pagseguro as token_pagseguro',
                DB::raw('COALESCE(e.nome_fantasia, e.razao_social, e.nome_completo) as estabelecimento_nome'),
                DB::raw('COALESCE(e.cnpj, e.cpf) as estabelecimento_documento'),
                DB::raw('COALESCE(mkt.nome_fantasia, mkt.razao_social, mkt.nome_completo, mkt.email) as marketplace_nome'),
                DB::raw('COALESCE(rev.nome_fantasia, rev.razao_social, rev.nome_completo, rev.email) as revenda_nome'),
            ]);
    }

    /**
     * @return list<string|int|float|null>
     */
    private function linhaExcelCompleto(
        string $status,
        string $origem,
        string $idCliente,
        mixed $estabelecimentoId,
        string $estabelecimento,
        string $documento,
        string $marketplace,
        string $revenda,
        string $meio,
        string $bandeira,
        string $parcelamento,
        string $escrow,
        string $solucao,
        string $mcc,
        float $tpvPs,
        float $tpvEdi,
        float $diffTpv,
        float $comissaoPs,
        float $comissaoEdi,
        float $diffComissao,
        int $qtdEdi,
    ): array {
        return [
            $this->statusLabelExcel($status),
            $origem,
            $idCliente,
            $estabelecimentoId !== null && $estabelecimentoId !== '' ? (int) $estabelecimentoId : '',
            $estabelecimento,
            $documento,
            $marketplace,
            $revenda,
            $meio,
            $bandeira,
            $parcelamento,
            $escrow,
            $solucao,
            $mcc,
            round($tpvPs, 2),
            round($tpvEdi, 2),
            round($diffTpv, 2),
            round($comissaoPs, 4),
            round($comissaoEdi, 4),
            round($diffComissao, 4),
            $qtdEdi,
        ];
    }

    private function statusLabelExcel(string $status): string
    {
        return match ($status) {
            'ok' => 'OK',
            'divergente' => 'Divergente',
            'sem_estabelecimento' => 'Sem estabelecimento',
            'sem_edi' => 'Só na planilha',
            'so_edi' => 'Só no EDI',
            default => 'Pendente',
        };
    }

    /**
     * @return list<string>
     */
    private function cabecalhosExcelSoEdi(): array
    {
        return [
            'EDI ID',
            'NSU',
            'Código autorização',
            'Código transação',
            'Código venda',
            'TX ID',
            'Data transação',
            'Hora transação',
            'Data venda/ajuste',
            'Data prevista pagamento',
            'ID cliente',
            'Estabelecimento ID',
            'Estabelecimento',
            'Documento',
            'Marketplace',
            'Revenda',
            'Meio',
            'Parcelamento',
            'Bandeira',
            'Escrow',
            'Solução',
            'Tipo transação',
            'Meio pagamento',
            'Arranjo UR',
            'Instituição financeira',
            'Parcela',
            'Quantidade parcelas',
            'Plano',
            'Pagamento prazo',
            'Meio captura',
            'Canal entrada',
            'Leitor',
            'Nº lógico',
            'Nº série leitor',
            'Status pagamento',
            'Tipo evento',
            'Cartão BIN',
            'Cartão holder',
            'Código CV',
            'Valor total',
            'Valor parcela',
            'Valor original',
            'Valor líquido',
            'Taxa intermediação',
            'Tarifa intermediação',
            'Comissão % (grade)',
            'Comissão valor (grade)',
            'Código movimento API',
            'Estabelecimento EDI',
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @param  array<string, true>  $chavesSoEdi
     * @return \Generator<int, list<string|int|float|null>>
     */
    private function iterarTransacoesSoEdi(string $inicio, string $fim, array $filtros, array $chavesSoEdi): \Generator
    {
        $idClientes = $this->escopoEdiDosFiltros($filtros);
        $comissaoDoPlano = ComissaoAdminSql::lookupPercentualPorChave();

        $query = DB::table('edi_movimentos as em')
            ->leftJoin('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->leftJoin('usuarios as mkt', 'mkt.id', '=', 'e.marketplace_id')
            ->leftJoin('usuarios as rev', 'rev.id', '=', 'e.revenda_id')
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
                'em.nsu',
                'em.codigo_autorizacao',
                'em.codigo_transacao',
                'em.codigo_venda',
                'em.tx_id',
                'em.data_inicial_transacao',
                'em.hora_inicial_transacao',
                'em.data_venda_ajuste',
                'em.data_prevista_pagamento',
                'em.estabelecimento_id',
                'em.tipo_transacao',
                'em.meio_pagamento',
                'em.arranjo_ur',
                'em.instituicao_financeira',
                'em.parcela',
                'em.quantidade_parcela',
                'em.plano',
                'em.pagamento_prazo',
                'em.meio_captura',
                'em.canal_entrada',
                'em.leitor',
                'em.num_logico',
                'em.numero_serie_leitor',
                'em.status_pagamento',
                'em.tipo_evento',
                'em.cartao_bin',
                'em.cartao_holder',
                'em.codigo_cv',
                'em.valor_total_transacao',
                'em.valor_parcela',
                'em.valor_original_transacao',
                'em.valor_liquido_transacao',
                'em.taxa_intermediacao',
                'em.tarifa_intermediacao',
                'em.movimento_api_codigo',
                'em.estabelecimento',
                'pc.comissao_percentual',
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
                DB::raw('COALESCE(e.nome_fantasia, e.razao_social, e.nome_completo) as estabelecimento_nome'),
                DB::raw('COALESCE(e.cnpj, e.cpf) as documento'),
                DB::raw('COALESCE(mkt.nome_fantasia, mkt.razao_social, mkt.nome_completo, mkt.email) as marketplace_nome'),
                DB::raw('COALESCE(rev.nome_fantasia, rev.razao_social, rev.nome_completo, rev.email) as revenda_nome'),
            ]);

        $idsVistos = [];
        $vendasVistas = [];

        foreach ($query->orderBy('em.data_inicial_transacao')->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            if ($this->movimentoEdiJaContado($mov, $idsVistos, $vendasVistas)) {
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

            if (! isset($chavesSoEdi[$chave])) {
                continue;
            }

            $valor = (float) $mov->valor_total_transacao;
            $percentual = (float) ($mov->comissao_percentual ?? 0);

            yield [
                (int) $mov->id,
                (string) ($mov->nsu ?? ''),
                (string) ($mov->codigo_autorizacao ?? ''),
                (string) ($mov->codigo_transacao ?? ''),
                (string) ($mov->codigo_venda ?? ''),
                (string) ($mov->tx_id ?? ''),
                $this->formatarDataExcel($mov->data_inicial_transacao),
                (string) ($mov->hora_inicial_transacao ?? ''),
                $this->formatarDataExcel($mov->data_venda_ajuste),
                $this->formatarDataExcel($mov->data_prevista_pagamento),
                $idCliente,
                $mov->estabelecimento_id !== null ? (int) $mov->estabelecimento_id : '',
                (string) ($mov->estabelecimento_nome ?? ''),
                (string) ($mov->documento ?? ''),
                (string) ($mov->marketplace_nome ?? ''),
                (string) ($mov->revenda_nome ?? ''),
                $meio,
                $parcelamento,
                $bandeira,
                $escrow,
                $solucao,
                (string) ($mov->tipo_transacao ?? ''),
                (string) ($mov->meio_pagamento ?? ''),
                (string) ($mov->arranjo_ur ?? ''),
                (string) ($mov->instituicao_financeira ?? ''),
                (string) ($mov->parcela ?? ''),
                (string) ($mov->quantidade_parcela ?? ''),
                (string) ($mov->plano ?? ''),
                (string) ($mov->pagamento_prazo ?? ''),
                (string) ($mov->meio_captura ?? ''),
                (string) ($mov->canal_entrada ?? ''),
                (string) ($mov->leitor ?? ''),
                (string) ($mov->num_logico ?? ''),
                (string) ($mov->numero_serie_leitor ?? ''),
                (string) ($mov->status_pagamento ?? ''),
                (string) ($mov->tipo_evento ?? ''),
                (string) ($mov->cartao_bin ?? ''),
                (string) ($mov->cartao_holder ?? ''),
                (string) ($mov->codigo_cv ?? ''),
                round($valor, 2),
                $mov->valor_parcela !== null ? round((float) $mov->valor_parcela, 2) : '',
                $mov->valor_original_transacao !== null ? round((float) $mov->valor_original_transacao, 2) : '',
                $mov->valor_liquido_transacao !== null ? round((float) $mov->valor_liquido_transacao, 2) : '',
                $mov->taxa_intermediacao !== null ? round((float) $mov->taxa_intermediacao, 2) : '',
                $mov->tarifa_intermediacao !== null ? round((float) $mov->tarifa_intermediacao, 2) : '',
                round($percentual, 4),
                round($valor * $percentual / 100, 4),
                (string) ($mov->movimento_api_codigo ?? ''),
                (string) ($mov->estabelecimento ?? ''),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, true>
     */
    private function chavesEdiNaoPareadas(Conciliacao $conciliacao, array $filtros = []): array
    {
        if (! $conciliacao->referencia_mes) {
            return [];
        }

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();
        $agregados = $this->agregarEdi($inicio, $fim, $this->escopoEdiDosFiltros($filtros));

        $planilha = [];
        $filtrosPlanilha = $filtros;
        unset($filtrosPlanilha['status']);

        foreach ($this->queryLinhas($conciliacao, $filtrosPlanilha)->orderBy('conciliacao_linhas.id')->cursor() as $linha) {
            $this->acrescentarGrupoPlanilha($planilha, $linha);
        }

        $ediPareados = array_fill_keys(array_values($this->mapaPareamento($planilha, $agregados)), true);
        $naoPareadas = [];

        foreach ($agregados as $chave => $edi) {
            if (! isset($ediPareados[$chave])) {
                $naoPareadas[$chave] = true;
            }
        }

        return $naoPareadas;
    }

    private function formatarDataExcel(mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        try {
            return now()->parse((string) $valor)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $valor;
        }
    }

    /**
     * @param  array<int|string, true>  $idsVistos
     * @param  array<string, true>  $vendasVistas
     */
    private function movimentoEdiJaContado(object $mov, array &$idsVistos, array &$vendasVistas): bool
    {
        $id = (int) ($mov->id ?? 0);
        if ($id > 0) {
            if (isset($idsVistos[$id])) {
                return true;
            }
            $idsVistos[$id] = true;
        }

        $chave = ConciliacaoDimensao::chaveUnicaVenda(
            $mov->id ?? 0,
            $mov->valor_total_transacao ?? 0,
            isset($mov->codigo_transacao) ? (string) $mov->codigo_transacao : null,
            isset($mov->tx_id) ? (string) $mov->tx_id : null,
            isset($mov->nsu) ? (string) $mov->nsu : null,
            $mov->estabelecimento_id ?? null,
            $mov->data_inicial_transacao ?? null,
        );

        if (isset($vendasVistas[$chave])) {
            return true;
        }

        $vendasVistas[$chave] = true;

        return false;
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

        $planilha = [];
        foreach ($linhasPs as $linha) {
            $this->acrescentarGrupoPlanilha($planilha, $linha);
        }

        $inicio = $conciliacao->referencia_mes?->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes?->copy()->endOfMonth()->toDateString();
        $ediGrupos = ($inicio && $fim) ? $this->agregarEdi($inicio, $fim, $tokens) : collect();
        $mapaPareamento = $this->mapaPareamento($planilha, $ediGrupos);
        $ediPareados = array_fill_keys(array_values($mapaPareamento), true);

        $linhas = collect();

        foreach ($linhasPs as $linha) {
            $chave = $this->chaveDaLinha($linha);
            $ediChave = $mapaPareamento[$chave] ?? null;
            $pareada = $ediChave !== null;
            $detalhe = $this->linhaDetalheDaPlanilha(
                $linha,
                $pareada,
                $pareada ? $ediGrupos->get($ediChave) : null,
                (float) ($planilha[$chave]['tpv'] ?? 0.0),
                $pareada && $ediChave === $chave,
            );
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
            if (isset($ediPareados[$chave])) {
                continue;
            }

            $linhas->push((object) [
                'status' => 'so_edi',
                'id_cliente' => $edi['id_cliente'],
                'estabelecimento_id' => $edi['estabelecimento_id'],
                'meio_pagamento' => $edi['meio'],
                'bandeira' => $edi['bandeira'],
                'parcelamento_agrupado' => $edi['parcelamento'],
                'escrow' => $edi['escrow'] ?? null,
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
     * @param  array<string, array{tpv: float, id_cliente: string, meio: string, parcelamento: string, bandeira: string}>  $planilha
     */
    private function acrescentarGrupoPlanilha(array &$planilha, ConciliacaoLinha $linha): void
    {
        $chave = $this->chaveDaLinha($linha);

        if (! isset($planilha[$chave])) {
            $planilha[$chave] = [
                'tpv' => 0.0,
                'id_cliente' => (string) $linha->id_cliente,
                'meio' => (string) $linha->meio_pagamento,
                'parcelamento' => (string) $linha->parcelamento_agrupado,
                'bandeira' => (string) $linha->bandeira,
            ];
        }

        $planilha[$chave]['tpv'] += (float) $linha->tpv;
    }

    /**
     * Emparelha planilha e EDI: primeiro a chave completa; depois restos com o
     * mesmo cliente, meio, parcelas, bandeira e TPV (escrow/solução podem diferir).
     *
     * @param  array<string, array{tpv: float, id_cliente: string, meio: string, parcelamento: string, bandeira: string}>  $planilha
     * @param  Collection<string, array{tpv: float, id_cliente: string, meio: string, parcelamento: string, bandeira: string}>  $agregados
     * @return array<string, string>  chave da planilha => chave do EDI
     */
    private function mapaPareamento(array $planilha, Collection $agregados): array
    {
        $mapa = [];
        $ediUsados = [];

        foreach ($planilha as $chave => $ps) {
            if ($agregados->has($chave)) {
                $mapa[$chave] = $chave;
                $ediUsados[$chave] = true;
            }
        }

        foreach ($planilha as $chave => $ps) {
            if (isset($mapa[$chave])) {
                continue;
            }

            $candidato = null;

            foreach ($agregados as $ediChave => $edi) {
                if (isset($ediUsados[$ediChave])) {
                    continue;
                }

                if (! $this->mesmoGrupoBasico($ps, $edi)) {
                    continue;
                }

                if (! self::tpvCompativel((float) $ps['tpv'], (float) $edi['tpv'])) {
                    continue;
                }

                if ($candidato !== null) {
                    $candidato = null;
                    break;
                }

                $candidato = (string) $ediChave;
            }

            if ($candidato !== null) {
                $mapa[$chave] = $candidato;
                $ediUsados[$candidato] = true;
            }
        }

        return $mapa;
    }

    /**
     * @param  array{id_cliente?: string, meio?: string, parcelamento?: string, bandeira?: string}  $ps
     * @param  array{id_cliente?: string, meio?: string, parcelamento?: string, bandeira?: string}  $edi
     */
    private function mesmoGrupoBasico(array $ps, array $edi): bool
    {
        return ConciliacaoDimensao::chaveGrupoBasico(
            (string) ($ps['id_cliente'] ?? ''),
            $ps['meio'] ?? null,
            $ps['parcelamento'] ?? null,
            $ps['bandeira'] ?? null,
        ) === ConciliacaoDimensao::chaveGrupoBasico(
            (string) ($edi['id_cliente'] ?? ''),
            $edi['meio'] ?? null,
            $edi['parcelamento'] ?? null,
            $edi['bandeira'] ?? null,
        );
    }

    /**
     * @param  array{tpv: float, qtd: int, comissao: float, bandeira?: string, escrow?: string, solucao?: string}|null  $edi
     */
    private function linhaDetalheDaPlanilha(
        ConciliacaoLinha $linha,
        bool $pareada,
        ?array $edi = null,
        float $grupoTpv = 0.0,
        bool $exato = true,
    ): object {
        $status = $linha->status;
        $tpvLinha = (float) $linha->tpv;
        $comissaoPlanilha = (float) $linha->ms_comissao;
        $ediTpv = 0.0;
        $ediComissao = 0.0;
        $ediQtd = 0;
        $diffTpv = round($tpvLinha, 2);
        $diffComissao = round($comissaoPlanilha, 4);

        if ($status !== 'sem_estabelecimento' && $status !== 'pendente' && ! $pareada) {
            $status = 'sem_edi';
        }

        if ($pareada && $edi !== null && $status !== 'sem_estabelecimento') {
            $ratioTpv = $grupoTpv > 0 ? $tpvLinha / $grupoTpv : 0.0;
            $ediTpv = round((float) $edi['tpv'] * $ratioTpv, 2);
            $ediComissao = self::comissaoPlanilhaNoTpvEdi($comissaoPlanilha, $tpvLinha, $ediTpv);
            $ediQtd = (int) round(((int) ($edi['qtd'] ?? 0)) * $ratioTpv);
            $diffTpv = round($tpvLinha - $ediTpv, 2);
            $diffComissao = round($comissaoPlanilha - $ediComissao, 4);
            $status = ($exato && self::tpvCompativel($grupoTpv, (float) $edi['tpv'])) ? 'ok' : 'divergente';
        }

        return (object) [
            'status' => $status,
            'id_cliente' => $linha->id_cliente,
            'estabelecimento_id' => $linha->estabelecimento_id,
            'meio_pagamento' => $linha->meio_pagamento,
            'bandeira' => $this->combinarDimensao((string) $linha->bandeira, (string) ($edi['bandeira'] ?? ''), $exato && $pareada),
            'parcelamento_agrupado' => $linha->parcelamento_agrupado,
            'escrow' => $this->combinarDimensao((string) $linha->escrow, (string) ($edi['escrow'] ?? ''), $exato && $pareada),
            'solucao' => $this->combinarDimensao((string) $linha->solucao, (string) ($edi['solucao'] ?? ''), $exato && $pareada),
            'tpv' => $tpvLinha,
            'edi_tpv' => $ediTpv,
            'ms_comissao' => $comissaoPlanilha,
            'edi_comissao' => $ediComissao,
            'diff_tpv' => $diffTpv,
            'diff_comissao' => $diffComissao,
            'edi_qtd' => $ediQtd,
            'estabelecimento' => $linha->estabelecimento,
        ];
    }

    private function combinarDimensao(string $planilha, string $edi, bool $exato): string
    {
        $planilha = trim($planilha);
        $edi = trim($edi);

        if ($exato || $edi === '' || $planilha === $edi) {
            return $planilha;
        }

        if ($planilha === '') {
            return $edi;
        }

        return $planilha.' → '.$edi;
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
            $this->acrescentarGrupoPlanilha($planilha, $linha);
        }

        $soEdi = [];
        $extraEdi = [];

        $ediPareados = array_fill_keys(array_values($this->mapaPareamento($planilha, $agregados)), true);

        foreach ($agregados as $chave => $edi) {
            $grupoChave = (string) ($edi['estabelecimento_id'] ?: $edi['id_cliente']);

            if (isset($ediPareados[$chave])) {
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
