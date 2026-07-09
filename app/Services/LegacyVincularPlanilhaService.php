<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\Hierarquia;
use App\Models\Usuario;
use App\Support\DocumentoBrasil;
use App\Support\LegacyImportConcerns;
use App\Support\LegacyMarketplaceAlias;
use App\Support\SimpleXlsxReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LegacyVincularPlanilhaService
{
    use LegacyImportConcerns;

    /** @var array<string, Usuario> */
    private array $marketplacesPorNome = [];

    /** @var array<string, Usuario> */
    private array $revendasPorNome = [];

    /** @var array<string, Estabelecimento> */
    private array $estabelecimentosPorToken = [];

    /** @var array<string, Estabelecimento> */
    private array $estabelecimentosPorDocumento = [];

    public function __construct(
        private readonly HierarquiaService $hierarquiaService,
        private readonly RoyaltyCalculadorService $royaltyService,
    ) {}

    /**
     * @return array{
     *   resumo: array<string, int>,
     *   marketplaces_faltantes: list<string>,
     *   revendas_faltantes: list<array{nome: string, marketplace: string}>,
     *   linhas: list<array<string, mixed>>
     * }
     */
    public function processar(
        string $path,
        bool $dryRun = true,
        bool $criarFaltantes = false,
        bool $onlyEmpty = true,
        bool $force = false,
    ): array {
        $rows = $this->lerPlanilha($path);

        $this->precarregar();

        if ($criarFaltantes) {
            $this->criarMarketplacesFaltantes($rows, $dryRun);
            $this->criarRevendasFaltantes($rows, $dryRun);
        }

        $resultado = [
            'resumo' => [
                'total' => 0,
                'ok' => 0,
                'vinculados' => 0,
                'criariam_vinculo' => 0,
                'estab_nao_encontrado' => 0,
                'mkt_faltando' => 0,
                'rep_faltando' => 0,
                'sem_marketplace_planilha' => 0,
                'ja_vinculado' => 0,
                'divergente_ignorado' => 0,
                'erros' => 0,
            ],
            'marketplaces_faltantes' => [],
            'revendas_faltantes' => [],
            'linhas' => [],
        ];

        $mktFaltantes = [];
        $repFaltantes = [];

        foreach ($rows as $row) {
            $linha = $this->processarLinha($row, $dryRun, $onlyEmpty, $force);
            $resultado['linhas'][] = $linha;
            $resultado['resumo']['total']++;

            $status = $linha['status'];
            if (isset($resultado['resumo'][$status])) {
                $resultado['resumo'][$status]++;
            } else {
                $resultado['resumo']['erros']++;
            }

            if ($status === 'mkt_faltando' && filled($linha['marketplace_planilha'])) {
                $mktFaltantes[$linha['marketplace_planilha']] = true;
            }

            if ($status === 'rep_faltando' && filled($linha['revenda_planilha'])) {
                $chave = $linha['revenda_planilha'].'|'.$linha['marketplace_planilha'];
                $repFaltantes[$chave] = [
                    'nome' => $linha['revenda_planilha'],
                    'marketplace' => $linha['marketplace_planilha'],
                ];
            }
        }

        $resultado['marketplaces_faltantes'] = array_keys($mktFaltantes);
        sort($resultado['marketplaces_faltantes']);
        $resultado['revendas_faltantes'] = array_values($repFaltantes);

        return $resultado;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lerPlanilha(string $path): array
    {
        $rows = SimpleXlsxReader::rowsAssociativos($path);
        $normalizadas = [];

        foreach ($rows as $row) {
            $mapa = [];
            foreach ($row as $chave => $valor) {
                $mapa[$this->normalizarCabecalho((string) $chave)] = is_string($valor) ? trim($valor) : $valor;
            }

            $token = $this->valorMapa($mapa, ['id', 'token', 'id_estabelecimento', 'codigo_edi']);
            $marketplace = $this->valorMapa($mapa, ['marketplace', 'mkt']);
            $revenda = $this->valorMapa($mapa, ['representante', 'revenda', 'rep']);
            $documento = $this->valorMapa($mapa, ['cpf/cnpj do ec', 'cpf_cnpj', 'cnpj', 'cpf', 'documento', 'client_document']);
            $nome = $this->valorMapa($mapa, ['nome do ec', 'nome', 'client_statement', 'razao_social']);

            if (! filled($token) && ! filled($documento) && ! filled($nome)) {
                continue;
            }

            $normalizadas[] = [
                'token' => filled($token) ? preg_replace('/\D/', '', (string) $token) : null,
                'marketplace' => filled($marketplace) ? trim((string) $marketplace) : null,
                'revenda' => filled($revenda) ? trim((string) $revenda) : null,
                'documento' => filled($documento) ? DocumentoBrasil::apenasDigitos((string) $documento) : null,
                'nome' => filled($nome) ? trim((string) $nome) : null,
            ];
        }

        return $normalizadas;
    }

    private function normalizarCabecalho(string $chave): string
    {
        $chave = Str::ascii(mb_strtolower(trim($chave)));
        $chave = preg_replace('/\s+/', ' ', $chave) ?? $chave;

        return $chave;
    }

    /**
     * @param  array<string, mixed>  $mapa
     * @param  list<string>  $candidatos
     */
    private function valorMapa(array $mapa, array $candidatos): mixed
    {
        foreach ($candidatos as $candidato) {
            if (array_key_exists($candidato, $mapa) && filled($mapa[$candidato])) {
                return $mapa[$candidato];
            }
        }

        return null;
    }

    private function precarregar(): void
    {
        $this->marketplacesPorNome = [];
        $this->revendasPorNome = [];
        $this->estabelecimentosPorToken = [];
        $this->estabelecimentosPorDocumento = [];

        Usuario::query()
            ->where('tipo', 'marketplace')
            ->with('hierarquia')
            ->get()
            ->each(function (Usuario $usuario) {
                foreach ($this->chavesNomeUsuario($usuario) as $chave) {
                    $this->marketplacesPorNome[$chave] = $usuario;
                    $this->marketplacesPorNome[$this->chaveCompacta($chave)] = $usuario;
                }
            });

        Usuario::query()
            ->where('tipo', 'revenda')
            ->with('hierarquia.pai.usuario')
            ->get()
            ->each(function (Usuario $usuario) {
                foreach ($this->chavesNomeUsuario($usuario) as $chave) {
                    $this->revendasPorNome[$chave] = $usuario;
                    $this->revendasPorNome[$this->chaveCompacta($chave)] = $usuario;
                }
            });

        Estabelecimento::withoutGlobalScopes()
            ->get(['id', 'token_pagseguro', 'cnpj', 'cpf', 'nome_fantasia', 'razao_social', 'nome_completo', 'master_id', 'marketplace_id', 'revenda_id', 'plano_id'])
            ->each(function (Estabelecimento $estab) {
                $token = preg_replace('/\D/', '', (string) $estab->token_pagseguro);
                if ($token !== '') {
                    $this->estabelecimentosPorToken[$token] = $estab;
                }

                $doc = DocumentoBrasil::apenasDigitos((string) ($estab->cnpj ?: $estab->cpf));
                if ($doc !== '') {
                    $this->estabelecimentosPorDocumento[$doc] = $estab;
                }
            });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function criarMarketplacesFaltantes(array $rows, bool $dryRun): void
    {
        $nomes = [];
        foreach ($rows as $row) {
            if (filled($row['marketplace'])) {
                $nomes[trim((string) $row['marketplace'])] = true;
            }
        }

        foreach (array_keys($nomes) as $nome) {
            if ($this->resolverMarketplace($nome)) {
                continue;
            }

            if ($dryRun) {
                continue;
            }

            $usuario = DB::transaction(function () use ($nome) {
                $email = $this->emailSintetico('mkt', $nome);
                $usuario = Usuario::create([
                    'tipo' => 'marketplace',
                    'pessoa_tipo' => 'juridica',
                    'nome_fantasia' => $nome,
                    'razao_social' => $nome,
                    'email' => $email,
                    'password' => Hash::make('123456'),
                    'must_change_password' => true,
                    'ativo' => true,
                ]);

                Hierarquia::create([
                    'usuario_id' => $usuario->id,
                    'pai_id' => null,
                    'nivel' => 'marketplace',
                ]);

                return $usuario->load('hierarquia');
            });

            foreach ($this->chavesNomeUsuario($usuario) as $chave) {
                $this->marketplacesPorNome[$chave] = $usuario;
                $this->marketplacesPorNome[$this->chaveCompacta($chave)] = $usuario;
            }
            foreach ($this->chavesNomeTexto($nome) as $chave) {
                $this->marketplacesPorNome[$chave] = $usuario;
                $this->marketplacesPorNome[$this->chaveCompacta($chave)] = $usuario;
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function criarRevendasFaltantes(array $rows, bool $dryRun): void
    {
        $pares = [];
        foreach ($rows as $row) {
            if (! filled($row['revenda']) || ! filled($row['marketplace'])) {
                continue;
            }
            $chave = trim((string) $row['revenda']).'|'.trim((string) $row['marketplace']);
            $pares[$chave] = [
                'revenda' => trim((string) $row['revenda']),
                'marketplace' => trim((string) $row['marketplace']),
            ];
        }

        foreach ($pares as $par) {
            $marketplace = $this->resolverMarketplace($par['marketplace']);
            if (! $marketplace) {
                continue;
            }

            if ($this->resolverRevenda($par['revenda'], $marketplace)) {
                continue;
            }

            if ($dryRun) {
                continue;
            }

            if (! $marketplace->hierarquia) {
                continue;
            }

            $usuario = DB::transaction(function () use ($par, $marketplace) {
                $email = $this->emailSintetico('rep', $par['revenda']);
                $usuario = Usuario::create([
                    'tipo' => 'revenda',
                    'pessoa_tipo' => 'juridica',
                    'nome_fantasia' => $par['revenda'],
                    'razao_social' => $par['revenda'],
                    'email' => $email,
                    'password' => Hash::make('123456'),
                    'must_change_password' => true,
                    'ativo' => true,
                ]);

                $this->hierarquiaService->criarNo($usuario, $marketplace);

                return $usuario->load('hierarquia.pai.usuario');
            });

            foreach ($this->chavesNomeUsuario($usuario) as $chave) {
                $this->revendasPorNome[$chave] = $usuario;
                $this->revendasPorNome[$this->chaveCompacta($chave)] = $usuario;
            }
            foreach ($this->chavesNomeTexto($par['revenda']) as $chave) {
                $this->revendasPorNome[$chave] = $usuario;
                $this->revendasPorNome[$this->chaveCompacta($chave)] = $usuario;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function processarLinha(array $row, bool $dryRun, bool $onlyEmpty, bool $force): array
    {
        $base = [
            'token' => $row['token'],
            'documento' => $row['documento'],
            'nome_planilha' => $row['nome'],
            'marketplace_planilha' => $row['marketplace'],
            'revenda_planilha' => $row['revenda'],
            'estabelecimento_id' => null,
            'marketplace_id' => null,
            'revenda_id' => null,
            'match' => null,
            'status' => 'erros',
            'mensagem' => '',
        ];

        $estab = $this->resolverEstabelecimento($row);
        if (! $estab) {
            return array_merge($base, [
                'status' => 'estab_nao_encontrado',
                'mensagem' => 'Estabelecimento não encontrado por ID EDI nem documento.',
            ]);
        }

        $base['estabelecimento_id'] = $estab->id;
        $base['match'] = filled($row['token']) && isset($this->estabelecimentosPorToken[$row['token']])
            ? 'token_pagseguro'
            : 'documento';

        if (! filled($row['marketplace'])) {
            return array_merge($base, [
                'status' => 'sem_marketplace_planilha',
                'mensagem' => 'Linha sem marketplace na planilha.',
            ]);
        }

        $marketplace = $this->resolverMarketplace((string) $row['marketplace']);
        if (! $marketplace) {
            return array_merge($base, [
                'status' => 'mkt_faltando',
                'mensagem' => 'Marketplace não encontrado: '.$row['marketplace'],
            ]);
        }

        $base['marketplace_id'] = $marketplace->id;

        $revenda = null;
        if (filled($row['revenda'])) {
            $revenda = $this->resolverRevenda((string) $row['revenda'], $marketplace);
            if (! $revenda) {
                return array_merge($base, [
                    'status' => 'rep_faltando',
                    'mensagem' => 'Revenda não encontrada: '.$row['revenda'],
                ]);
            }
            $base['revenda_id'] = $revenda->id;
        }

        $alvo = $this->hierarquiaService->cadeiaParaEstabelecimento($revenda ?? $marketplace);
        $novoMarketplaceId = $alvo['marketplace_id'] ?? $marketplace->id;
        $novoRevendaId = $alvo['revenda_id'] ?? $revenda?->id;
        $novoMasterId = $alvo['master_id'] ?? null;

        $igual = (int) $estab->marketplace_id === (int) $novoMarketplaceId
            && (int) ($estab->revenda_id ?? 0) === (int) ($novoRevendaId ?? 0)
            && (int) ($estab->master_id ?? 0) === (int) ($novoMasterId ?? 0);

        if ($igual) {
            return array_merge($base, [
                'status' => 'ok',
                'mensagem' => 'Já vinculado corretamente.',
            ]);
        }

        $temVinculo = filled($estab->marketplace_id) || filled($estab->revenda_id);

        if ($temVinculo && $onlyEmpty && ! $force) {
            return array_merge($base, [
                'status' => 'divergente_ignorado',
                'mensagem' => sprintf(
                    'Já tem vínculo (mkt=%s, rev=%s). Use --force para sobrescrever com mkt=%s, rev=%s.',
                    $estab->marketplace_id ?: '—',
                    $estab->revenda_id ?: '—',
                    $novoMarketplaceId ?: '—',
                    $novoRevendaId ?: '—',
                ),
            ]);
        }

        if ($temVinculo && ! $force && ! $onlyEmpty) {
            return array_merge($base, [
                'status' => 'divergente_ignorado',
                'mensagem' => 'Vínculo diferente do da planilha. Use --force para sobrescrever.',
            ]);
        }

        if ($dryRun) {
            return array_merge($base, [
                'status' => 'criariam_vinculo',
                'mensagem' => sprintf(
                    'Seria vinculado a mkt #%s%s.',
                    $novoMarketplaceId,
                    $novoRevendaId ? " / revenda #{$novoRevendaId}" : '',
                ),
            ]);
        }

        try {
            DB::transaction(function () use ($estab, $novoMarketplaceId, $novoRevendaId, $novoMasterId, $alvo) {
                $estab->marketplace_id = $novoMarketplaceId;
                $estab->revenda_id = $novoRevendaId;
                $estab->master_id = $novoMasterId;

                if (! filled($estab->cadastrado_por_id) && filled($alvo['cadastrado_por_id'] ?? null)) {
                    $estab->cadastrado_por_id = $alvo['cadastrado_por_id'];
                    $estab->cadastrado_por_nivel = $alvo['cadastrado_por_nivel'];
                }

                $estab->save();

                if (filled($estab->plano_id)) {
                    $this->royaltyService->fixarCadeia($estab->fresh());
                }
            });

            return array_merge($base, [
                'status' => 'vinculados',
                'mensagem' => sprintf(
                    'Vinculado a mkt #%s%s.',
                    $novoMarketplaceId,
                    $novoRevendaId ? " / revenda #{$novoRevendaId}" : '',
                ),
            ]);
        } catch (\Throwable $e) {
            return array_merge($base, [
                'status' => 'erros',
                'mensagem' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolverEstabelecimento(array $row): ?Estabelecimento
    {
        $token = (string) ($row['token'] ?? '');
        if ($token !== '' && isset($this->estabelecimentosPorToken[$token])) {
            return $this->estabelecimentosPorToken[$token];
        }

        $doc = (string) ($row['documento'] ?? '');
        if ($doc !== '' && isset($this->estabelecimentosPorDocumento[$doc])) {
            return $this->estabelecimentosPorDocumento[$doc];
        }

        return null;
    }

    private function resolverMarketplace(string $nome): ?Usuario
    {
        foreach ($this->chavesBuscaNome($nome) as $chave) {
            if (isset($this->marketplacesPorNome[$chave])) {
                return $this->marketplacesPorNome[$chave];
            }
        }

        $alias = LegacyMarketplaceAlias::nomePlataforma($nome);
        if ($alias !== null) {
            foreach ($this->chavesBuscaNome($alias) as $chave) {
                if (isset($this->marketplacesPorNome[$chave])) {
                    return $this->marketplacesPorNome[$chave];
                }
            }
        }

        return null;
    }

    private function resolverRevenda(string $nome, Usuario $marketplace): ?Usuario
    {
        foreach ($this->chavesBuscaNome($nome) as $chave) {
            if (! isset($this->revendasPorNome[$chave])) {
                continue;
            }

            $revenda = $this->revendasPorNome[$chave];
            $pai = $this->marketplaceDoUsuario($revenda);

            if ($pai && $pai->id === $marketplace->id) {
                return $revenda;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function chavesBuscaNome(string $nome): array
    {
        $chaves = $this->chavesNomeTexto($nome);
        foreach ($chaves as $chave) {
            $chaves[] = $this->chaveCompacta($chave);
        }

        return array_values(array_unique(array_filter($chaves)));
    }

    private function chaveCompacta(string $texto): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(Str::ascii($texto))) ?? '';
    }

    private function emailSintetico(string $prefixo, string $nome): string
    {
        $slug = Str::slug(Str::ascii($nome), '.');
        if ($slug === '') {
            $slug = 'importado';
        }

        $base = strtolower("{$prefixo}.{$slug}");
        $email = $base.'@legacy.local';
        $i = 1;

        while ($this->emailEmUso($email)) {
            $email = $base.'.'.$i.'@legacy.local';
            $i++;
        }

        return $email;
    }
}
