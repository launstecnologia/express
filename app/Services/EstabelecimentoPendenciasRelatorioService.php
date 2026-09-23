<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\Usuario;
use App\Support\DocumentoBrasil;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\SimpleXlsxWriter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class EstabelecimentoPendenciasRelatorioService
{
    public const PENDENCIAS = ['sem_plano', 'sem_id', 'sem_transacao'];

    public function query(array $filtros): Builder
    {
        $query = $this->queryBase($filtros);
        $this->aplicarPendencia($query, $filtros['pendencia'] ?? null);

        return $query
            ->with(['marketplace', 'revenda', 'plano'])
            ->orderByDesc('estabelecimentos.id');
    }

    public function paginar(array $filtros, int $porPagina = 50): LengthAwarePaginator
    {
        return $this->query($filtros)->paginate($porPagina)->withQueryString();
    }

    /**
     * @return array{total: int, sem_plano: int, sem_id: int, sem_transacao: int}
     */
    public function contagens(array $filtros): array
    {
        $base = $this->queryBase($filtros);

        return [
            'sem_plano' => (clone $base)->whereRaw($this->sqlSemPlano())->count(),
            'sem_id' => (clone $base)->whereRaw($this->sqlSemId())->count(),
            'sem_transacao' => (clone $base)->whereRaw($this->sqlSemTransacao())->count(),
            'total' => (clone $base)->whereRaw($this->sqlAlgumaPendencia())->count(),
        ];
    }

    public function gerarExcel(array $filtros): string
    {
        $linhas = $this->query($filtros)
            ->get()
            ->map(fn (Estabelecimento $ec) => $this->linhaExcel($ec));

        return SimpleXlsxWriter::file(
            [
                'ID',
                'Nome',
                'Documento',
                'Status',
                'Marketplace',
                'Revenda',
                'Plano',
                'ID PagSeguro',
                'Sem plano',
                'Sem ID PagSeguro',
                'Sem transação',
                'Cadastro',
            ],
            $linhas,
            'Pendências',
        );
    }

    public function nomeArquivo(): string
    {
        return 'estabelecimentos-pendencias-'.now()->format('Y-m-d-His').'.xlsx';
    }

    /**
     * @return list<array{id: int, nome: string}>
     */
    public function marketplaces(): array
    {
        return Cache::remember('relatorio.pendencias.marketplaces', 300, function () {
            return Usuario::query()
                ->where('tipo', 'marketplace')
                ->where('ativo', true)
                ->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')
                ->get()
                ->map(fn (Usuario $usuario) => [
                    'id' => $usuario->id,
                    'nome' => $usuario->nomeExibicao(),
                ])
                ->all();
        });
    }

    public function nome(Estabelecimento $ec): string
    {
        return $ec->nome_fantasia ?: $ec->razao_social ?: $ec->nome_completo ?: '—';
    }

    public function documento(Estabelecimento $ec): string
    {
        $doc = $ec->cnpj ?: $ec->cpf ?: '';

        return $doc !== '' ? DocumentoBrasil::formatarCpfOuCnpj($doc) : '—';
    }

    public function semPlano(Estabelecimento $ec): bool
    {
        return blank($ec->plano_id);
    }

    public function semId(Estabelecimento $ec): bool
    {
        return blank($ec->token_pagseguro);
    }

    /**
     * @return list<string>
     */
    public function pendenciasDaLinha(Estabelecimento $ec, bool $semTransacao): array
    {
        $itens = [];
        if ($this->semPlano($ec)) {
            $itens[] = 'Sem plano';
        }
        if ($this->semId($ec)) {
            $itens[] = 'Sem ID PagSeguro';
        }
        if ($semTransacao) {
            $itens[] = 'Sem transação';
        }

        return $itens;
    }

    public function temTransacao(Estabelecimento $ec): bool
    {
        return (int) ($ec->getAttribute('qtd_transacoes') ?? 0) > 0;
    }

    private function queryBase(array $filtros): Builder
    {
        $query = Estabelecimento::query()
            ->select('estabelecimentos.*')
            ->selectRaw('('.$this->sqlTemTransacao().') as qtd_transacoes');

        if (filled($filtros['busca'] ?? null)) {
            $termo = '%'.trim((string) $filtros['busca']).'%';
            $query->where(function (Builder $q) use ($termo) {
                $q->where('estabelecimentos.nome_fantasia', 'like', $termo)
                    ->orWhere('estabelecimentos.razao_social', 'like', $termo)
                    ->orWhere('estabelecimentos.nome_completo', 'like', $termo)
                    ->orWhere('estabelecimentos.cnpj', 'like', $termo)
                    ->orWhere('estabelecimentos.cpf', 'like', $termo)
                    ->orWhere('estabelecimentos.token_pagseguro', 'like', $termo);
            });
        }

        if (filled($filtros['marketplace_id'] ?? null)) {
            $query->where('estabelecimentos.marketplace_id', (int) $filtros['marketplace_id']);
        }

        if (filled($filtros['revenda_id'] ?? null)) {
            $query->where('estabelecimentos.revenda_id', (int) $filtros['revenda_id']);
        }

        if (filled($filtros['status'] ?? null)) {
            EstabelecimentoEtapaListagem::aplicarFiltroStatus($query, (string) $filtros['status']);
        }

        return $query;
    }

    private function aplicarPendencia(Builder $query, ?string $pendencia): void
    {
        match ($pendencia) {
            'sem_plano' => $query->whereRaw($this->sqlSemPlano()),
            'sem_id' => $query->whereRaw($this->sqlSemId()),
            'sem_transacao' => $query->whereRaw($this->sqlSemTransacao()),
            default => $query->whereRaw($this->sqlAlgumaPendencia()),
        };
    }

    private function sqlSemPlano(): string
    {
        return 'estabelecimentos.plano_id IS NULL';
    }

    private function sqlSemId(): string
    {
        return "(estabelecimentos.token_pagseguro IS NULL OR estabelecimentos.token_pagseguro = '')";
    }

    private function sqlTemTransacao(): string
    {
        return 'EXISTS (SELECT 1 FROM edi_movimentos em WHERE '.$this->sqlVinculoEdi().')';
    }

    private function sqlSemTransacao(): string
    {
        return 'NOT '.$this->sqlTemTransacao();
    }

    private function sqlVinculoEdi(): string
    {
        return 'em.estabelecimento_id = estabelecimentos.id
            OR (
                estabelecimentos.token_pagseguro IS NOT NULL
                AND estabelecimentos.token_pagseguro <> \'\'
                AND (
                    em.estabelecimento = estabelecimentos.token_pagseguro
                    OR em.id_cliente = estabelecimentos.token_pagseguro
                )
            )';
    }

    private function sqlAlgumaPendencia(): string
    {
        return '('.$this->sqlSemPlano().' OR '.$this->sqlSemId().' OR '.$this->sqlSemTransacao().')';
    }

    /**
     * @return list<string|int>
     */
    private function linhaExcel(Estabelecimento $ec): array
    {
        $semTransacao = ! $this->temTransacao($ec);

        return [
            $ec->id,
            $this->nome($ec),
            $this->documento($ec),
            EstabelecimentoEtapaListagem::rotulo(EstabelecimentoEtapaListagem::statusEstabelecimento($ec)),
            $ec->marketplace?->nomeExibicao() ?: '—',
            $ec->revenda?->nomeExibicao() ?: '—',
            $ec->plano?->nome ?: '—',
            $ec->token_pagseguro ?: '—',
            $this->semPlano($ec) ? 'Sim' : 'Não',
            $this->semId($ec) ? 'Sim' : 'Não',
            $semTransacao ? 'Sim' : 'Não',
            optional($ec->created_at)->format('d/m/Y') ?: '—',
        ];
    }
}
