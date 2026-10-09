<?php

namespace App\Services;

use App\Models\Conciliacao;
use App\Models\ConciliacaoLinha;
use App\Models\EdiMovimento;
use App\Models\Estabelecimento;
use App\Models\Usuario;
use App\Support\ConciliacaoDimensao;
use App\Support\DocumentoBrasil;
use App\Support\EdiStatusPagamento;
use App\Support\EdiTransacaoCategoria;
use App\Support\SimpleXlsxWriter;
use App\Support\UsuarioComercial;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ConsultaCnpjTransacoesService
{
    /**
     * @return array{
     *     parceiros: Collection<int, Usuario>,
     *     origem_estabelecimentos: Collection<int, Estabelecimento>,
     *     estabelecimentos: Collection<int, Estabelecimento>
     * }
     */
    public function resolverConsulta(string $documento, bool $incluirRede): array
    {
        $origem = $this->buscarEstabelecimentos($documento);
        $parceiros = $this->buscarParceiros($documento);
        $estabelecimentos = $origem;

        if ($incluirRede) {
            $rede = collect();

            foreach ($parceiros as $parceiro) {
                $rede = $rede->merge($this->estabelecimentosDoParceiro($parceiro));
            }

            foreach ($origem as $ec) {
                $rede = $rede->merge($this->estabelecimentosDaRedeDoEc($ec));
            }

            $estabelecimentos = $origem->concat($rede)->unique('id')->values();
        }

        return [
            'parceiros' => $parceiros,
            'origem_estabelecimentos' => $origem,
            'estabelecimentos' => $estabelecimentos,
        ];
    }

    public function buscarEstabelecimentos(string $documento): Collection
    {
        $digitos = DocumentoBrasil::apenasDigitos($documento);

        if (! in_array(strlen($digitos), [11, 14], true)) {
            return collect();
        }

        $query = Estabelecimento::withoutGlobalScopes()
            ->with(['marketplace', 'revenda']);
        $this->filtrarPorDocumento($query, $digitos);

        return $query->orderByDesc('id')->get();
    }

    public function buscarParceiros(string $documento): Collection
    {
        $digitos = DocumentoBrasil::apenasDigitos($documento);

        if (! in_array(strlen($digitos), [11, 14], true)) {
            return collect();
        }

        $query = Usuario::query()
            ->whereIn('tipo', ['marketplace', 'revenda']);
        $this->filtrarPorDocumento($query, $digitos);

        return $query
            ->orderBy('tipo')
            ->orderBy('id')
            ->get();
    }

    public function estabelecimentosDoParceiro(Usuario $parceiro): Collection
    {
        $query = Estabelecimento::withoutGlobalScopes()->with(['marketplace', 'revenda']);

        if ($parceiro->tipo === 'marketplace') {
            $revendaIds = UsuarioComercial::revendasDo($parceiro)->pluck('id')->all();

            $query->where(function (Builder $q) use ($parceiro, $revendaIds) {
                $q->where('marketplace_id', $parceiro->id);
                if ($revendaIds !== []) {
                    $q->orWhereIn('revenda_id', $revendaIds);
                }
            });
        } elseif ($parceiro->tipo === 'revenda') {
            $query->where('revenda_id', $parceiro->id);
        } else {
            return collect();
        }

        return $query->orderByDesc('id')->get();
    }

    public function estabelecimentosDaRedeDoEc(Estabelecimento $ec): Collection
    {
        if ($ec->revenda_id) {
            $revenda = $ec->revenda ?: Usuario::query()->find($ec->revenda_id);

            return $revenda ? $this->estabelecimentosDoParceiro($revenda) : collect([$ec]);
        }

        if ($ec->marketplace_id) {
            $marketplace = $ec->marketplace ?: Usuario::query()->find($ec->marketplace_id);

            return $marketplace ? $this->estabelecimentosDoParceiro($marketplace) : collect([$ec]);
        }

        return collect([$ec]);
    }

    public function rotuloParceiro(Usuario $usuario): string
    {
        return $usuario->tipo === 'marketplace' ? 'Marketplace' : 'Revenda';
    }

    public function nomeParceiro(Usuario $usuario): string
    {
        return $usuario->nomeExibicao();
    }

    public function movimentosQuery(Collection $estabelecimentos, string $inicio, string $fim): Builder
    {
        $ids = $estabelecimentos->pluck('id')->filter()->all();
        $tokens = $estabelecimentos
            ->pluck('token_pagseguro')
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = EdiMovimento::query()
            ->whereBetween('data_inicial_transacao', [$inicio, $fim]);

        if ($ids === [] && $tokens === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($ids, $tokens) {
            if ($ids !== []) {
                $query->whereIn('estabelecimento_id', $ids);
            }

            if ($tokens !== []) {
                $query->orWhereIn('estabelecimento', $tokens);
            }
        });
    }

    public function totais(Builder $query): object
    {
        return (clone $query)
            ->selectRaw('
                COUNT(*) as total_transacoes,
                COALESCE(SUM(valor_total_transacao), 0) as valor_total,
                COALESCE(SUM(valor_liquido_transacao), 0) as valor_liquido
            ')
            ->first();
    }

    public function paginar(Builder $query, int $porPagina = 100): LengthAwarePaginator
    {
        return (clone $query)
            ->orderByDesc('data_inicial_transacao')
            ->orderByDesc('hora_inicial_transacao')
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    public function gerarExcel(Collection $estabelecimentos, string $inicio, string $fim): string
    {
        @set_time_limit(900);

        $planilhaPorEc = $this->carregarLinhasPlanilhaPorEc($estabelecimentos, $inicio);
        $planilhas = [];
        $resumoLinhas = [];

        foreach ($estabelecimentos as $ec) {
            $linhasPlanilha = $planilhaPorEc[(int) $ec->id] ?? collect();
            $aba = $this->montarAbaEstabelecimento($ec, $inicio, $fim, $linhasPlanilha);
            $planilhas[] = $aba['planilha'];
            $resumoLinhas[] = [
                $ec->id,
                $this->idPagBank($ec),
                $this->razaoSocial($ec),
                $this->documentoFormatado($ec),
                $aba['transacoes_edi'],
                ['v' => $aba['faturamento_edi'], 'estilo' => 'numero'],
                $aba['linhas_planilha'],
                ['v' => $aba['faturamento_planilha'], 'estilo' => 'numero'],
            ];
        }

        if (count($planilhas) > 1) {
            array_unshift($planilhas, [
                'nome' => 'Resumo',
                'autoFiltro' => true,
                'autoFiltroInicio' => 'A5',
                'congelar' => 5,
                'larguras' => [10, 18, 36, 20, 14, 16, 14, 16],
                'mesclar' => ['A1:H1'],
                'linhas' => array_merge([
                    [['v' => 'Consulta por CNPJ — Resumo por estabelecimento', 'estilo' => 'titulo']],
                    [['v' => 'Período', 'estilo' => 'cabecalho'], $this->periodoLabel($inicio, $fim)],
                    [['v' => 'Estabelecimentos', 'estilo' => 'cabecalho'], $estabelecimentos->count()],
                    [],
                    [
                        ['v' => 'ID', 'estilo' => 'cabecalho'],
                        ['v' => 'ID PagBank', 'estilo' => 'cabecalho'],
                        ['v' => 'Razão social', 'estilo' => 'cabecalho'],
                        ['v' => 'CNPJ', 'estilo' => 'cabecalho'],
                        ['v' => 'Transações EDI', 'estilo' => 'cabecalho'],
                        ['v' => 'Faturamento EDI', 'estilo' => 'cabecalho'],
                        ['v' => 'Linhas planilha', 'estilo' => 'cabecalho'],
                        ['v' => 'Faturamento planilha', 'estilo' => 'cabecalho'],
                    ],
                ], $resumoLinhas),
            ]);
        }

        return SimpleXlsxWriter::fileSheets($planilhas !== [] ? $planilhas : [[
            'nome' => 'Sem dados',
            'linhas' => [[['v' => 'Nenhum estabelecimento para exportar', 'estilo' => 'titulo']]],
        ]]);
    }

    public function nomeArquivo(Collection $estabelecimentos, Carbon $mes, ?string $documento = null, bool $rede = false): string
    {
        $primeiro = $estabelecimentos->first();
        $digitos = DocumentoBrasil::apenasDigitos((string) ($documento ?: $primeiro?->cnpj ?: $primeiro?->cpf ?: 'documento'));
        $competencia = $mes->format('Y-m');
        $prefixo = $rede ? 'rede' : 'transacoes';

        return "{$prefixo}-{$digitos}-{$competencia}.xlsx";
    }

    public function statusLabel(?string $status): string
    {
        if (EdiStatusPagamento::cancelado($status)) {
            return 'Cancelado';
        }

        return match ((string) $status) {
            '03', '3' => 'Concluído',
            '01', '1' => 'Novo',
            '02', '2' => 'Agendado',
            '04', '4' => 'Cancelado',
            '', 'sem' => 'Sem status',
            default => 'Status '.$status,
        };
    }

    /**
     * @param  Collection<int, Estabelecimento>  $estabelecimentos
     * @return array<int, Collection<int, ConciliacaoLinha>>
     */
    private function carregarLinhasPlanilhaPorEc(Collection $estabelecimentos, string $inicio): array
    {
        $conciliacao = Conciliacao::query()
            ->whereDate('referencia_mes', Carbon::parse($inicio)->startOfMonth()->toDateString())
            ->latest('id')
            ->first();

        if (! $conciliacao) {
            return [];
        }

        $ids = $estabelecimentos->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $tokens = $estabelecimentos
            ->pluck('token_pagseguro')
            ->map(fn ($token) => trim((string) $token))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $tokenParaId = $estabelecimentos
            ->filter(fn (Estabelecimento $ec) => filled($ec->token_pagseguro))
            ->mapWithKeys(fn (Estabelecimento $ec) => [strtolower(trim((string) $ec->token_pagseguro)) => (int) $ec->id])
            ->all();

        if ($ids === [] && $tokens === []) {
            return [];
        }

        $linhas = ConciliacaoLinha::query()
            ->where('conciliacao_id', $conciliacao->id)
            ->where(function (Builder $query) use ($ids, $tokens) {
                if ($ids !== []) {
                    $query->whereIn('estabelecimento_id', $ids);
                }
                if ($tokens !== []) {
                    $query->orWhereIn('id_cliente', $tokens);
                }
            })
            ->orderBy('id')
            ->get();

        $porEc = [];
        foreach ($linhas as $linha) {
            $ecId = $linha->estabelecimento_id
                ? (int) $linha->estabelecimento_id
                : ($tokenParaId[strtolower(trim((string) $linha->id_cliente))] ?? null);

            if (! $ecId) {
                continue;
            }

            $porEc[$ecId] ??= collect();
            $porEc[$ecId]->push($linha);
        }

        return $porEc;
    }

    /**
     * @param  Collection<int, ConciliacaoLinha>  $linhasPlanilha
     * @return array{
     *     planilha: array<string, mixed>,
     *     transacoes_edi: int,
     *     faturamento_edi: float,
     *     linhas_planilha: int,
     *     faturamento_planilha: float
     * }
     */
    private function montarAbaEstabelecimento(
        Estabelecimento $ec,
        string $inicio,
        string $fim,
        Collection $linhasPlanilha,
    ): array {
        $linhasEdi = [];
        $transacoesEdi = 0;
        $faturamentoEdi = 0.0;

        foreach (
            $this->movimentosQuery(collect([$ec]), $inicio, $fim)
                ->orderBy('data_inicial_transacao')
                ->orderBy('hora_inicial_transacao')
                ->orderBy('id')
                ->cursor() as $tx
        ) {
            $transacoesEdi++;
            $faturamentoEdi += (float) ($tx->valor_total_transacao ?? 0);
            $linhasEdi[] = $this->linhaExcelEdi($tx, $ec);
        }

        $faturamentoPlanilha = round((float) $linhasPlanilha->sum(fn (ConciliacaoLinha $l) => (float) $l->tpv), 2);
        $linhasPlanilhaExcel = $linhasPlanilha->map(fn (ConciliacaoLinha $linha) => $this->linhaExcelPlanilha($linha, $ec))->values()->all();

        $doc = $this->documentoFormatado($ec);
        $razao = $this->razaoSocial($ec);
        $idPagBank = $this->idPagBank($ec);
        $cabecalhoDetalhe = [
            ['v' => 'Origem', 'estilo' => 'cabecalho'],
            ['v' => 'ID PagBank', 'estilo' => 'cabecalho'],
            ['v' => 'Data', 'estilo' => 'cabecalho'],
            ['v' => 'Horário', 'estilo' => 'cabecalho'],
            ['v' => 'Crédito / Débito', 'estilo' => 'cabecalho'],
            ['v' => 'Bandeira', 'estilo' => 'cabecalho'],
            ['v' => 'Código da transação', 'estilo' => 'cabecalho'],
            ['v' => 'Faturamento', 'estilo' => 'cabecalho'],
            ['v' => 'CNPJ', 'estilo' => 'cabecalho'],
            ['v' => 'Razão social', 'estilo' => 'cabecalho'],
        ];

        $qtdPlanilha = $linhasPlanilha->count();
        $linhas = [
            [['v' => 'Consulta por CNPJ', 'estilo' => 'titulo']],
            [['v' => 'ID do estabelecimento', 'estilo' => 'cabecalho'], $ec->id],
            [['v' => 'ID PagBank', 'estilo' => 'cabecalho'], $idPagBank],
            [['v' => 'Razão social', 'estilo' => 'cabecalho'], $razao],
            [['v' => 'CNPJ / CPF', 'estilo' => 'cabecalho'], $doc],
            [['v' => 'Período', 'estilo' => 'cabecalho'], $this->periodoLabel($inicio, $fim)],
            [],
            [['v' => 'Resumo', 'estilo' => 'titulo']],
            [
                ['v' => 'Transações (EDI)', 'estilo' => 'cabecalho'],
                $transacoesEdi,
                ['v' => 'Faturamento (EDI)', 'estilo' => 'cabecalho'],
                ['v' => round($faturamentoEdi, 2), 'estilo' => 'numero_negrito'],
            ],
            [
                ['v' => 'Linhas (planilha)', 'estilo' => 'cabecalho'],
                $qtdPlanilha,
                ['v' => 'Faturamento (planilha)', 'estilo' => 'cabecalho'],
                ['v' => $faturamentoPlanilha, 'estilo' => 'numero_negrito'],
            ],
            [],
            [['v' => 'Detalhe — Planilha PagSeguro', 'estilo' => 'titulo']],
            $cabecalhoDetalhe,
            ...($linhasPlanilhaExcel !== [] ? $linhasPlanilhaExcel : [[
                'Planilha', $idPagBank, '—', '—', '—', '—', 'Sem linhas na conciliação deste mês', '', $doc, $razao,
            ]]),
            [],
            [['v' => 'Detalhe — EDI', 'estilo' => 'titulo']],
            $cabecalhoDetalhe,
            ...($linhasEdi !== [] ? $linhasEdi : [[
                'EDI', $idPagBank, '—', '—', '—', '—', 'Sem transações no EDI deste mês', '', $doc, $razao,
            ]]),
        ];

        // Linhas 1–13 fixas; dados da planilha a partir da 14; título EDI = 15 + N
        $linhaTituloEdi = 15 + max(1, count($linhasPlanilhaExcel));

        return [
            'planilha' => [
                'nome' => 'ID '.$ec->id,
                'autoFiltro' => false,
                'congelar' => 10,
                'mesclar' => ['A1:J1', 'A8:J8', 'A12:J12', 'A'.$linhaTituloEdi.':J'.$linhaTituloEdi],
                'larguras' => [12, 18, 12, 12, 16, 18, 28, 14, 20, 40],
                'linhas' => $linhas,
            ],
            'transacoes_edi' => $transacoesEdi,
            'faturamento_edi' => round($faturamentoEdi, 2),
            'linhas_planilha' => $linhasPlanilha->count(),
            'faturamento_planilha' => $faturamentoPlanilha,
        ];
    }

    /**
     * @return list<mixed>
     */
    private function linhaExcelEdi(object $tx, Estabelecimento $ec): array
    {
        $tipo = EdiTransacaoCategoria::resolver(
            $tx->tipo_transacao ?? null,
            $tx->meio_pagamento ?? null,
            $tx->arranjo_ur ?? null,
            isset($tx->quantidade_parcela) ? (string) $tx->quantidade_parcela : null,
        );
        $codigo = trim((string) ($tx->codigo_transacao ?: $tx->nsu ?: $tx->tx_id ?: ''));

        $idPagBank = trim((string) ($tx->estabelecimento ?: $tx->id_cliente ?: $this->idPagBank($ec)));

        return [
            'EDI',
            $idPagBank !== '' ? $idPagBank : '—',
            $tx->data_inicial_transacao?->format('d/m/Y') ?: '',
            (string) ($tx->hora_inicial_transacao ?: ''),
            $this->labelCreditoDebito($tipo),
            ConciliacaoDimensao::bandeiraDoEdi(
                $tx->instituicao_financeira ?? null,
                $tx->tipo_transacao ?? null,
                $tx->arranjo_ur ?? null,
            ),
            $codigo,
            ['v' => (float) ($tx->valor_total_transacao ?? 0), 'estilo' => 'numero'],
            $this->documentoFormatado($ec),
            $this->razaoSocial($ec),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function linhaExcelPlanilha(ConciliacaoLinha $linha, Estabelecimento $ec): array
    {
        $meio = ConciliacaoDimensao::meioNormalizado($linha->meio_pagamento);
        $idPagBank = trim((string) ($linha->id_cliente ?: $this->idPagBank($ec)));

        return [
            'Planilha',
            $idPagBank !== '' ? $idPagBank : '—',
            '—',
            '—',
            $this->labelCreditoDebito($meio === 'parcelado' ? 'credito' : $meio),
            (string) ($linha->bandeira ?: '—'),
            trim((string) ($linha->parcelamento_agrupado ?: $linha->solucao ?: '—')),
            ['v' => (float) ($linha->tpv ?? 0), 'estilo' => 'numero'],
            $this->documentoFormatado($ec),
            $this->razaoSocial($ec),
        ];
    }

    private function idPagBank(?Estabelecimento $ec): string
    {
        $token = trim((string) ($ec?->token_pagseguro ?: ''));

        return $token !== '' ? $token : '—';
    }

    private function documentoFormatado(?Estabelecimento $ec): string
    {
        $digitos = DocumentoBrasil::apenasDigitos((string) ($ec?->cnpj ?: $ec?->cpf ?: ''));

        return $digitos !== '' ? DocumentoBrasil::formatarCpfOuCnpj($digitos) : '';
    }

    private function labelCreditoDebito(string $tipo): string
    {
        return match ($tipo) {
            'credito', 'parcelado' => 'Crédito',
            'debito' => 'Débito',
            'pix' => 'PIX',
            default => 'Outros',
        };
    }

    private function razaoSocial(?Estabelecimento $estabelecimento): string
    {
        if (! $estabelecimento) {
            return '—';
        }

        return $estabelecimento->razao_social
            ?: $estabelecimento->nome_fantasia
            ?: $estabelecimento->nome_completo
            ?: 'Estabelecimento #'.$estabelecimento->id;
    }

    private function periodoLabel(string $inicio, string $fim): string
    {
        return Carbon::parse($inicio)->format('d/m/Y').' a '.Carbon::parse($fim)->format('d/m/Y');
    }

    public function nomeEstabelecimento(?Estabelecimento $estabelecimento): string
    {
        if (! $estabelecimento) {
            return '—';
        }

        return $estabelecimento->nome_fantasia
            ?: $estabelecimento->razao_social
            ?: $estabelecimento->nome_completo
            ?: 'Estabelecimento #'.$estabelecimento->id;
    }

    private function filtrarPorDocumento(Builder $query, string $digitos): void
    {
        if (strlen($digitos) === 14) {
            $query->whereRaw(
                "REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '/', ''), '-', '') = ?",
                [$digitos],
            );

            return;
        }

        $query->whereRaw(
            "REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', '') = ?",
            [$digitos],
        );
    }
}
