<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\EstabelecimentoTransacaoRelatorioService;
use App\Support\EstabelecimentoEtapaListagem;
use Illuminate\Console\Command;
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

    public function handle(EstabelecimentoTransacaoRelatorioService $relatorio): int
    {
        $marketplace = $this->resolverMarketplace((string) $this->argument('marketplace'));
        if (! $marketplace) {
            return self::FAILURE;
        }

        [$de, $ate] = $this->periodo();

        $this->info("Marketplace #{$marketplace->id} — {$marketplace->nomeExibicao()}");
        $this->line("Período EDI: {$de} → {$ate}");
        $this->newLine();

        $filtro = $this->option('somente-com') ? 'com' : ($this->option('somente-sem') ? 'sem' : null);
        $rows = $relatorio->filtrar($relatorio->consultar($marketplace->id, $de, $ate), $filtro);

        $this->imprimirResumo($relatorio->resumo($rows));
        $this->newLine();

        if ($rows->isEmpty()) {
            $this->warn('Nenhum estabelecimento encontrado com esses critérios.');

            return self::SUCCESS;
        }

        $comTransacao = $rows->filter(fn ($r) => (int) $r->qtd_transacoes > 0);
        $semTransacao = $rows->filter(fn ($r) => (int) $r->qtd_transacoes === 0);

        if ($comTransacao->isNotEmpty() && ! $this->option('somente-sem')) {
            $this->comment('── Estabelecimentos COM transação no período ──');
            $this->table($this->cabecalhoTabela(), $comTransacao->map(fn ($r) => $this->linhaTabela($relatorio, $r))->all());
            $this->newLine();
        }

        if ($semTransacao->isNotEmpty() && ! $this->option('somente-com')) {
            $this->comment('── Estabelecimentos SEM transação no período ──');
            $this->table($this->cabecalhoTabela(), $semTransacao->map(fn ($r) => $this->linhaTabela($relatorio, $r))->all());
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

    private function imprimirResumo(array $resumo): void
    {
        $this->table(
            ['Indicador', 'Valor'],
            [
                ['Estabelecimentos do marketplace', number_format($resumo['total'], 0, ',', '.')],
                ['Com Safepay ID (token_pagseguro)', number_format($resumo['com_token'], 0, ',', '.')],
                ['Sem Safepay ID — venda não entra no EDI', number_format($resumo['sem_token'], 0, ',', '.')],
                ['EDI ativo (pagbank_edi_ativo)', number_format($resumo['edi_ativo'], 0, ',', '.')],
                ['COM transação no período', number_format($resumo['com_transacao'], 0, ',', '.')],
                ['SEM transação no período', number_format($resumo['sem_transacao'], 0, ',', '.')],
                ['Sem transação, mas COM token', number_format($resumo['sem_transacao_com_token'], 0, ',', '.')],
                ['Qtd transações EDI no período', number_format($resumo['qtd_transacoes'], 0, ',', '.')],
                ['Destas em terminal (série/lógico)', number_format($resumo['qtd_terminal'], 0, ',', '.')],
                ['Com histórico no banco (pelo ID)', number_format($resumo['com_historico'] ?? 0, 0, ',', '.')],
                ['Com transação no banco pelo Safepay ID', number_format($resumo['com_token_edi'] ?? 0, 0, ',', '.')],
                ['TPV no período', 'R$ '.number_format($resumo['tpv'], 2, ',', '.')],
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function cabecalhoTabela(): array
    {
        return ['ID', 'Nome', 'Documento', 'Cadastro', 'Status', 'Token', 'EDI', 'Tx período', 'TPV período', 'Tx hist.', 'Tx Safepay', 'Última no banco'];
    }

    private function linhaTabela(EstabelecimentoTransacaoRelatorioService $relatorio, object $r): array
    {
        return [
            $r->id,
            mb_strimwidth($relatorio->nome($r), 0, 36, '…'),
            $r->cnpj ?: $r->cpf ?: '—',
            $relatorio->data($r->created_at) ?: '—',
            EstabelecimentoEtapaListagem::normalizarStatus($r->status),
            $r->token_pagseguro ?: '—',
            ((int) $r->pagbank_edi_ativo === 1) ? 'sim' : 'não',
            number_format((int) $r->qtd_transacoes, 0, ',', '.'),
            'R$ '.number_format((float) $r->tpv, 2, ',', '.'),
            number_format((int) ($r->qtd_historico ?? 0), 0, ',', '.'),
            number_format((int) ($r->qtd_token ?? 0), 0, ',', '.'),
            $relatorio->data($r->ultima_historico ?? $r->ultima_token ?? $r->ultima_venda ?? null) ?: '—',
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
            'qtd_transacoes_periodo',
            'qtd_terminal_periodo',
            'tpv_periodo',
            'primeira_venda_periodo',
            'ultima_venda_periodo',
            'qtd_historico',
            'tpv_historico',
            'ultima_historico',
            'qtd_token',
            'tpv_token',
            'ultima_token',
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
                $r->qtd_historico ?? 0,
                number_format((float) ($r->tpv_historico ?? 0), 2, '.', ''),
                $r->ultima_historico ?? '',
                $r->qtd_token ?? 0,
                number_format((float) ($r->tpv_token ?? 0), 2, '.', ''),
                $r->ultima_token ?? '',
            ], ';');
        }

        rewind($handle);
        $conteudo = stream_get_contents($handle) ?: '';
        fclose($handle);

        Storage::disk('local')->put($nome, $conteudo);

        return $nome;
    }
}
