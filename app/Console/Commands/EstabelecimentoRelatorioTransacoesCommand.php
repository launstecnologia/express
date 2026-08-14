<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Support\EstabelecimentoEtapaListagem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EstabelecimentoRelatorioTransacoesCommand extends Command
{
    protected $signature = 'estabelecimento:relatorio-transacoes
                            {marketplace : ID do marketplace}
                            {--de= : Data inicial (Y-m-d)}
                            {--ate= : Data final (Y-m-d)}
                            {--dias=30 : Usado quando --de não informado}
                            {--csv : Gera CSV em storage/app}
                            {--somente-com : Só estabelecimentos com transação no período}
                            {--somente-sem : Só estabelecimentos sem transação no período}';

    protected $description = 'Relatório de estabelecimentos do marketplace com/sem transação EDI (inclui maquininha)';

    public function handle(): int
    {
        $marketplace = $this->resolverMarketplace((string) $this->argument('marketplace'));
        if (! $marketplace) {
            return self::FAILURE;
        }

        [$de, $ate] = $this->periodo();

        $this->info("Marketplace #{$marketplace->id} — {$marketplace->nomeExibicao()}");
        $this->line("Período EDI: {$de} → {$ate}");
        $this->newLine();

        $rows = $this->consultar($marketplace->id, $de, $ate);

        if ($this->option('somente-com')) {
            $rows = $rows->filter(fn ($r) => (int) $r->qtd_transacoes > 0)->values();
        } elseif ($this->option('somente-sem')) {
            $rows = $rows->filter(fn ($r) => (int) $r->qtd_transacoes === 0)->values();
        }

        $this->imprimirResumo($rows);
        $this->newLine();

        if ($rows->isEmpty()) {
            $this->warn('Nenhum estabelecimento encontrado com esses critérios.');

            return self::SUCCESS;
        }

        $comTransacao = $rows->filter(fn ($r) => (int) $r->qtd_transacoes > 0);
        $semTransacao = $rows->filter(fn ($r) => (int) $r->qtd_transacoes === 0);

        if ($comTransacao->isNotEmpty() && ! $this->option('somente-sem')) {
            $this->comment('── Estabelecimentos COM transação no período ──');
            $this->table($this->cabecalhoTabela(), $comTransacao->map(fn ($r) => $this->linhaTabela($r))->all());
            $this->newLine();
        }

        if ($semTransacao->isNotEmpty() && ! $this->option('somente-com')) {
            $this->comment('── Estabelecimentos SEM transação no período ──');
            $this->table($this->cabecalhoTabela(), $semTransacao->map(fn ($r) => $this->linhaTabela($r))->all());
            $this->newLine();
        }

        if ($this->option('csv')) {
            $caminho = $this->gerarCsv($marketplace, $rows, $de, $ate);
            $this->info("CSV: storage/app/{$caminho}");
            $this->line('No Docker: docker cp express-app:/var/www/html/storage/app/'.$caminho.' .');
        }

        return self::SUCCESS;
    }

    private function resolverMarketplace(string $chave): ?Usuario
    {
        $query = Usuario::query()->where('tipo', 'marketplace');

        $usuario = ctype_digit($chave)
            ? (clone $query)->whereKey((int) $chave)->first()
            : (clone $query)->where(function ($q) use ($chave) {
                $q->where('nome_fantasia', 'like', "%{$chave}%")
                    ->orWhere('razao_social', 'like', "%{$chave}%")
                    ->orWhere('nome_completo', 'like', "%{$chave}%")
                    ->orWhere('email', $chave);
            })->first();

        if ($usuario) {
            return $usuario;
        }

        $qualquer = Usuario::query()->find((int) $chave);
        if ($qualquer) {
            $this->error("Usuário #{$qualquer->id} existe, mas o tipo é \"{$qualquer->tipo}\" (esperado: marketplace).");

            return null;
        }

        $this->error("Marketplace não encontrado: {$chave}");

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodo(): array
    {
        $ate = filled($this->option('ate'))
            ? now()->parse((string) $this->option('ate'))->toDateString()
            : now()->toDateString();

        $de = filled($this->option('de'))
            ? now()->parse((string) $this->option('de'))->toDateString()
            : now()->parse($ate)->subDays(max(1, (int) $this->option('dias')) - 1)->toDateString();

        return [$de, $ate];
    }

    private function consultar(int $marketplaceId, string $de, string $ate)
    {
        return DB::table('estabelecimentos as e')
            ->leftJoin('edi_movimentos as em', function ($join) use ($de, $ate) {
                $join->on('em.estabelecimento_id', '=', 'e.id')
                    ->whereBetween('em.data_inicial_transacao', [$de, $ate]);
            })
            ->where('e.marketplace_id', $marketplaceId)
            ->groupBy(
                'e.id',
                'e.nome_fantasia',
                'e.razao_social',
                'e.nome_completo',
                'e.cnpj',
                'e.cpf',
                'e.status',
                'e.ativo',
                'e.token_pagseguro',
                'e.pagbank_edi_ativo',
                'e.plano_id',
                'e.revenda_id',
                'e.created_at',
            )
            ->orderByDesc(DB::raw('COALESCE(SUM(em.valor_total_transacao), 0)'))
            ->orderBy('e.id')
            ->get([
                'e.id',
                'e.nome_fantasia',
                'e.razao_social',
                'e.nome_completo',
                'e.cnpj',
                'e.cpf',
                'e.status',
                'e.ativo',
                'e.token_pagseguro',
                'e.pagbank_edi_ativo',
                'e.plano_id',
                'e.revenda_id',
                'e.created_at',
                DB::raw('COUNT(em.id) as qtd_transacoes'),
                DB::raw('COALESCE(SUM(em.valor_total_transacao), 0) as tpv'),
                DB::raw('MIN(em.data_inicial_transacao) as primeira_venda'),
                DB::raw('MAX(em.data_inicial_transacao) as ultima_venda'),
                DB::raw("SUM(CASE WHEN COALESCE(em.num_logico, '') <> '' OR COALESCE(em.numero_serie_leitor, '') <> '' THEN 1 ELSE 0 END) as qtd_terminal"),
            ]);
    }

    private function imprimirResumo($rows): void
    {
        $total = $rows->count();
        $comToken = $rows->filter(fn ($r) => filled($r->token_pagseguro))->count();
        $semToken = $total - $comToken;
        $ediAtivo = $rows->filter(fn ($r) => (int) $r->pagbank_edi_ativo === 1)->count();
        $comTx = $rows->filter(fn ($r) => (int) $r->qtd_transacoes > 0)->count();
        $semTx = $total - $comTx;
        $semTxComToken = $rows->filter(
            fn ($r) => (int) $r->qtd_transacoes === 0 && filled($r->token_pagseguro)
        )->count();
        $tpv = (float) $rows->sum('tpv');
        $qtdTx = (int) $rows->sum('qtd_transacoes');
        $qtdTerminal = (int) $rows->sum('qtd_terminal');

        $this->table(
            ['Indicador', 'Valor'],
            [
                ['Estabelecimentos do marketplace', number_format($total, 0, ',', '.')],
                ['Com Safepay ID (token_pagseguro)', number_format($comToken, 0, ',', '.')],
                ['Sem Safepay ID — venda não entra no EDI', number_format($semToken, 0, ',', '.')],
                ['EDI ativo (pagbank_edi_ativo)', number_format($ediAtivo, 0, ',', '.')],
                ['COM transação no período', number_format($comTx, 0, ',', '.')],
                ['SEM transação no período', number_format($semTx, 0, ',', '.')],
                ['Sem transação, mas COM token', number_format($semTxComToken, 0, ',', '.')],
                ['Qtd transações EDI', number_format($qtdTx, 0, ',', '.')],
                ['Destas em terminal (série/lógico)', number_format($qtdTerminal, 0, ',', '.')],
                ['TPV no período', 'R$ '.number_format($tpv, 2, ',', '.')],
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function cabecalhoTabela(): array
    {
        return ['ID', 'Nome', 'Documento', 'Cadastro', 'Status', 'Token', 'EDI', 'Tx', 'Terminal', 'TPV', 'Última venda'];
    }

    private function linhaTabela(object $r): array
    {
        $nome = $r->nome_fantasia ?: $r->razao_social ?: $r->nome_completo ?: '—';
        $doc = $r->cnpj ?: $r->cpf ?: '—';

        return [
            $r->id,
            mb_strimwidth((string) $nome, 0, 36, '…'),
            $doc,
            $r->created_at ? now()->parse($r->created_at)->format('d/m/Y') : '—',
            EstabelecimentoEtapaListagem::normalizarStatus($r->status),
            $r->token_pagseguro ?: '—',
            ((int) $r->pagbank_edi_ativo === 1) ? 'sim' : 'não',
            number_format((int) $r->qtd_transacoes, 0, ',', '.'),
            number_format((int) $r->qtd_terminal, 0, ',', '.'),
            'R$ '.number_format((float) $r->tpv, 2, ',', '.'),
            $r->ultima_venda ? now()->parse($r->ultima_venda)->format('d/m/Y') : '—',
        ];
    }

    private function gerarCsv(Usuario $marketplace, $rows, string $de, string $ate): string
    {
        $nome = "mkt-{$marketplace->id}-transacoes-{$de}_{$ate}.csv";
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, [
            'marketplace_id',
            'marketplace',
            'periodo_de',
            'periodo_ate',
            'estabelecimento_id',
            'nome',
            'documento',
            'cadastrado_em',
            'status',
            'ativo',
            'token_pagseguro',
            'pagbank_edi_ativo',
            'plano_id',
            'revenda_id',
            'qtd_transacoes',
            'qtd_terminal',
            'tpv',
            'primeira_venda',
            'ultima_venda',
        ], ';');

        foreach ($rows as $r) {
            fputcsv($handle, [
                $marketplace->id,
                $marketplace->nomeExibicao(),
                $de,
                $ate,
                $r->id,
                $r->nome_fantasia ?: $r->razao_social ?: $r->nome_completo ?: '',
                $r->cnpj ?: $r->cpf ?: '',
                $r->created_at ? now()->parse($r->created_at)->format('Y-m-d H:i:s') : '',
                EstabelecimentoEtapaListagem::normalizarStatus($r->status),
                $r->ativo ? '1' : '0',
                $r->token_pagseguro,
                $r->pagbank_edi_ativo ? '1' : '0',
                $r->plano_id,
                $r->revenda_id,
                $r->qtd_transacoes,
                $r->qtd_terminal,
                number_format((float) $r->tpv, 2, '.', ''),
                $r->primeira_venda,
                $r->ultima_venda,
            ], ';');
        }

        rewind($handle);
        $conteudo = stream_get_contents($handle) ?: '';
        fclose($handle);

        Storage::disk('local')->put($nome, $conteudo);

        return $nome;
    }
}
