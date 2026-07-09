<?php

namespace App\Console/Commands;

use App\Models\Estabelecimento;
use App\Support\EstabelecimentoEtapaListagem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class EstabelecimentoListarSemVinculoCommand extends Command
{
    protected $signature = 'estabelecimento:listar-sem-vinculo
                            {--status=pendente,negado : Etapas de status do cadastro (pendente,aprovado,negado) separadas por vírgula}
                            {--somente-sem-marketplace : Só exige marketplace_id nulo (revenda pode existir)}
                            {--somente-sem-revenda : Só exige revenda_id nulo (marketplace pode existir)}
                            {--incluir-aprovados : Inclui também status aprovado}
                            {--csv : Gera CSV em storage/app}
                            {--limit=0 : Limita quantidade (0 = todos)}';

    protected $description = 'Lista estabelecimentos sem marketplace/revenda com status pendente ou negado';

    public function handle(): int
    {
        $query = Estabelecimento::withoutGlobalScopes()
            ->orderBy('id');

        $somenteMkt = (bool) $this->option('somente-sem-marketplace');
        $somenteRev = (bool) $this->option('somente-sem-revenda');

        if ($somenteMkt && ! $somenteRev) {
            $query->whereNull('marketplace_id');
        } elseif ($somenteRev && ! $somenteMkt) {
            $query->whereNull('revenda_id');
        } else {
            $query->whereNull('marketplace_id')->whereNull('revenda_id');
        }

        $this->aplicarFiltroStatus($query);

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        $rows = $query->get([
            'id',
            'nome_fantasia',
            'razao_social',
            'nome_completo',
            'cnpj',
            'cpf',
            'status',
            'ativo',
            'marketplace_id',
            'revenda_id',
            'plano_id',
            'token_pagseguro',
            'created_at',
        ]);

        $this->info("Encontrados: {$total}");
        $this->line($this->descricaoFiltros());

        if ($rows->isEmpty()) {
            $this->warn('Nenhum estabelecimento encontrado com esses critérios.');

            return self::SUCCESS;
        }

        $tabela = $rows->map(function (Estabelecimento $e) {
            $nome = $e->nome_fantasia ?: $e->razao_social ?: $e->nome_completo ?: '—';
            $doc = $e->cnpj ?: $e->cpf ?: '—';
            $statusNorm = EstabelecimentoEtapaListagem::normalizarStatus($e->status);

            return [
                $e->id,
                $nome,
                $doc,
                $e->status ?: '—',
                $statusNorm,
                $e->ativo ? '1' : '0',
                $e->marketplace_id ?: '—',
                $e->revenda_id ?: '—',
                $e->plano_id ?: '—',
                $e->token_pagseguro ?: '—',
                optional($e->created_at)->format('Y-m-d') ?: '—',
            ];
        })->all();

        $this->table(
            ['ID', 'Nome', 'Documento', 'Status DB', 'Status', 'Ativo', 'MKT', 'Revenda', 'Plano', 'EDI', 'Cadastro'],
            $tabela,
        );

        if ($this->option('csv')) {
            $caminho = $this->gerarCsv($rows);
            $this->info("CSV: storage/app/{$caminho}");
            $this->line('No Docker: docker cp express-app:/var/www/html/storage/app/'.$caminho.' .');
        }

        return self::SUCCESS;
    }

    private function aplicarFiltroStatus(Builder $query): void
    {
        $raw = (string) $this->option('status');
        $etapas = collect(explode(',', $raw))
            ->map(fn ($s) => strtolower(trim($s)))
            ->filter()
            ->unique()
            ->values();

        if ($this->option('incluir-aprovados') && ! $etapas->contains('aprovado')) {
            $etapas->push('aprovado');
        }

        $etapas = $etapas->filter(
            fn ($e) => in_array($e, ['pendente', 'aprovado', 'negado'], true)
        )->values();

        if ($etapas->isEmpty()) {
            $etapas = collect(['pendente', 'negado']);
        }

        $query->where(function (Builder $outer) use ($etapas) {
            foreach ($etapas as $etapa) {
                $outer->orWhere(function (Builder $q) use ($etapa) {
                    EstabelecimentoEtapaListagem::aplicarFiltroStatus($q, $etapa);
                });
            }
        });
    }

    private function descricaoFiltros(): string
    {
        $vinculo = match (true) {
            $this->option('somente-sem-marketplace') && ! $this->option('somente-sem-revenda') => 'sem marketplace',
            $this->option('somente-sem-revenda') && ! $this->option('somente-sem-marketplace') => 'sem revenda',
            default => 'sem marketplace e sem revenda',
        };

        return "Filtro: {$vinculo} | status={$this->option('status')}";
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Estabelecimento>  $rows
     */
    private function gerarCsv($rows): string
    {
        $nome = 'estabelecimentos-sem-vinculo-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'id',
            'nome',
            'documento',
            'status_db',
            'status',
            'ativo',
            'marketplace_id',
            'revenda_id',
            'plano_id',
            'token_pagseguro',
            'created_at',
        ], ';');

        foreach ($rows as $e) {
            fputcsv($handle, [
                $e->id,
                $e->nome_fantasia ?: $e->razao_social ?: $e->nome_completo ?: '',
                $e->cnpj ?: $e->cpf ?: '',
                $e->status ?: '',
                EstabelecimentoEtapaListagem::normalizarStatus($e->status),
                $e->ativo ? '1' : '0',
                $e->marketplace_id,
                $e->revenda_id,
                $e->plano_id,
                $e->token_pagseguro,
                optional($e->created_at)->format('Y-m-d H:i:s'),
            ], ';');
        }

        rewind($handle);
        $conteudo = stream_get_contents($handle) ?: '';
        fclose($handle);

        Storage::disk('local')->put($nome, $conteudo);

        return $nome;
    }
}
