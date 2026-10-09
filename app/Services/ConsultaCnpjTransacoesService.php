<?php

namespace App\Services;

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

        $grupos = $this->agruparPorDocumento($estabelecimentos);
        $planilhas = [];
        $resumoLinhas = [];

        foreach ($grupos as $grupo) {
            $aba = $this->montarAbaDocumento($grupo['estabelecimentos'], $grupo['documento'], $inicio, $fim);
            $planilhas[] = $aba['planilha'];
            $resumoLinhas[] = [
                $grupo['documento_formatado'],
                $grupo['estabelecimentos']->count(),
                $aba['transacoes'],
                ['v' => $aba['faturamento'], 'estilo' => 'numero'],
                ['v' => $aba['liquido'], 'estilo' => 'numero'],
                $grupo['nomes'],
            ];
        }

        if (count($planilhas) > 1) {
            array_unshift($planilhas, [
                'nome' => 'Resumo',
                'autoFiltro' => true,
                'autoFiltroInicio' => 'A5',
                'congelar' => 5,
                'larguras' => [22, 14, 14, 16, 16, 48],
                'mesclar' => ['A1:F1'],
                'linhas' => array_merge([
                    [['v' => 'Consulta por CNPJ — Resumo', 'estilo' => 'titulo']],
                    [['v' => 'Período', 'estilo' => 'cabecalho'], $this->periodoLabel($inicio, $fim)],
                    [['v' => 'CNPJs / CPFs', 'estilo' => 'cabecalho'], count($grupos)],
                    [],
                    [
                        ['v' => 'Documento', 'estilo' => 'cabecalho'],
                        ['v' => 'ECs', 'estilo' => 'cabecalho'],
                        ['v' => 'Transações', 'estilo' => 'cabecalho'],
                        ['v' => 'Faturamento', 'estilo' => 'cabecalho'],
                        ['v' => 'Valor líquido', 'estilo' => 'cabecalho'],
                        ['v' => 'Estabelecimentos', 'estilo' => 'cabecalho'],
                    ],
                ], $resumoLinhas),
            ]);
        }

        return SimpleXlsxWriter::fileSheets($planilhas);
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
     * @return list<array{documento: string, documento_formatado: string, nomes: string, estabelecimentos: Collection<int, Estabelecimento>}>
     */
    private function agruparPorDocumento(Collection $estabelecimentos): array
    {
        $grupos = [];

        foreach ($estabelecimentos as $ec) {
            $digitos = DocumentoBrasil::apenasDigitos((string) ($ec->cnpj ?: $ec->cpf ?: ''));
            $chave = $digitos !== '' ? $digitos : 'sem-documento-'.$ec->id;

            if (! isset($grupos[$chave])) {
                $grupos[$chave] = [
                    'documento' => $digitos,
                    'documento_formatado' => $digitos !== ''
                        ? DocumentoBrasil::formatarCpfOuCnpj($digitos)
                        : 'Sem documento',
                    'nomes' => [],
                    'estabelecimentos' => collect(),
                ];
            }

            $grupos[$chave]['estabelecimentos']->push($ec);
            $grupos[$chave]['nomes'][] = $this->nomeEstabelecimento($ec);
        }

        return array_map(function (array $grupo) {
            $grupo['nomes'] = implode(' · ', array_values(array_unique($grupo['nomes'])));

            return $grupo;
        }, array_values($grupos));
    }

    /**
     * @param  Collection<int, Estabelecimento>  $estabelecimentos
     * @return array{planilha: array<string, mixed>, transacoes: int, faturamento: float, liquido: float}
     */
    private function montarAbaDocumento(Collection $estabelecimentos, string $documento, string $inicio, string $fim): array
    {
        $porId = $estabelecimentos->keyBy('id');
        $porToken = $estabelecimentos
            ->filter(fn (Estabelecimento $ec) => filled($ec->token_pagseguro))
            ->keyBy(fn (Estabelecimento $ec) => (string) $ec->token_pagseguro);

        $linhasTx = [];
        $transacoes = 0;
        $faturamento = 0.0;
        $liquido = 0.0;

        foreach (
            $this->movimentosQuery($estabelecimentos, $inicio, $fim)
                ->orderBy('data_inicial_transacao')
                ->orderBy('hora_inicial_transacao')
                ->orderBy('id')
                ->cursor() as $tx
        ) {
            $ec = $porId->get($tx->estabelecimento_id)
                ?? $porToken->get((string) $tx->estabelecimento);

            $transacoes++;
            $faturamento += (float) ($tx->valor_total_transacao ?? 0);
            $liquido += (float) ($tx->valor_liquido_transacao ?? 0);
            $linhasTx[] = $this->linhaExcelFormatada($tx, $ec);
        }

        $docFormatado = $documento !== ''
            ? DocumentoBrasil::formatarCpfOuCnpj($documento)
            : 'Sem documento';
        $nomeAba = $documento !== '' ? $docFormatado : 'Sem documento';
        $nomesEc = $estabelecimentos
            ->map(fn (Estabelecimento $ec) => $this->nomeEstabelecimento($ec))
            ->unique()
            ->values()
            ->implode(' · ');

        $cabecalhoTabela = [
            ['v' => 'Data', 'estilo' => 'cabecalho'],
            ['v' => 'Horário', 'estilo' => 'cabecalho'],
            ['v' => 'Crédito / Débito', 'estilo' => 'cabecalho'],
            ['v' => 'Bandeira', 'estilo' => 'cabecalho'],
            ['v' => 'Código da transação', 'estilo' => 'cabecalho'],
            ['v' => 'Faturamento', 'estilo' => 'cabecalho'],
            ['v' => 'CNPJ', 'estilo' => 'cabecalho'],
            ['v' => 'Razão social', 'estilo' => 'cabecalho'],
        ];

        $linhas = [
            [['v' => 'Consulta por CNPJ', 'estilo' => 'titulo']],
            [['v' => 'CNPJ / CPF', 'estilo' => 'cabecalho'], $docFormatado],
            [['v' => 'Período', 'estilo' => 'cabecalho'], $this->periodoLabel($inicio, $fim)],
            [['v' => 'Estabelecimentos', 'estilo' => 'cabecalho'], $estabelecimentos->count().' · '.$nomesEc],
            [],
            [['v' => 'Transações', 'estilo' => 'cabecalho'], $transacoes],
            [['v' => 'Faturamento', 'estilo' => 'cabecalho'], ['v' => round($faturamento, 2), 'estilo' => 'numero_negrito']],
            [['v' => 'Valor líquido', 'estilo' => 'cabecalho'], ['v' => round($liquido, 2), 'estilo' => 'numero_negrito']],
            [],
            $cabecalhoTabela,
            ...$linhasTx,
        ];

        return [
            'planilha' => [
                'nome' => $nomeAba,
                'autoFiltro' => true,
                'autoFiltroInicio' => 'A10',
                'autoFiltroColunaFim' => 'H',
                'congelar' => 10,
                'mesclar' => ['A1:H1', 'B4:H4'],
                'larguras' => [12, 12, 16, 18, 28, 14, 20, 40],
                'linhas' => $linhas,
            ],
            'transacoes' => $transacoes,
            'faturamento' => round($faturamento, 2),
            'liquido' => round($liquido, 2),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function linhaExcelFormatada(object $tx, ?Estabelecimento $ec): array
    {
        $tipo = EdiTransacaoCategoria::resolver(
            $tx->tipo_transacao ?? null,
            $tx->meio_pagamento ?? null,
            $tx->arranjo_ur ?? null,
            isset($tx->quantidade_parcela) ? (string) $tx->quantidade_parcela : null,
        );

        $documento = DocumentoBrasil::apenasDigitos((string) ($ec?->cnpj ?: $ec?->cpf ?: ''));
        $codigo = trim((string) ($tx->codigo_transacao ?: $tx->nsu ?: $tx->tx_id ?: ''));

        return [
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
            $documento !== '' ? DocumentoBrasil::formatarCpfOuCnpj($documento) : '',
            $this->razaoSocial($ec),
        ];
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
