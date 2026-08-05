<?php

namespace App\Http\Controllers\Estabelecimento;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Models\Log;
use App\Rules\CelularValido;
use App\Rules\CpfValido;
use App\Rules\CnpjValido;
use App\Services\AutomacaoLogService;
use App\Models\Plano;
use App\Models\Segmento;
use App\Models\Usuario;
use App\Support\DocumentoBrasil;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\EstabelecimentoSchema;
use App\Support\UsuarioComercial;
use App\Services\AutomacaoPagBankService;
use App\Services\EmailPlataformaService;
use App\Services\HierarquiaService;
use App\Services\KycDocumentoSyncService;
use App\Services\KycFinalizacaoService;
use App\Services\KycInicializacaoService;
use App\Services\LogService;
use App\Services\MarketplacePlanoService;
use App\Services\NotificacaoEmailService;
use App\Services\RoyaltyCalculadorService;
use App\Support\KycDocumentosObrigatorios;
use App\Support\KycTipoDocumentoMapper;
use App\Support\NotificacaoVars;
use App\Support\AutomacaoErroInterpretador;
use App\Support\PlatformSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EstabelecimentoController extends Controller
{
    public function __construct(private MarketplacePlanoService $marketplacePlano) {}
    public function index(Request $request)
    {
        $filtros = $request->only([
            'busca',
            'codigo_edi',
            'master_id',
            'marketplace_id',
            'revenda_id',
            'status',
            'pagbank',
            'risco',
            'plano_id',
            'segmento',
            'pessoa_tipo',
            'ativo',
            'data_inicio',
            'data_fim',
        ]);

        $marketplaceFiltro = (string) ($request->input('marketplace_id') ?? '');
        $revendaFiltro = (string) ($request->input('revenda_id') ?? '');
        $planoFiltro = (string) ($request->input('plano_id') ?? '');
        // Marketplace/revenda por ID também vão pelo Query Builder: evita ativo/scopes
        // esconderem a carteira (ex.: MUNDIAL PAY com 44 no banco e 3 na UI).
        $filtroSemVinculoEspecial = in_array($marketplaceFiltro, ['sem_marketplace', 'sem_vinculo'], true)
            || $revendaFiltro === 'sem_revenda'
            || $planoFiltro === 'sem_plano'
            || ($request->filled('vinculo') && $request->string('vinculo') === 'sem')
            || ($marketplaceFiltro !== '' && ctype_digit($marketplaceFiltro))
            || ($revendaFiltro !== '' && ctype_digit($revendaFiltro));

        // Query Builder puro (igual ao artisan), sem escopos de ativo.
        if ($filtroSemVinculoEspecial) {
            return $this->indexSemVinculoEspecial($request, $filtros, $marketplaceFiltro, $revendaFiltro, $planoFiltro);
        }

        $query = Estabelecimento::withoutGlobalScopes()
            ->with(['marketplace', 'revenda'])
            ->latest('estabelecimentos.id');

        if (! $this->deveIncluirInativosNaListagem($request)) {
            $query->where('estabelecimentos.ativo', true);
        }

        $this->aplicarFiltrosIndex($query, $request);

        return view('estabelecimento.index', [
            'estabelecimentos' => $query->paginate(20)->withQueryString(),
            'filtros' => $filtros,
            'filtrosResumo' => $this->resumoFiltrosAplicados($filtros),
            'masters' => $this->usuariosPorTipo('master'),
            'marketplaces' => $this->usuariosPorTipo('marketplace'),
            'revendas' => $this->usuariosPorTipo('revenda'),
            'planos' => $this->marketplacePlano->planosDisponiveis(),
            'segmentos' => Segmento::where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    /**
     * Listagem à prova de escopo para Sem marketplace / Sem revenda / Sem plano.
     */
    private function indexSemVinculoEspecial(
        Request $request,
        array $filtros,
        string $marketplaceFiltro,
        string $revendaFiltro,
        string $planoFiltro,
    ) {
        $base = DB::table('estabelecimentos')->orderByDesc('id');

        if ($marketplaceFiltro === 'sem_vinculo' || ($request->filled('vinculo') && $request->string('vinculo') === 'sem')) {
            $base->whereNull('marketplace_id')->whereNull('revenda_id');
        } elseif ($marketplaceFiltro === 'sem_marketplace') {
            $base->whereNull('marketplace_id');
        } elseif ($marketplaceFiltro !== '' && ctype_digit($marketplaceFiltro)) {
            $marketplaceId = (int) $marketplaceFiltro;
            $marketplace = Usuario::withoutGlobalScopes()->find($marketplaceId);
            $revendaIds = $marketplace
                ? UsuarioComercial::revendasDo($marketplace)->pluck('id')->all()
                : [];

            $base->where(function (QueryBuilder $q) use ($marketplaceId, $revendaIds) {
                $q->where('marketplace_id', $marketplaceId);
                if ($revendaIds !== []) {
                    $q->orWhereIn('revenda_id', $revendaIds);
                }
            });
        }

        if ($revendaFiltro === 'sem_revenda') {
            $base->whereNull('revenda_id');
        } elseif ($revendaFiltro !== '' && ctype_digit($revendaFiltro)) {
            $base->where('revenda_id', (int) $revendaFiltro);
        }

        if ($planoFiltro === 'sem_plano') {
            $base->whereNull('plano_id');
        }

        $actor = UsuarioComercial::principal();
        if ($actor && $actor->tipo !== 'admin') {
            match ($actor->tipo) {
                'master' => $base->where('master_id', $actor->id),
                'marketplace' => $base->where('marketplace_id', $actor->id),
                'revenda' => $base->where('revenda_id', $actor->id),
                default => null,
            };
        }

        $this->aplicarFiltrosIndexSemVinculoQuery($base, $request);

        $paginator = $base->paginate(20)->withQueryString();
        $ids = collect($paginator->items())->pluck('id')->all();

        $models = $ids === []
            ? collect()
            : Estabelecimento::withoutGlobalScopes()
                ->with(['marketplace', 'revenda'])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Estabelecimento $e) => array_search($e->id, $ids, true))
                ->values();

        $paginator->setCollection($models);

        return view('estabelecimento.index', [
            'estabelecimentos' => $paginator,
            'filtros' => $filtros,
            'filtrosResumo' => $this->resumoFiltrosAplicados($filtros),
            'masters' => $this->usuariosPorTipo('master'),
            'marketplaces' => $this->usuariosPorTipo('marketplace'),
            'revendas' => $this->usuariosPorTipo('revenda'),
            'planos' => $this->marketplacePlano->planosDisponiveis(),
            'segmentos' => Segmento::where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function create(Request $request)
    {
        $this->autorizarMutacaoEstabelecimento();

        $estabelecimento = new Estabelecimento;
        $digits = preg_replace('/\D/', '', (string) $request->query('documento', ''));

        if (strlen($digits) === 14) {
            $estabelecimento->pessoa_tipo = 'juridica';
            $estabelecimento->cnpj = $digits;
        } elseif (strlen($digits) === 11) {
            $estabelecimento->pessoa_tipo = 'fisica';
            $estabelecimento->cpf = $digits;
        }

        return view('estabelecimento.form', [
            'estabelecimento' => $estabelecimento,
            'planos' => $this->planosParaFormulario($request),
            'segmentos' => Segmento::where('ativo', true)->orderBy('nome')->get(),
            'prefillDocumento' => in_array(strlen($digits), [11, 14], true),
            ...$this->opcoesFormulario(),
        ]);
    }

    public function store(
        Request $request,
        RoyaltyCalculadorService $royalties,
        HierarquiaService $hierarquia,
        EmailPlataformaService $emailPlataforma,
        KycFinalizacaoService $kycFinalizacao,
    )
    {
        $this->autorizarMutacaoEstabelecimento();

        if (UsuarioComercial::deveEscolherModoCadastro()) {
            $request->validate([
                'modo_cadastro' => ['required', 'in:completo,apenas_dados'],
            ]);
        }

        $modoCadastro = UsuarioComercial::deveEscolherModoCadastro()
            ? $request->string('modo_cadastro')->toString()
            : 'completo';

        $dados = $this->validar($request);
        $usuario = $request->user();
        $dados = array_merge(
            $dados,
            $hierarquia->cadeiaParaEstabelecimento(UsuarioComercial::principal() ?? $usuario),
            $this->hierarquiaSelecionada($dados),
            $this->ipCadastro($request),
        );
        $dados = $this->aplicarHierarquiaPorPerfil($dados);

        $estabelecimento = Estabelecimento::create($dados);
        $royalties->fixarCadeia($estabelecimento->load('plano.taxas.royalties'));

        if ($modoCadastro === 'apenas_dados') {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->with('status', 'Estabelecimento cadastrado. E-mail e automação não foram iniciados — você pode acioná-los depois na aba Automação.');
        }

        if (filled($estabelecimento->email)) {
            app(NotificacaoEmailService::class)->enfileirar(
                'estabelecimento.cadastro',
                $estabelecimento->email,
                NotificacaoVars::estabelecimento($estabelecimento),
                route('estabelecimentos.show', $estabelecimento)
            );
        }

        $avisoEmail = null;

        if (config('directadmin.criar_email_ao_habilitar') && filled($estabelecimento->email)) {
            try {
                $usernameOcupado = $emailPlataforma->provisionarAutomatico($estabelecimento);
                if ($usernameOcupado !== null) {
                    $avisoEmail = "O username \"{$usernameOcupado}\" já existe no servidor de e-mail. Acesse o cadastro para definir manualmente.";
                }
            } catch (\Throwable) {
                $avisoEmail = 'Não foi possível criar o e-mail da plataforma automaticamente. Acesse o cadastro para tentar novamente.';
            }
        }

        $estabelecimento = $estabelecimento->fresh();
        $usuarioComercial = UsuarioComercial::principal() ?? $usuario;
        $automacaoEnfileirada = $kycFinalizacao->aprovarAutomaticoNoCadastro(
            $estabelecimento,
            $usuarioComercial instanceof Usuario ? $usuarioComercial : null,
        );

        $mensagem = 'Estabelecimento cadastrado e aprovado automaticamente.';
        if ($automacaoEnfileirada) {
            $mensagem .= ' Automação PagBank enfileirada.';
        } elseif (blank($estabelecimento->webmail_email)) {
            $mensagem .= ' Configure o e-mail da plataforma para iniciar a automação.';
        }

        $redirect = redirect()->route('estabelecimentos.show', $estabelecimento)->with('status', $mensagem);

        if ($avisoEmail) {
            $redirect = $redirect->with('aviso', $avisoEmail);
        }

        return $redirect;
    }

    public function edit(Request $request, Estabelecimento $estabelecimento)
    {
        $this->autorizarMutacaoEstabelecimento();

        return view('estabelecimento.form', [
            'estabelecimento' => $estabelecimento,
            'planos' => $this->planosParaFormulario($request, $estabelecimento),
            'segmentos' => Segmento::where('ativo', true)->orderBy('nome')->get(),
            ...$this->opcoesFormulario(),
        ]);
    }

    public function update(Request $request, Estabelecimento $estabelecimento)
    {
        $this->autorizarMutacaoEstabelecimento();

        $estabelecimento->update(array_merge(
            $this->aplicarHierarquiaPorPerfil($this->validar($request)),
            $this->ipCadastro($request, $estabelecimento),
        ));

        return redirect()->route('estabelecimentos.index')->with('status', 'Estabelecimento atualizado.');
    }

    public function show(Estabelecimento $estabelecimento, KycInicializacaoService $kycInicializacao, KycDocumentoSyncService $kycSync, AutomacaoLogService $automacaoLogService)
    {
        if (blank($estabelecimento->documento_token_publico)) {
            $estabelecimento->forceFill(['documento_token_publico' => (string) Str::uuid()])->save();
        }

        $estabelecimento->load(['master', 'marketplace', 'revenda', 'plano', 'documentos', 'emails', 'kycAnalise']);

        // KYC — inicializa e sincroniza para a aba inline
        $kyc = $kycInicializacao->iniciar($estabelecimento);
        $kycSync->sincronizarTodosDoEstabelecimento($estabelecimento);
        $kyc->load(['documentos', 'historico']);

        $kycItens = collect(KycTipoDocumentoMapper::tiposEstabelecimento($estabelecimento->pessoa_tipo))
            ->map(function (string $label) use ($estabelecimento, $kyc) {
                $tipoKyc  = KycTipoDocumentoMapper::tipoKyc($label);
                $estabDoc = $estabelecimento->documentos->keyBy('tipo_documento')->get($label);
                $kycDoc   = $tipoKyc ? $kyc->documentos->keyBy('tipo')->get($tipoKyc) : null;

                return compact('label', 'tipoKyc', 'estabDoc', 'kycDoc');
            });

        $logs = Log::where('entidade', 'Estabelecimento')
            ->where('entidade_id', $estabelecimento->id)
            ->latest()
            ->take(10)
            ->get();

        $automacaoLogs = $automacaoLogService->listarParaEstabelecimento($estabelecimento->id);
        $automacaoLogsIndisponivel = ! \App\Support\AutomacaoSchema::temTabelaLogs();

        $automacaoPreview = null;
        if (
            PlatformSettings::automacaoConfigurado()
            && UsuarioComercial::podeGerenciarAutomacaoEstabelecimento($estabelecimento)
        ) {
            try {
                $automacaoPreview = app(AutomacaoPagBankService::class)->previewConfirmacao($estabelecimento);
            } catch (\Throwable) {
                $automacaoPreview = null;
            }
        }

        $automacaoErro = null;
        if (filled($estabelecimento->fv_erro) && in_array($estabelecimento->fv_status, ['erro', 'timeout', 'erro_email'], true)) {
            $ultimoLogErro = $automacaoLogs->filter(fn ($log) => AutomacaoErroInterpretador::logPareceErro($log))->last();
            $contexto = is_array($ultimoLogErro?->detalhe) ? $ultimoLogErro->detalhe : null;
            if ($ultimoLogErro && filled($ultimoLogErro->etapa)) {
                $contexto = array_merge($contexto ?? [], ['etapa_log' => $ultimoLogErro->etapa]);
            }
            $automacaoErro = AutomacaoErroInterpretador::interpretar(
                $estabelecimento->fv_erro,
                $contexto,
                $automacaoLogs,
            );
        }

        return view('estabelecimento.show', compact(
            'estabelecimento',
            'logs',
            'automacaoLogs',
            'automacaoLogsIndisponivel',
            'kyc',
            'kycItens',
            'automacaoPreview',
            'automacaoErro',
        ) + [
            'kycAtivo'           => PlatformSettings::kycAtivo(),
            'ppidConfigurado'  => PlatformSettings::ppidConfigurado(),
        ]);
    }

    public function updateStatus(Request $request, Estabelecimento $estabelecimento, LogService $log)
    {
        $dados = $request->validate([
            'status' => ['required', 'in:pendente,aprovado,negado'],
            'pagbank_status_manual' => ['nullable', 'in:pendente,aprovado,negado'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        $anterior = $estabelecimento->status;
        $pagbankManualAnterior = EstabelecimentoSchema::temPagbankStatusManual()
            ? $estabelecimento->pagbank_status_manual
            : null;
        $pagbankManual = $dados['pagbank_status_manual'] ?? null;

        $update = [
            'status' => EstabelecimentoSchema::statusParaBanco($dados['status']),
            'anotacoes_interno' => trim(($estabelecimento->anotacoes_interno ? $estabelecimento->anotacoes_interno.PHP_EOL.PHP_EOL : '').($dados['observacao'] ?? '')),
        ];

        if (EstabelecimentoSchema::temPagbankStatusManual()) {
            $update['pagbank_status_manual'] = $pagbankManual;
        }

        $estabelecimento->update($update);

        $redirect = redirect()->route('estabelecimentos.show', $estabelecimento)
            ->with('status', 'Status alterado com sucesso.');

        if (! EstabelecimentoSchema::temPagbankStatusManual() && filled($pagbankManual)) {
            $redirect->with('aviso', 'Status operacional salvo. O override manual do PagBank exige a migration pendente no servidor (php artisan migrate --force).');
        }

        $log->registrar(
            'Estabelecimento',
            $estabelecimento->id,
            'update_status',
            'Status alterado com sucesso',
            [
                'status' => $anterior,
                'pagbank_status_manual' => $pagbankManualAnterior,
            ],
            [
                'status' => $dados['status'],
                'pagbank_status_manual' => $pagbankManual,
                'observacao' => $dados['observacao'] ?? null,
            ],
        );

        return $redirect;
    }

    public function inativarSistema(Request $request, Estabelecimento $estabelecimento, LogService $log)
    {
        abort_unless($request->user()?->tipo === 'admin', 403);

        if (! $estabelecimento->ativo) {
            return redirect()
                ->route('estabelecimentos.index')
                ->with('aviso', 'Este cadastro já está inativo no sistema.');
        }

        $dados = $request->validate([
            'senha_admin' => ['required', 'string'],
            'confirmacao' => ['accepted'],
        ], [
            'senha_admin.required' => 'Informe sua senha de administrador.',
            'confirmacao.accepted' => 'Confirme que deseja inativar este cadastro.',
        ]);

        if (! Hash::check($dados['senha_admin'], $request->user()->password)) {
            return redirect()
                ->route('estabelecimentos.show', $estabelecimento)
                ->withErrors(['senha_admin' => 'Senha de administrador incorreta.'])
                ->with('abrir_modal_inativar', true);
        }

        $statusAnterior = $estabelecimento->status;

        $estabelecimento->update([
            'status' => EstabelecimentoSchema::statusParaBanco(EstabelecimentoEtapaListagem::NEGADO),
            'ativo'  => false,
        ]);

        $log->registrar(
            'Estabelecimento',
            $estabelecimento->id,
            'inativar_sistema',
            'Cadastro inativado no sistema (soft delete)',
            ['status' => $statusAnterior, 'ativo' => true],
            ['status' => 'negado', 'ativo' => false],
        );

        return redirect()
            ->route('estabelecimentos.index')
            ->with('status', 'Cadastro inativado no sistema. O registro foi preservado e não aparece mais nas listagens.');
    }

    private function autorizarMutacaoEstabelecimento(): void
    {
        abort_unless(UsuarioComercial::podeCadastrarEstabelecimento(), 403, 'Seu perfil não pode cadastrar ou alterar estabelecimentos.');
    }

    private function validar(Request $request): array
    {
        $pessoaTipo = $request->input('pessoa_tipo');

        $dados = $request->validate([
            'pessoa_tipo' => ['required', 'in:juridica,fisica'],
            'cnpj' => [
                Rule::requiredIf($pessoaTipo === 'juridica'),
                'nullable',
                'string',
                'max:18',
                new CnpjValido,
            ],
            'cpf' => [
                Rule::requiredIf($pessoaTipo === 'fisica'),
                'nullable',
                'string',
                'max:14',
                new CpfValido,
            ],
            'razao_social' => ['nullable', 'string', 'max:200'],
            'inscricao_estadual' => ['nullable', 'string', 'max:30'],
            'data_abertura' => ['nullable', 'date'],
            'nome_completo' => ['nullable', 'string', 'max:200'],
            'data_nascimento' => ['nullable', 'date'],
            'nome_fantasia' => ['nullable', 'string', 'max:200'],
            'segmento' => ['nullable', 'string', 'max:200'],
            'faturamento_mensal' => ['nullable', 'string', 'in:De R$ 1 mil até R$ 5 mil,De R$ 5 mil até R$ 10 mil,Acima de R$ 10 mil'],
            'rep_nome' => ['nullable', 'string', 'max:200'],
            'rep_cpf' => [
                Rule::requiredIf($pessoaTipo === 'juridica'),
                'nullable',
                'string',
                'max:14',
                new CpfValido,
            ],
            'rep_data_nascimento' => ['nullable', 'date'],
            'email' => ['required', 'email', 'max:150'],
            'celular' => ['required', 'string', 'max:16', new CelularValido],
            'cep' => ['nullable', 'string', 'max:9'],
            'endereco' => ['nullable', 'string', 'max:200'],
            'numero' => ['nullable', 'string', 'max:10'],
            'sem_numero' => ['nullable', 'boolean'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'token_pagseguro' => ['nullable', 'string', 'max:255'],
            'plano_id' => ['nullable', 'exists:planos,id'],
            'master_id' => ['nullable', Rule::exists('usuarios', 'id')->where('tipo', 'master')],
            'marketplace_id' => ['nullable', Rule::exists('usuarios', 'id')->where('tipo', 'marketplace')],
            'revenda_id' => ['nullable', Rule::exists('usuarios', 'id')->where('tipo', 'revenda')],
            'subdominio' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('estabelecimentos', 'subdominio')->ignore($request->route('estabelecimento')),
            ],
            'status' => ['nullable', 'in:pendente,aprovado,negado'],
            'risco' => ['nullable', 'in:confiavel,atencao,bloqueado'],
            'anotacoes' => ['nullable', 'string'],
            'anotacoes_interno' => ['nullable', 'string'],
            'ativo' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('sem_numero')) {
            $dados['numero'] = '00';
        }

        unset($dados['sem_numero']);

        if (filled($dados['cpf'] ?? null)) {
            $dados['cpf'] = DocumentoBrasil::formatarCpf($dados['cpf']);
        }

        if (filled($dados['cnpj'] ?? null)) {
            $dados['cnpj'] = DocumentoBrasil::formatarCnpj($dados['cnpj']);
        }

        if (filled($dados['rep_cpf'] ?? null)) {
            $dados['rep_cpf'] = DocumentoBrasil::formatarCpf($dados['rep_cpf']);
        }

        if (filled($dados['celular'] ?? null)) {
            $dados['celular'] = DocumentoBrasil::formatarCelular($dados['celular']);
        }

        if (filled($dados['plano_id'] ?? null)) {
            $marketplaceId = filled($request->input('marketplace_id'))
                ? $request->integer('marketplace_id')
                : ($dados['marketplace_id']
                    ?? $request->route('estabelecimento')?->marketplace_id
                    ?? $this->marketplacePlano->marketplaceDoUsuario(UsuarioComercial::principal())?->id);

            abort_unless(
                $this->marketplacePlano->planoPermitido((int) $dados['plano_id'], UsuarioComercial::principal(), $marketplaceId),
                422,
                'Plano não disponível para este marketplace.'
            );
        }

        return $dados;
    }

    private function ipCadastro(Request $request, ?Estabelecimento $estabelecimento = null): array
    {
        if ($estabelecimento?->ip_cadastro) {
            return [];
        }

        $ip = $request->ip();

        return $ip ? ['ip_cadastro' => $ip] : [];
    }

    private function opcoesFormulario(): array
    {
        if (UsuarioComercial::ehRevenda()) {
            return [
                'gestores' => collect(),
                'representantes' => collect(),
                'revendas' => collect(),
            ];
        }

        if (UsuarioComercial::ehMarketplace()) {
            $marketplace = UsuarioComercial::principal();

            return [
                'gestores' => collect(),
                'representantes' => collect(),
                'revendas' => $marketplace
                    ? UsuarioComercial::revendasDo($marketplace)
                        ->where('ativo', true)
                        ->orderBy('nome_fantasia')
                        ->orderBy('razao_social')
                        ->orderBy('nome_completo')
                        ->get()
                    : collect(),
            ];
        }

        return [
            'gestores' => Usuario::where('tipo', 'master')->where('ativo', true)->orderBy('nome_fantasia')->orderBy('razao_social')->orderBy('nome_completo')->get(),
            'representantes' => Usuario::where('tipo', 'marketplace')->where('ativo', true)->orderBy('nome_fantasia')->orderBy('razao_social')->orderBy('nome_completo')->get(),
            'revendas' => Usuario::where('tipo', 'revenda')->where('ativo', true)->orderBy('nome_fantasia')->orderBy('razao_social')->orderBy('nome_completo')->get(),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function aplicarHierarquiaPorPerfil(array $dados): array
    {
        if (UsuarioComercial::ehAdmin()) {
            return $dados;
        }

        $cadeia = app(HierarquiaService::class)->cadeiaParaEstabelecimento(UsuarioComercial::principal());

        if (UsuarioComercial::ehRevenda()) {
            unset($dados['master_id'], $dados['marketplace_id'], $dados['revenda_id']);

            return array_merge($dados, array_filter([
                'master_id' => $cadeia['master_id'],
                'marketplace_id' => $cadeia['marketplace_id'],
                'revenda_id' => $cadeia['revenda_id'],
            ]));
        }

        if (UsuarioComercial::ehMarketplace()) {
            unset($dados['master_id'], $dados['marketplace_id']);

            $dados['master_id'] = $cadeia['master_id'];
            $dados['marketplace_id'] = $cadeia['marketplace_id'];

            if (filled($dados['revenda_id'] ?? null)) {
                $this->validarRevendaDoMarketplace((int) $dados['revenda_id']);
            } else {
                unset($dados['revenda_id']);
            }
        }

        return $dados;
    }

    private function validarRevendaDoMarketplace(int $revendaId): void
    {
        $marketplace = UsuarioComercial::principal();

        abort_unless(
            $marketplace && UsuarioComercial::revendasDo($marketplace)->whereKey($revendaId)->exists(),
            422,
            'Revenda não pertence a este marketplace.'
        );
    }

    private function planosParaFormulario(Request $request, ?Estabelecimento $estabelecimento = null)
    {
        $marketplaceId = $request->integer('marketplace_id')
            ?: $estabelecimento?->marketplace_id
            ?: old('marketplace_id');

        return $this->marketplacePlano->planosDisponiveis(
            UsuarioComercial::principal(),
            $marketplaceId ?: null,
        );
    }

    private function hierarquiaSelecionada(array $dados): array
    {
        return array_filter([
            'master_id' => $dados['master_id'] ?? null,
            'marketplace_id' => $dados['marketplace_id'] ?? null,
            'revenda_id' => $dados['revenda_id'] ?? null,
        ]);
    }

    /**
     * Filtros auxiliares no Query Builder (sem Eloquent/global scopes).
     * Não reaplica marketplace/revenda/plano especiais (já aplicados no caminho isolado).
     */
    private function aplicarFiltrosIndexSemVinculoQuery(QueryBuilder $query, Request $request): void
    {
        if ($request->filled('codigo_edi')) {
            $codigo = trim((string) $request->input('codigo_edi'));
            $query->where('token_pagseguro', 'like', '%'.$codigo.'%');
        }

        if ($request->filled('busca')) {
            $termo = trim((string) $request->input('busca'));
            $like = '%'.mb_strtolower($termo).'%';
            $digitos = DocumentoBrasil::apenasDigitos($termo);
            $idBusca = ltrim($termo, '#');
            $buscaPorId = $idBusca !== '' && ctype_digit($idBusca);

            $query->where(function (QueryBuilder $q) use ($like, $digitos, $termo, $buscaPorId, $idBusca) {
                $q->whereRaw('LOWER(COALESCE(nome_fantasia, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(razao_social, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(nome_completo, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(cidade, "")) LIKE ?', [$like])
                    ->orWhere('token_pagseguro', $termo);

                if ($buscaPorId) {
                    $q->orWhere('id', (int) $idBusca);
                }

                if ($digitos !== '') {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '/', ''), '-', '') LIKE ?",
                        ['%'.$digitos.'%'],
                    )->orWhereRaw(
                        "REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', '') LIKE ?",
                        ['%'.$digitos.'%'],
                    );
                }
            });
        }

        if ($request->filled('master_id')) {
            $query->where('master_id', $request->integer('master_id'));
        }

        if ($request->filled('status') || $request->filled('pagbank')) {
            $eloquent = Estabelecimento::withoutGlobalScopes()->newQuery();
            $this->aplicarFiltrosSituacao($eloquent, $request);
            $query->whereIn('id', $eloquent->select('id'));
        }

        if ($request->filled('risco')) {
            $query->where('risco', $request->string('risco'));
        }

        if ($request->filled('plano_id')) {
            $planoFiltro = (string) $request->input('plano_id');
            if ($planoFiltro !== 'sem_plano' && ctype_digit($planoFiltro)) {
                $query->where('plano_id', (int) $planoFiltro);
            }
        }

        if ($request->filled('segmento')) {
            $query->where('segmento', $request->string('segmento'));
        }

        if ($request->filled('pessoa_tipo')) {
            $query->where('pessoa_tipo', $request->string('pessoa_tipo'));
        }

        if ($request->filled('ativo') && in_array((string) $request->input('ativo'), ['0', '1'], true)) {
            $query->where('ativo', $request->boolean('ativo'));
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->date('data_inicio'));
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->date('data_fim'));
        }
    }

    private function aplicarFiltrosIndex(Builder $query, Request $request): void
    {
        if ($request->filled('codigo_edi')) {
            $codigo = trim((string) $request->input('codigo_edi'));
            $query->where('token_pagseguro', 'like', '%'.$codigo.'%');
        }

        if ($request->filled('busca')) {
            $termo = trim((string) $request->input('busca'));
            $like = '%'.mb_strtolower($termo).'%';
            $digitos = DocumentoBrasil::apenasDigitos($termo);
            $idBusca = ltrim($termo, '#');
            $buscaPorId = $idBusca !== '' && ctype_digit($idBusca);

            $query->where(function (Builder $q) use ($like, $digitos, $termo, $buscaPorId, $idBusca) {
                $q->whereRaw('LOWER(COALESCE(nome_fantasia, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(razao_social, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(nome_completo, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(cidade, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(bairro, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(segmento, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(webmail_email, "")) LIKE ?', [$like])
                    ->orWhere('token_pagseguro', $termo);

                if ($buscaPorId) {
                    $q->orWhere('estabelecimentos.id', (int) $idBusca);
                }

                if ($digitos !== '') {
                    $q->orWhere('token_pagseguro', 'like', '%'.$digitos.'%')
                        ->orWhereRaw(
                            "REPLACE(REPLACE(REPLACE(COALESCE(cnpj, ''), '.', ''), '/', ''), '-', '') LIKE ?",
                            ['%'.$digitos.'%'],
                        )->orWhereRaw(
                            "REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', '') LIKE ?",
                            ['%'.$digitos.'%'],
                        );
                }

                $this->aplicarBuscaUsuarioRelacionado($q, 'marketplace', $like);
                $this->aplicarBuscaUsuarioRelacionado($q, 'revenda', $like);
                $this->aplicarBuscaUsuarioRelacionado($q, 'master', $like);
            });
        }

        if ($request->filled('master_id')) {
            $query->where('master_id', $request->integer('master_id'));
        }

        $this->aplicarFiltroMarketplaceRevenda($query, $request);

        $this->aplicarFiltrosSituacao($query, $request);

        if ($request->filled('risco')) {
            $query->where('risco', $request->string('risco'));
        }

        if ($request->filled('plano_id')) {
            $planoFiltro = (string) $request->input('plano_id');
            if ($planoFiltro === 'sem_plano') {
                $query->whereNull('plano_id');
            } elseif (ctype_digit($planoFiltro)) {
                $query->where('plano_id', (int) $planoFiltro);
            }
        }

        if ($request->filled('segmento')) {
            $query->where('segmento', $request->string('segmento'));
        }

        if ($request->filled('pessoa_tipo')) {
            $query->where('pessoa_tipo', $request->string('pessoa_tipo'));
        }

        if ($request->has('ativo') && $request->input('ativo') !== '') {
            $query->where('estabelecimentos.ativo', $request->boolean('ativo'));
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->date('data_inicio'));
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->date('data_fim'));
        }
    }

    private function deveIncluirInativosNaListagem(Request $request): bool
    {
        $marketplaceFiltro = (string) ($request->input('marketplace_id') ?? '');
        $revendaFiltro = (string) ($request->input('revenda_id') ?? '');
        $planoFiltro = (string) ($request->input('plano_id') ?? '');
        $masterFiltro = (string) ($request->input('master_id') ?? '');
        $status = (string) ($request->input('status') ?? '');
        $pagbank = (string) ($request->input('pagbank') ?? '');

        if (in_array($marketplaceFiltro, ['sem_marketplace', 'sem_vinculo'], true)) {
            return true;
        }

        // Filtro por marketplace/revenda/master específico: mostra a carteira completa
        // (incluindo negados/inativos). Sem isso o admin vê só ativo=1 e parece que o filtro falhou.
        if ($marketplaceFiltro !== '' && ctype_digit($marketplaceFiltro)) {
            return true;
        }

        if ($revendaFiltro === 'sem_revenda') {
            return true;
        }

        if ($revendaFiltro !== '' && ctype_digit($revendaFiltro)) {
            return true;
        }

        if ($masterFiltro !== '' && ctype_digit($masterFiltro)) {
            return true;
        }

        if ($planoFiltro === 'sem_plano') {
            return true;
        }

        if ($request->filled('vinculo') && $request->string('vinculo') === 'sem') {
            return true;
        }

        // Pendente/negado: inclui inativos (negados costumam estar ativo=0).
        if (in_array($status, ['pendente', 'negado'], true)) {
            return true;
        }

        if (in_array($pagbank, ['pendente', 'negado'], true)) {
            return true;
        }

        return $request->has('ativo')
            && $request->input('ativo') !== ''
            && ! $request->boolean('ativo');
    }

    private function aplicarFiltroMarketplaceRevenda(Builder $query, Request $request): void
    {
        $marketplaceFiltro = (string) ($request->input('marketplace_id') ?? '');
        $revendaFiltro = (string) ($request->input('revenda_id') ?? '');
        $semVinculo = $marketplaceFiltro === 'sem_vinculo'
            || ($request->filled('vinculo') && $request->string('vinculo') === 'sem');

        if ($semVinculo) {
            $query->whereNull('estabelecimentos.marketplace_id')
                ->whereNull('estabelecimentos.revenda_id');

            return;
        }

        if ($marketplaceFiltro === 'sem_marketplace') {
            $query->whereNull('estabelecimentos.marketplace_id');
        } elseif ($marketplaceFiltro !== '' && ctype_digit($marketplaceFiltro)) {
            $marketplaceId = (int) $marketplaceFiltro;
            $marketplace = Usuario::query()->find($marketplaceId);
            $revendaIds = $marketplace
                ? UsuarioComercial::revendasDo($marketplace)->pluck('id')->all()
                : [];

            // Carteira do marketplace = vínculo direto OU via revenda filha.
            $query->where(function (Builder $q) use ($marketplaceId, $revendaIds) {
                $q->where('estabelecimentos.marketplace_id', $marketplaceId);
                if ($revendaIds !== []) {
                    $q->orWhereIn('estabelecimentos.revenda_id', $revendaIds);
                }
            });
        }

        if ($revendaFiltro === 'sem_revenda') {
            $query->whereNull('estabelecimentos.revenda_id');
        } elseif ($revendaFiltro !== '' && ctype_digit($revendaFiltro)) {
            $query->where('estabelecimentos.revenda_id', (int) $revendaFiltro);
        }
    }

    private function aplicarFiltrosSituacao(Builder $query, Request $request): void
    {
        $status = $request->filled('status') && in_array($request->string('status'), ['pendente', 'aprovado', 'negado'], true)
            ? $request->string('status')->toString()
            : null;
        $pagbank = $request->filled('pagbank') && in_array($request->string('pagbank'), ['pendente', 'aprovado', 'negado'], true)
            ? $request->string('pagbank')->toString()
            : null;

        // Cada select filtra só a própria coluna/badge — sem OR entre cadastro e PagBank.
        if ($status) {
            EstabelecimentoEtapaListagem::aplicarFiltroStatus($query, $status);
        }

        if ($pagbank) {
            EstabelecimentoEtapaListagem::aplicarFiltroPagBank($query, $pagbank);
        }
    }

    /**
     * @return list<array{chave: string, label: string, url: string}>
     */
    private function resumoFiltrosAplicados(array $filtros): array
    {
        $rotulos = [
            'status' => [
                'pendente' => 'Cadastro pendente',
                'aprovado' => 'Cadastro aprovado',
                'negado' => 'Cadastro negado',
            ],
            'pagbank' => [
                'pendente' => 'PagBank pendente',
                'aprovado' => 'PagBank aprovado',
                'negado' => 'PagBank negado',
            ],
        ];

        $resumo = [];

        foreach (['busca', 'codigo_edi', 'status', 'pagbank', 'risco', 'segmento', 'pessoa_tipo', 'ativo', 'data_inicio', 'data_fim'] as $chave) {
            $valor = $filtros[$chave] ?? null;
            if ($valor === null || $valor === '') {
                continue;
            }

            $label = match ($chave) {
                'busca' => 'Busca: '.$valor,
                'codigo_edi' => 'EDI: '.$valor,
                'status' => $rotulos['status'][$valor] ?? 'Status: '.$valor,
                'pagbank' => $rotulos['pagbank'][$valor] ?? 'PagBank: '.$valor,
                'risco' => 'Risco: '.$valor,
                'segmento' => 'Segmento: '.$valor,
                'pessoa_tipo' => 'Pessoa: '.($valor === 'juridica' ? 'Jurídica' : 'Física'),
                'ativo' => 'Cadastro ativo: '.($valor === '1' || $valor === 1 || $valor === true ? 'Sim' : 'Não'),
                'data_inicio' => 'Desde: '.$valor,
                'data_fim' => 'Até: '.$valor,
                default => $chave.': '.$valor,
            };

            $resumo[] = [
                'chave' => $chave,
                'label' => $label,
                'url' => $this->urlSemFiltro($filtros, $chave),
            ];
        }

        $marketplaceFiltro = (string) ($filtros['marketplace_id'] ?? '');
        if ($marketplaceFiltro === 'sem_marketplace') {
            $resumo[] = [
                'chave' => 'marketplace_id',
                'label' => 'Sem marketplace',
                'url' => $this->urlSemFiltro($filtros, 'marketplace_id'),
            ];
        } elseif ($marketplaceFiltro === 'sem_vinculo') {
            $resumo[] = [
                'chave' => 'marketplace_id',
                'label' => 'Sem marketplace e sem revenda',
                'url' => $this->urlSemFiltro($filtros, 'marketplace_id'),
            ];
        } elseif ($marketplaceFiltro !== '' && ctype_digit($marketplaceFiltro)) {
            $nome = Usuario::query()->find((int) $marketplaceFiltro)?->nomeExibicao() ?? '#'.$marketplaceFiltro;
            $resumo[] = [
                'chave' => 'marketplace_id',
                'label' => 'Marketplace: '.$nome,
                'url' => $this->urlSemFiltro($filtros, 'marketplace_id'),
            ];
        }

        $masterId = $filtros['master_id'] ?? null;
        if ($masterId !== null && $masterId !== '') {
            $nome = Usuario::query()->find((int) $masterId)?->nomeExibicao() ?? '#'.$masterId;
            $resumo[] = [
                'chave' => 'master_id',
                'label' => 'Master: '.$nome,
                'url' => $this->urlSemFiltro($filtros, 'master_id'),
            ];
        }

        $revendaFiltro = (string) ($filtros['revenda_id'] ?? '');
        if ($revendaFiltro === 'sem_revenda') {
            $resumo[] = [
                'chave' => 'revenda_id',
                'label' => 'Sem revenda',
                'url' => $this->urlSemFiltro($filtros, 'revenda_id'),
            ];
        } elseif ($revendaFiltro !== '' && ctype_digit($revendaFiltro)) {
            $nome = Usuario::query()->find((int) $revendaFiltro)?->nomeExibicao() ?? '#'.$revendaFiltro;
            $resumo[] = [
                'chave' => 'revenda_id',
                'label' => 'Revenda: '.$nome,
                'url' => $this->urlSemFiltro($filtros, 'revenda_id'),
            ];
        }

        $planoFiltro = (string) ($filtros['plano_id'] ?? '');
        if ($planoFiltro === 'sem_plano') {
            $resumo[] = [
                'chave' => 'plano_id',
                'label' => 'Sem plano',
                'url' => $this->urlSemFiltro($filtros, 'plano_id'),
            ];
        } elseif ($planoFiltro !== '' && ctype_digit($planoFiltro)) {
            $nome = Plano::query()->find((int) $planoFiltro)?->nome ?? '#'.$planoFiltro;
            $resumo[] = [
                'chave' => 'plano_id',
                'label' => 'Plano: '.$nome,
                'url' => $this->urlSemFiltro($filtros, 'plano_id'),
            ];
        }

        return $resumo;
    }

    /**
     * @return array<string, mixed>
     */
    private function urlSemFiltro(array $filtros, string $chave): string
    {
        $params = collect($filtros)
            ->except($chave)
            ->filter(fn ($valor) => $valor !== null && $valor !== '')
            ->all();

        return route('estabelecimentos.index', $params);
    }

    private function aplicarBuscaUsuarioRelacionado(Builder $query, string $relation, string $like): void
    {
        $query->orWhereHas($relation, function (Builder $usuario) use ($like) {
            $usuario->where(function (Builder $inner) use ($like) {
                $inner->whereRaw('LOWER(COALESCE(nome_fantasia, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(razao_social, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(nome_completo, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(email, "")) LIKE ?', [$like]);
            });
        });
    }

    private function usuariosPorTipo(string $tipo)
    {
        // Admin vê também inativos no filtro — estabelecimentos podem continuar
        // apontando para marketplace/revenda desativados.
        $incluirInativos = UsuarioComercial::ehAdmin();

        return Usuario::query()
            ->where('tipo', $tipo)
            ->when(! $incluirInativos, fn (Builder $q) => $q->where('ativo', true))
            ->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')
            ->get()
            ->map(fn (Usuario $usuario) => [
                'id' => $usuario->id,
                'nome' => $usuario->nomeExibicao().($usuario->ativo ? '' : ' (inativo)'),
            ]);
    }
}
