<?php

namespace App\Console\Commands;

use App\Services\LegacyVincularPlanilhaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class LegacyVincularPlanilhaCommand extends Command
{
    protected $signature = 'legacy:vincular-planilha
                            {file : Caminho do Excel (ID, MARKETPLACE, REPRESENTANTE, CPF/CNPJ, NOME)}
                            {--dry-run : Simula sem gravar (padrão se não passar --apply)}
                            {--apply : Grava vínculos no banco}
                            {--criar-faltantes : Cria marketplaces/revendas ausentes antes de vincular}
                            {--force : Sobrescreve vínculo existente (padrão: só preenche quem está sem vínculo)}
                            {--report= : Caminho do CSV de relatório}';

    protected $description = 'Valida e vincula estabelecimentos a marketplace/revenda a partir da planilha TODOS DE A-Z';

    public function handle(LegacyVincularPlanilhaService $service): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Arquivo não encontrado ou sem permissão: {$path}");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $dryRun = ! $apply;
        $criarFaltantes = (bool) $this->option('criar-faltantes');
        $force = (bool) $this->option('force');
        $onlyEmpty = ! $force;

        if ($dryRun) {
            $this->warn('Modo dry-run — nenhum registro será gravado.');
        } else {
            $this->warn('Modo APPLY — vínculos serão gravados no banco.');
        }

        $this->line('Estratégia: '.($force ? 'sobrescrever vínculos existentes (--force)' : 'somente estabelecimentos sem vínculo'));

        if ($criarFaltantes) {
            $this->line('Criação de marketplaces/revendas faltantes: '.($dryRun ? 'simulada (lista no resumo)' : 'ativa'));
        }

        $this->info('Processando planilha...');

        $resultado = $service->processar(
            $path,
            $dryRun,
            $criarFaltantes,
            $onlyEmpty,
            $force,
        );

        $this->newLine();
        $this->info('Resumo');
        foreach ($resultado['resumo'] as $chave => $valor) {
            $this->line(sprintf('  %-24s %d', $chave.':', $valor));
        }

        if ($resultado['marketplaces_faltantes'] !== []) {
            $this->newLine();
            $this->warn('Marketplaces faltantes ('.count($resultado['marketplaces_faltantes']).'):');
            foreach ($resultado['marketplaces_faltantes'] as $nome) {
                $this->line('  - '.$nome);
            }
        }

        if ($resultado['revendas_faltantes'] !== []) {
            $this->newLine();
            $this->warn('Revendas faltantes ('.count($resultado['revendas_faltantes']).'):');
            foreach ($resultado['revendas_faltantes'] as $item) {
                $this->line("  - {$item['nome']} (mkt: {$item['marketplace']})");
            }
        }

        $amostra = collect($resultado['linhas'])
            ->reject(fn (array $l) => in_array($l['status'], ['ok'], true))
            ->take(25)
            ->map(fn (array $l) => [
                $l['token'] ?: '—',
                mb_substr((string) ($l['nome_planilha'] ?? '—'), 0, 28),
                $l['status'],
                mb_substr((string) $l['mensagem'], 0, 60),
            ])
            ->all();

        if ($amostra !== []) {
            $this->newLine();
            $this->info('Amostra (até 25 linhas não-OK):');
            $this->table(['Token', 'Nome', 'Status', 'Mensagem'], $amostra);
        }

        $report = $this->option('report')
            ?: storage_path('app/legacy-vincular-'.now()->format('Ymd-His').'.csv');

        $this->gravarRelatorio($report, $resultado['linhas']);
        $this->info("Relatório CSV: {$report}");

        $this->newLine();
        $this->line('Próximos passos sugeridos:');
        $this->line('  1) Revisar marketplaces/revendas faltantes e aliases');
        $this->line('  2) Dry-run com criação: php artisan legacy:vincular-planilha FILE --criar-faltantes');
        $this->line('  3) Aplicar só vazios: php artisan legacy:vincular-planilha FILE --apply --criar-faltantes');
        $this->line('  4) Se precisar sobrescrever: acrescente --force');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $linhas
     */
    private function gravarRelatorio(string $path, array $linhas): void
    {
        File::ensureDirectoryExists(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Não foi possível gravar relatório em {$path}");
        }

        fputcsv($handle, [
            'token',
            'documento',
            'nome_planilha',
            'marketplace_planilha',
            'revenda_planilha',
            'estabelecimento_id',
            'marketplace_id',
            'revenda_id',
            'match',
            'status',
            'mensagem',
        ], ';');

        foreach ($linhas as $linha) {
            fputcsv($handle, [
                $linha['token'] ?? '',
                $linha['documento'] ?? '',
                $linha['nome_planilha'] ?? '',
                $linha['marketplace_planilha'] ?? '',
                $linha['revenda_planilha'] ?? '',
                $linha['estabelecimento_id'] ?? '',
                $linha['marketplace_id'] ?? '',
                $linha['revenda_id'] ?? '',
                $linha['match'] ?? '',
                $linha['status'] ?? '',
                $linha['mensagem'] ?? '',
            ], ';');
        }

        fclose($handle);
    }
}
