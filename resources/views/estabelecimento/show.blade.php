@extends('layouts.app')

@section('title', 'Detalhes do Estabelecimento')

@section('content')
@php
    $nome = $estabelecimento->nome_fantasia ?: $estabelecimento->razao_social ?: $estabelecimento->nome_completo ?: 'Estabelecimento';
    $documento = $estabelecimento->cnpj ?: $estabelecimento->cpf ?: '-';
    $statusEtapa = \App\Support\EstabelecimentoEtapaListagem::statusEstabelecimento($estabelecimento);
    [$statusClass, $statusLabel] = \App\Support\EstabelecimentoEtapaListagem::badge($statusEtapa);
    $pagbankAutomatico = \App\Support\EstabelecimentoEtapaListagem::statusPagBankAutomatico($estabelecimento);
    [$pagbankAutoClass, $pagbankAutoLabel] = \App\Support\EstabelecimentoEtapaListagem::badge($pagbankAutomatico);
    $pagbankEfetivo = \App\Support\EstabelecimentoEtapaListagem::statusPagBank($estabelecimento);
    [$pagbankClass, $pagbankLabel] = \App\Support\EstabelecimentoEtapaListagem::badge($pagbankEfetivo);
    $pagbankStatusManualDisponivel = \App\Support\EstabelecimentoSchema::temPagbankStatusManual();
    $riscoClass = match ($estabelecimento->risco) {
        'bloqueado' => 'bg-red-500 text-white',
        'atencao' => 'bg-amber-500 text-white',
        default => 'bg-emerald-500 text-white',
    };
    $navTabClass = 'inline-flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold transition-colors';
    $navActionClass = 'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold shadow-sm transition-colors';
    $partesEndereco = array_filter([
        trim(($estabelecimento->endereco ?: '').($estabelecimento->numero ? ', '.$estabelecimento->numero : '')),
        $estabelecimento->complemento,
        $estabelecimento->bairro,
        trim(($estabelecimento->cidade ?: '').($estabelecimento->uf ? ' - '.$estabelecimento->uf : '')),
        $estabelecimento->cep ? 'CEP '.$estabelecimento->cep : null,
    ]);
    $enderecoCompleto = $partesEndereco !== [] ? implode(' · ', $partesEndereco) : '-';
@endphp

<div class="mb-4 flex items-center justify-between">
    <h2 class="text-sm font-bold uppercase tracking-wide text-gray-700">Detalhes do Estabelecimento</h2>
    <div class="text-sm text-gray-500">
        <a href="{{ route('estabelecimentos.index') }}" class="text-gray-800 hover:text-blue-600">Estabelecimento</a>
        <span class="mx-2 text-gray-400">›</span>
        <span>Detalhes</span>
    </div>
</div>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3">
    <nav class="flex flex-wrap items-center gap-2" aria-label="Seções do estabelecimento" data-tab-nav>
        <a href="#resumo" data-tab-link="resumo" class="{{ $navTabClass }} border-gray-200 bg-white text-gray-700 hover:border-blue-300 hover:text-blue-700">
            <i class="fa-solid fa-store"></i> Resumo
        </a>
        <a href="#automacao" data-tab-link="automacao" class="{{ $navTabClass }} border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-700">
            <i class="fa-solid fa-robot"></i> Automação
            @if ($estabelecimento->fv_status === 'em_andamento')
                <span class="inline-block h-2 w-2 animate-pulse rounded-full bg-blue-500"></span>
            @elseif ($estabelecimento->fv_status === 'concluido')
                <span class="inline-block h-2 w-2 rounded-full bg-emerald-500"></span>
            @elseif (in_array($estabelecimento->fv_status, ['erro','erro_email','timeout']))
                <span class="inline-block h-2 w-2 rounded-full bg-red-500"></span>
            @endif
        </a>
        <a href="#email-plataforma" data-tab-link="email-plataforma" class="{{ $navTabClass }} border-gray-200 bg-white text-gray-700 hover:border-blue-300 hover:text-blue-700">
            <i class="fa-solid fa-envelope"></i> E-mail
            @if ($estabelecimento->webmail_email)
                <span class="rounded-full bg-blue-600 px-2 py-0.5 text-xs text-white"><i class="fa-solid fa-check text-[9px]"></i></span>
            @endif
        </a>
        <a href="#kyc" data-tab-link="kyc" class="{{ $navTabClass }} border-gray-200 bg-white text-gray-700 hover:border-indigo-300 hover:text-indigo-700">
            <i class="fa-solid fa-shield-halved"></i> KYC
            @if ($estabelecimento->kycAnalise)
                @php $kycStatusColor = match($estabelecimento->kycAnalise->status) {
                    'aprovado' => 'bg-emerald-100 text-emerald-700',
                    'reprovado' => 'bg-red-100 text-red-700',
                    'em_analise','revisao_manual' => 'bg-amber-100 text-amber-700',
                    default => 'bg-indigo-100 text-indigo-700',
                }; @endphp
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $kycStatusColor }}">{{ str_replace('_', ' ', $estabelecimento->kycAnalise->status) }}</span>
            @endif
        </a>
        <a href="#documentos" data-tab-link="documentos" class="{{ $navTabClass }} border-gray-200 bg-white text-gray-700 hover:border-teal-300 hover:text-teal-700">
            <i class="fa-solid fa-file-lines"></i> Documentos
        </a>
        <a href="#logs" data-tab-link="logs" class="{{ $navTabClass }} border-gray-200 bg-white text-gray-700 hover:border-gray-400">
            <i class="fa-solid fa-clock-rotate-left"></i> Logs
        </a>
    </nav>
    <div class="flex flex-wrap items-center gap-2">
        <a href="{{ route('estabelecimentos.edit', $estabelecimento) }}" class="{{ $navActionClass }} bg-indigo-900 text-white hover:bg-indigo-800">
            <i class="fa-solid fa-pen"></i> Editar
        </a>
        <button type="button" data-modal-open="status" class="{{ $navActionClass }} bg-sky-500 text-white hover:bg-sky-600">
            <i class="fa-solid fa-sliders"></i> Alterar status
        </button>
        @if (auth()->user()?->tipo === 'admin' && $estabelecimento->ativo)
            <button type="button" data-modal-open="inativar-sistema" class="{{ $navActionClass }} border border-red-200 bg-white text-red-700 hover:bg-red-50">
                <i class="fa-solid fa-trash-can"></i> Inativar cadastro
            </button>
        @endif
    </div>
</div>

<section id="resumo" data-tab-panel="resumo" class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-100 bg-gray-50/80 px-6 py-4">
        <h3 class="text-lg font-bold text-gray-900">{{ $nome }}</h3>
        <p class="mt-0.5 text-sm text-gray-500">{{ $estabelecimento->pessoa_tipo === 'fisica' ? 'CPF' : 'CNPJ' }} {{ $documento }}</p>
    </div>

    <div class="grid gap-6 p-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Dados cadastrais</p>
                <dl class="mt-2 space-y-2 text-sm">
                    <div class="flex flex-wrap gap-x-2">
                        <dt class="font-semibold text-gray-600">Nome fantasia</dt>
                        <dd class="text-gray-800">{{ $estabelecimento->nome_fantasia ?: '-' }}</dd>
                    </div>
                    <div class="flex flex-wrap gap-x-2">
                        <dt class="font-semibold text-gray-600">Razão social</dt>
                        <dd class="text-gray-800">{{ $estabelecimento->razao_social ?: '-' }}</dd>
                    </div>
                </dl>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Endereço</p>
                <p class="mt-2 text-sm leading-relaxed text-gray-800">{{ $enderecoCompleto }}</p>
            </div>
            <div>
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Contato</p>
                <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="font-semibold text-gray-600">Telefone</dt>
                        <dd class="text-gray-800">{{ $estabelecimento->telefone ?: '-' }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-600">Celular</dt>
                        <dd class="text-gray-800">{{ $estabelecimento->celular ?: '-' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-semibold text-gray-600">E-mail</dt>
                        <dd class="text-gray-800">
                            @if ($estabelecimento->email)
                                <a href="mailto:{{ $estabelecimento->email }}" class="font-medium text-blue-600 hover:underline">{{ $estabelecimento->email }}</a>
                            @else
                                -
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Hierarquia</p>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div>
                        <dt class="text-gray-500">Master</dt>
                        <dd class="font-medium text-gray-800">{{ $estabelecimento->master?->id ? $estabelecimento->master->id.' · '.$estabelecimento->master->nomeExibicao() : '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Marketplace</dt>
                        <dd class="font-medium text-gray-800">{{ $estabelecimento->marketplace?->id ? $estabelecimento->marketplace->id.' · '.$estabelecimento->marketplace->nomeExibicao() : '-' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Revenda</dt>
                        <dd class="font-medium text-gray-800">{{ $estabelecimento->revenda?->id ? $estabelecimento->revenda->id.' · '.$estabelecimento->revenda->nomeExibicao() : '-' }}</dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Operação</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold {{ $statusClass }}">{{ $statusLabel }}</span>
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold {{ $pagbankClass }}">PagBank: {{ $pagbankLabel }}</span>
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold {{ $riscoClass }}">{{ ucfirst($estabelecimento->risco ?: 'confiavel') }}</span>
                </div>
                <p class="mt-3 text-sm text-gray-800"><span class="font-semibold text-gray-600">Plano:</span> {{ $estabelecimento->plano?->nome ?: '-' }}</p>
            </div>
        </div>
    </div>
</section>

{{-- ── Aba: Automação FV ── --}}
@php
    $fvStatus   = $estabelecimento->fv_status;
    $fvPodeGerenciarAutomacao = \App\Support\UsuarioComercial::podeGerenciarAutomacaoEstabelecimento($estabelecimento);
    $fvEhAdmin  = auth()->user()?->tipo === 'admin';
    $fvCadastroConcluido = filled($estabelecimento->fv_concluido_em);
    $fvPropostaComErro = ($estabelecimento->fv_proposta_status ?? null) === 'erro'
        || ($fvCadastroConcluido && $fvStatus === 'erro_proposta');
    $fvCores = match(true) {
        $fvCadastroConcluido && $fvPropostaComErro => ['bg-orange-100 text-orange-800 border-orange-200', 'fa-file-circle-xmark', 'Erro na proposta'],
        $fvStatus === 'concluido' || $fvCadastroConcluido => ['bg-emerald-100 text-emerald-800 border-emerald-200', 'fa-circle-check', 'Concluído'],
        $fvStatus === 'em_andamento' => ['bg-blue-100 text-blue-800 border-blue-200', 'fa-spinner fa-spin', 'Em andamento'],
        $fvStatus === 'pendente' => ['bg-amber-100 text-amber-800 border-amber-200', 'fa-clock', 'Aguardando fila'],
        $fvStatus === 'erro' => ['bg-red-100 text-red-800 border-red-200', 'fa-circle-exclamation', 'Erro'],
        $fvStatus === 'erro_email' => ['bg-red-100 text-red-800 border-red-200', 'fa-circle-exclamation', 'Erro no e-mail'],
        $fvStatus === 'erro_proposta' => ['bg-orange-100 text-orange-800 border-orange-200', 'fa-file-circle-xmark', 'Erro na proposta'],
        $fvStatus === 'timeout' => ['bg-orange-100 text-orange-800 border-orange-200', 'fa-hourglass-end', 'Timeout'],
        default => ['bg-gray-100 text-gray-500 border-gray-200', 'fa-circle-minus', 'Não iniciada'],
    };
    $fvPodeIniciar = $fvPodeGerenciarAutomacao && ! in_array($fvStatus, ['em_andamento', 'concluido']) && ! $fvCadastroConcluido;
    $fvCadastroFvConcluidoNosLogs = \App\Support\AutomacaoSchema::cadastroFvConcluidoNosLogs($automacaoLogs);
    $fvPodeRetentarEmail = $fvPodeGerenciarAutomacao
        && \App\Support\AutomacaoSchema::podeRetentarApenasEmail($estabelecimento, $automacaoLogs);
    $fvPodeIniciarCompleto = $fvPodeIniciar && ! $fvPodeRetentarEmail;
    $fvPodeRetentar = $fvPodeGerenciarAutomacao
        && ! in_array($fvStatus, ['em_andamento', 'pendente'], true)
        && ($estabelecimento->fv_proposta_status ?? null) !== 'em_andamento'
        && ! session('proposta_aceitando');
    $ultimoLogErroId = $automacaoLogs
        ->filter(fn ($log) => \App\Support\AutomacaoErroInterpretador::logPareceErro($log))
        ->last()
        ?->id;
@endphp
<section id="automacao" data-tab-panel="automacao" class="mt-8 hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-100 bg-gray-50/80 px-6 py-4">
        <h3 class="text-base font-bold text-gray-800">Automação Força de Vendas</h3>
        <p class="mt-0.5 text-xs text-gray-500">Cadastro automatizado via Selenium no portal PagBank Força de Vendas.</p>
    </div>

    <div class="space-y-6 p-6">

        @if ($errors->has('automacao'))
            <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <p class="font-bold"><i class="fa-solid fa-circle-exclamation mr-1"></i> Erro na automação</p>
                @foreach ($errors->get('automacao') as $erroAutomacao)
                    <p class="mt-1">{{ $erroAutomacao }}</p>
                @endforeach
            </div>
        @endif

        @if (session('status'))
            <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800">
                <i class="fa-solid fa-circle-check mr-1"></i> {{ session('status') }}
            </div>
        @endif

        @if (session('aviso'))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                <i class="fa-solid fa-circle-info mr-1"></i> {{ session('aviso') }}
            </div>
        @endif

        @if ($estabelecimento->fv_job_id)
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
                <div class="text-sm text-indigo-900">
                    <i class="fa-solid fa-camera mr-1"></i>
                    Screenshots disponíveis para debug deste cadastro.
                </div>
                <button type="button"
                        data-abrir-screenshots-automacao
                        class="inline-flex items-center gap-2 rounded-lg border border-indigo-300 bg-white px-3 py-2 text-xs font-semibold text-indigo-700 shadow-sm hover:bg-indigo-100">
                    <i class="fa-solid fa-images"></i>
                    Ver todos os screenshots
                </button>
            </div>
        @endif

        {{-- Status atual + botões de ação --}}
        <div class="flex flex-col gap-4">

            {{-- Etapas detalhadas quando cadastro FV ok e falha no e-mail --}}
            @if ($fvStatus === 'erro_email' || ($fvCadastroFvConcluidoNosLogs && in_array($fvStatus, ['erro', 'timeout'], true)))
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-2 rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-bold text-emerald-700">
                        <i class="fa-solid fa-circle-check"></i> Cadastro PagBank: Concluído
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-full border border-orange-300 bg-orange-50 px-3 py-1.5 text-sm font-bold text-orange-700">
                        <i class="fa-solid fa-circle-exclamation"></i> E-mail / Senha: Erro
                    </span>
                </div>
            @elseif ($fvCadastroConcluido && $fvPropostaComErro)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="inline-flex items-center gap-2 rounded-full border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-sm font-bold text-emerald-700">
                        <i class="fa-solid fa-circle-check"></i> Cadastro PagBank: Concluído
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-full border border-orange-300 bg-orange-50 px-3 py-1.5 text-sm font-bold text-orange-700">
                        <i class="fa-solid fa-circle-exclamation"></i> Proposta comercial: Erro
                    </span>
                </div>
            @else
                <div class="flex flex-col gap-2">
                    <div class="flex items-center gap-3">
                        <span id="automacao-status-badge" class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-bold {{ $fvCores[0] }}">
                            <i id="automacao-status-icon" class="fa-solid {{ $fvCores[1] }}"></i>
                            <span id="automacao-status-label">{{ $fvCores[2] }}</span>
                        </span>
                    </div>
                    @if (in_array($fvStatus, ['em_andamento', 'pendente']) || ($estabelecimento->fv_proposta_status ?? null) === 'em_andamento' || session('proposta_aceitando'))
                        <div id="automacao-etapa-box"
                             class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3"
                             data-automacao-poll
                             data-status-url="{{ route('admin.estabelecimentos.automacao.status', $estabelecimento) }}"
                             data-fv-status="{{ $fvStatus }}">
                            <p class="text-xs font-semibold uppercase tracking-wide text-blue-600">Etapa atual</p>
                            <p id="automacao-etapa-texto" class="mt-1 text-sm font-medium text-blue-900">
                                @if (($estabelecimento->fv_proposta_status ?? null) === 'em_andamento' || session('proposta_aceitando'))
                                    Aceitando proposta comercial no PagBank...
                                @else
                                    {{ $fvStatus === 'pendente' ? 'Aguardando fila...' : 'Consultando progresso...' }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-blue-500">
                                <i class="fa-solid fa-arrows-rotate fa-spin mr-1"></i>
                                Atualização automática a cada 20 segundos
                            </p>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Botões de ação --}}
            <div class="flex flex-wrap items-center gap-3">
                @if ($fvPodeRetentarEmail)
                    <form method="POST"
                          action="{{ route('admin.estabelecimentos.automacao.retentar-email', $estabelecimento) }}"
                          onsubmit="return confirm('Continuar pela etapa de e-mail e senha? O cadastro no portal FV não será refeito.')">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow hover:bg-orange-700">
                            <i class="fa-solid fa-envelope-circle-check"></i> Continuar (e-mail e senha)
                        </button>
                    </form>
                    @if ($fvPodeIniciar)
                        <button type="button"
                                data-modal-open="automacao-confirmar"
                                data-automacao-label="Confirmar e refazer tudo"
                                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">
                            <i class="fa-solid fa-rotate-right"></i> Refazer tudo
                        </button>
                    @endif
                @elseif ($fvPodeIniciarCompleto)
                    <button type="button"
                            data-modal-open="automacao-confirmar"
                            data-automacao-label="{{ in_array($fvStatus, ['erro','timeout']) ? 'Confirmar e retentar' : 'Confirmar e iniciar' }}"
                            class="inline-flex items-center gap-2 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-bold text-white shadow hover:bg-indigo-800">
                        <i class="fa-solid fa-robot"></i>
                        {{ in_array($fvStatus, ['erro','timeout']) ? 'Retentar Automação' : 'Iniciar Automação' }}
                    </button>
                @elseif ($fvPropostaComErro && $fvEhAdmin)
                    <form method="POST"
                          action="{{ route('admin.estabelecimentos.automacao.aceitar-proposta', $estabelecimento) }}"
                          onsubmit="return confirm('Retentar aceite da proposta comercial no PagBank?')">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow hover:bg-orange-700">
                            <i class="fa-solid fa-file-signature"></i> Retentar Proposta
                        </button>
                    </form>
                @elseif (($fvStatus === 'concluido' || $fvCadastroConcluido) && $fvPodeGerenciarAutomacao)
                    <button type="button"
                            data-modal-open="automacao-confirmar"
                            data-automacao-label="Confirmar e reexecutar"
                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        <i class="fa-solid fa-rotate-right"></i> Reexecutar
                    </button>
                @endif

                @php
                    $propostaEmAndamento = ($estabelecimento->fv_proposta_status ?? null) === 'em_andamento' || session('proposta_aceitando');
                    $podeAceitarProposta = $fvEhAdmin
                        && filled($estabelecimento->fv_senha_6)
                        && ($estabelecimento->fv_proposta_status ?? null) !== 'concluido'
                        && ! $propostaEmAndamento
                        && ! $fvPropostaComErro
                        && $fvCadastroConcluido
                        && \App\Support\AutomacaoSchema::temColunasProposta();
                @endphp
                @if ($podeAceitarProposta)
                    <form id="form-aceitar-proposta" method="POST"
                          action="{{ route('admin.estabelecimentos.automacao.aceitar-proposta', $estabelecimento) }}"
                          onsubmit="return confirm('Executar login no PagBank e aceitar a proposta comercial pendente?')">
                        @csrf
                        <button type="submit" id="btn-aceitar-proposta"
                                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-bold text-white shadow hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-60">
                            <i class="fa-solid fa-file-signature"></i>
                            <span>Aceitar Proposta</span>
                        </button>
                    </form>
                @elseif ($fvEhAdmin && $fvCadastroConcluido && ! \App\Support\AutomacaoSchema::temColunasProposta())
                    <p class="text-sm text-amber-700">
                        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                        Execute <code class="rounded bg-amber-100 px-1">docker compose exec app php artisan migrate --force</code> para habilitar o aceite de proposta.
                    </p>
                @endif
            </div>
        </div>

        {{-- Detalhes da execução --}}
        @php
            $emailPagBank = $estabelecimento->webmail_email ?: $estabelecimento->email;
        @endphp
        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">Iniciado em</dt>
                <dd class="mt-1 text-sm font-medium text-gray-800">
                    {{ $estabelecimento->fv_iniciado_em?->format('d/m/Y H:i:s') ?: '—' }}
                </dd>
            </div>
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-4">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">Concluído em</dt>
                <dd class="mt-1 text-sm font-medium text-gray-800">
                    {{ $estabelecimento->fv_concluido_em?->format('d/m/Y H:i:s') ?: '—' }}
                </dd>
            </div>
            @if ($fvCadastroConcluido && filled($emailPagBank))
                <div class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-600">E-mail PagBank</dt>
                    <dd class="mt-1 break-all text-sm font-medium text-gray-900">{{ $emailPagBank }}</dd>
                </div>
            @endif
            @if ($fvCadastroConcluido && filled($estabelecimento->fv_senha_6))
                <div class="rounded-lg border border-emerald-100 bg-emerald-50/50 p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Senha PagBank (6 dígitos)</dt>
                    <dd x-data="{ mostrar: false }" class="mt-1 flex flex-wrap items-center gap-2">
                        <span x-show="!mostrar" class="font-mono text-sm tracking-widest text-gray-400">••••••</span>
                        <span x-show="mostrar" class="font-mono text-sm font-bold text-gray-900">{{ $estabelecimento->fv_senha_6 }}</span>
                        <button type="button" @click="mostrar = !mostrar" class="text-xs font-semibold text-emerald-700 hover:text-emerald-900">
                            <span x-show="!mostrar">mostrar</span>
                            <span x-show="mostrar">ocultar</span>
                        </button>
                    </dd>
                </div>
            @endif
            @if ($fvCadastroConcluido)
                @php
                    $safepayBuscando = session('safepay_buscando') && blank($estabelecimento->token_pagseguro);
                @endphp
                <div class="rounded-lg border {{ filled($estabelecimento->token_pagseguro) ? 'border-emerald-100 bg-emerald-50/50' : 'border-amber-100 bg-amber-50/50' }} p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide {{ filled($estabelecimento->token_pagseguro) ? 'text-emerald-600' : 'text-amber-700' }}">ID PagSeguro (Safepay ID)</dt>
                    <dd class="mt-1 flex flex-wrap items-center gap-3">
                        <span id="safepay-id-valor" class="font-mono text-sm font-bold text-gray-900">{{ $estabelecimento->token_pagseguro ?: '—' }}</span>
                        @if ($fvEhAdmin && blank($estabelecimento->token_pagseguro) && ! $safepayBuscando)
                            <form id="form-buscar-safepay" method="POST" action="{{ route('admin.estabelecimentos.automacao.buscar-safepay-id', $estabelecimento) }}">
                                @csrf
                                <button type="submit" id="btn-buscar-safepay"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60">
                                    <i class="fa-solid fa-magnifying-glass"></i>
                                    <span>Buscar Safepay ID</span>
                                </button>
                            </form>
                        @endif
                    </dd>
                    @if ($safepayBuscando)
                        <div id="safepay-busca-ativa" class="mt-3 rounded-lg border border-blue-200 bg-blue-50 p-3">
                            <div class="flex items-center gap-2 text-sm font-semibold text-blue-800">
                                <i class="fa-solid fa-spinner fa-spin"></i>
                                <span>Pesquisando Safepay ID no portal PagBank...</span>
                            </div>
                            <p class="mt-1 text-xs text-blue-600">Aguarde — a página atualiza automaticamente quando encontrar o ID.</p>
                        </div>
                    @elseif (blank($estabelecimento->token_pagseguro))
                        <p class="mt-2 text-xs text-amber-700">Busca no FV o cliente com e-mail @express.app.br e preenche automaticamente.</p>
                    @endif
                </div>
            @endif
            @if (filled($estabelecimento->fv_proposta_status))
                <div class="rounded-lg border {{ $estabelecimento->fv_proposta_status === 'concluido' ? 'border-emerald-100 bg-emerald-50/50' : ($estabelecimento->fv_proposta_status === 'em_andamento' ? 'border-blue-100 bg-blue-50/50' : 'border-orange-100 bg-orange-50/50') }} p-4">
                    <dt class="text-xs font-semibold uppercase tracking-wide {{ $estabelecimento->fv_proposta_status === 'concluido' ? 'text-emerald-600' : ($estabelecimento->fv_proposta_status === 'em_andamento' ? 'text-blue-600' : 'text-orange-700') }}">Proposta comercial</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-800">
                        @if ($estabelecimento->fv_proposta_status === 'concluido')
                            Aceita em {{ $estabelecimento->fv_proposta_concluido_em?->format('d/m/Y H:i') ?: '—' }}
                        @elseif ($estabelecimento->fv_proposta_status === 'em_andamento')
                            Aceitando proposta no PagBank...
                        @else
                            Erro: {{ $estabelecimento->fv_proposta_erro ?: 'Falha ao aceitar proposta' }}
                        @endif
                    </dd>
                </div>
            @elseif (session('proposta_aceitando') || $estabelecimento->fv_proposta_status === 'em_andamento')
                <div id="proposta-aceitando-ativa" class="rounded-lg border border-blue-200 bg-blue-50 p-4 sm:col-span-2">
                    <div class="flex items-center gap-2 text-sm font-semibold text-blue-800">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        <span>Aceitando proposta comercial no PagBank...</span>
                    </div>
                </div>
            @endif
            <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 sm:col-span-2">
                <dt class="text-xs font-semibold uppercase tracking-wide text-gray-400">ID do Job (API Python)</dt>
                <dd class="mt-1 break-all font-mono text-xs text-gray-700">
                    {{ $estabelecimento->fv_job_id ?: '—' }}
                </dd>
            </div>
        </dl>

        {{-- Erro do cadastro (não confundir com erro só da proposta) --}}
        @if ($estabelecimento->fv_erro && ! $fvPropostaComErro)
            @php
                $erroAuto = $automacaoErro ?? \App\Support\AutomacaoErroInterpretador::interpretar($estabelecimento->fv_erro);
            @endphp
            <div class="rounded-lg border border-red-200 bg-red-50 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="mb-1 text-xs font-bold uppercase tracking-wide text-red-600">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i> {{ $erroAuto['titulo'] }}
                        </p>
                        @if ($erroAuto['etapa'])
                            <p class="mb-2 inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-[11px] font-semibold text-red-800">
                                <i class="fa-solid fa-layer-group"></i>
                                Etapa: {{ $erroAuto['etapa'] }}
                            </p>
                        @endif
                        <p class="text-sm leading-relaxed text-red-900">{{ $erroAuto['resumo'] }}</p>
                        @if ($fvCadastroFvConcluidoNosLogs && in_array($fvStatus, ['erro', 'timeout'], true))
                            <p class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                <i class="fa-solid fa-circle-info mr-1"></i>
                                O cadastro no portal Força de Vendas já foi concluído. Os passos do log (webmail, e-mail do PagBank) são da
                                <strong>criação da senha</strong>. Use <strong>Continuar (e-mail e senha)</strong> para retomar dessa etapa — não é necessário refazer o cadastro no PagBank.
                            </p>
                        @endif
                        @if (filled($erroAuto['tecnico']) && $erroAuto['tecnico'] !== $erroAuto['resumo'])
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs font-medium text-red-700">Ver detalhes técnicos</summary>
                                <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap break-all rounded bg-red-100/60 p-3 font-mono text-[10px] text-red-900">{{ $erroAuto['tecnico'] }}</pre>
                            </details>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        @if ($estabelecimento->fv_job_id)
                            <button type="button"
                                    data-abrir-screenshots-automacao
                                    class="inline-flex items-center gap-2 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold text-red-700 shadow-sm hover:bg-red-100">
                                <i class="fa-solid fa-images"></i>
                                Ver screenshots do processo
                            </button>
                        @endif
                        @include('estabelecimento.partials.automacao-retentar-botoes')
                    </div>
                </div>
            </div>
        @endif

        @if ($fvPropostaComErro && filled($estabelecimento->fv_proposta_erro))
            <div class="rounded-lg border border-orange-200 bg-orange-50 p-4">
                <p class="mb-1 text-xs font-bold uppercase tracking-wide text-orange-700">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> Erro na proposta comercial
                </p>
                <pre class="whitespace-pre-wrap break-all font-mono text-xs text-orange-900">{{ $estabelecimento->fv_proposta_erro }}</pre>
            </div>
        @elseif ($fvPropostaComErro && filled($estabelecimento->fv_erro))
            <div class="rounded-lg border border-orange-200 bg-orange-50 p-4">
                <p class="mb-1 text-xs font-bold uppercase tracking-wide text-orange-700">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i> Erro na proposta comercial
                </p>
                <pre class="whitespace-pre-wrap break-all font-mono text-xs text-orange-900">{{ $estabelecimento->fv_erro }}</pre>
            </div>
        @endif

        {{-- Sucesso --}}
        @if ($fvCadastroConcluido)
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                <p class="text-sm font-bold text-emerald-800">
                    <i class="fa-solid fa-circle-check mr-1"></i> Cadastro concluído com sucesso no portal PagBank Força de Vendas.
                </p>
                @if ($estabelecimento->fv_concluido_em)
                    <p class="mt-1 text-xs text-emerald-700">Finalizado em {{ $estabelecimento->fv_concluido_em->format('d/m/Y \à\s H:i') }}.</p>
                @endif
            </div>
        @endif

        {{-- Screenshots da automação --}}
        @if ($estabelecimento->fv_job_id)
            <div id="automacao-screenshots-section"
                 data-list-url="{{ route('admin.estabelecimentos.automacao.screenshots', $estabelecimento) }}">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Screenshots da execução</p>
                    <div class="flex items-center gap-2">
                        <button type="button"
                                data-abrir-screenshots-automacao
                                class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-100">
                            <i class="fa-solid fa-up-right-and-down-left-from-center"></i>
                            Abrir galeria
                        </button>
                        <button type="button"
                                id="automacao-screenshots-recarregar"
                                class="inline-flex items-center gap-1 rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 hover:border-indigo-300 hover:text-indigo-700">
                            <i class="fa-solid fa-arrows-rotate"></i>
                            Atualizar
                        </button>
                    </div>
                </div>
                <div id="automacao-screenshots-loading" class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
                    <i class="fa-solid fa-spinner fa-spin mr-1"></i> Carregando screenshots...
                </div>
                <div id="automacao-screenshots-empty" class="hidden rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-6 text-center text-sm text-gray-400">
                    Nenhum screenshot disponível para este job.
                </div>
                <div id="automacao-screenshots-grid" class="hidden grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>
            </div>
        @endif

        {{-- Histórico detalhado da automação --}}
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Log da automação — passo a passo</p>
            @if ($automacaoLogsIndisponivel ?? false)
                <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                    Tabela de logs ainda não criada. Execute <code class="rounded bg-amber-100 px-1">docker compose exec app php artisan migrate --force</code> no servidor.
                </div>
            @endif
            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="w-full text-xs" id="automacao-logs-table">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Data/Hora</th>
                            <th class="px-3 py-2 text-left font-semibold">Nível</th>
                            <th class="px-3 py-2 text-left font-semibold">Etapa</th>
                            <th class="px-3 py-2 text-left font-semibold">Mensagem</th>
                        </tr>
                    </thead>
                    <tbody id="automacao-logs-body" class="divide-y divide-gray-100">
                        @forelse ($automacaoLogs as $alog)
                            @php
                                $nivelCores = match($alog->nivel) {
                                    'sucesso' => 'bg-emerald-100 text-emerald-800',
                                    'erro' => 'bg-red-100 text-red-800',
                                    'aviso' => 'bg-amber-100 text-amber-800',
                                    default => 'bg-blue-50 text-blue-800',
                                };
                                $contextoLog = is_array($alog->detalhe) ? $alog->detalhe : [];
                                if (filled($alog->etapa)) {
                                    $contextoLog['etapa_log'] = $alog->etapa;
                                }
                                $erroLog = \App\Support\AutomacaoErroInterpretador::logPareceErro($alog)
                                    ? \App\Support\AutomacaoErroInterpretador::interpretar($alog->mensagem, $contextoLog, $automacaoLogs)
                                    : null;
                            @endphp
                            <tr class="hover:bg-gray-50" data-log-id="{{ $alog->id }}">
                                <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ $alog->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td class="whitespace-nowrap px-3 py-2">
                                    <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase {{ $nivelCores }}">{{ $alog->nivel }}</span>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-gray-600">{{ $erroLog['etapa'] ?? ($alog->etapa ?: '—') }}</td>
                                <td class="px-3 py-2 text-gray-800">
                                    @if ($erroLog)
                                        <p class="font-medium text-red-900">{{ $erroLog['titulo'] }}</p>
                                        <p class="mt-0.5 text-gray-700">{{ $erroLog['resumo'] }}</p>
                                        <div class="mt-2 flex flex-wrap items-center gap-2">
                                            @if ($estabelecimento->fv_job_id)
                                                <button type="button"
                                                        data-abrir-screenshots-automacao
                                                        class="inline-flex items-center gap-1 rounded border border-red-200 bg-white px-2 py-1 text-[10px] font-semibold text-red-700 hover:bg-red-50">
                                                    <i class="fa-solid fa-images"></i>
                                                    Ver screenshots
                                                </button>
                                            @endif
                                            @if ($fvPodeRetentar && $alog->id === $ultimoLogErroId)
                                                @include('estabelecimento.partials.automacao-retentar-botoes', ['compact' => true])
                                            @endif
                                        </div>
                                        @if (filled($erroLog['tecnico']) && $erroLog['tecnico'] !== $erroLog['resumo'])
                                            <details class="mt-2">
                                                <summary class="cursor-pointer text-[10px] text-gray-400">detalhes técnicos</summary>
                                                <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all rounded bg-gray-50 p-2 font-mono text-[10px] text-gray-600">{{ $erroLog['tecnico'] }}</pre>
                                            </details>
                                        @endif
                                    @else
                                        {{ $alog->mensagem }}
                                        @if (filled($alog->detalhe))
                                            <details class="mt-1">
                                                <summary class="cursor-pointer text-[10px] text-gray-400">detalhes</summary>
                                                <pre class="mt-1 whitespace-pre-wrap break-all rounded bg-gray-50 p-2 font-mono text-[10px] text-gray-600">{{ json_encode($alog->detalhe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                            </details>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr id="automacao-logs-empty">
                                <td colspan="4" class="px-3 py-6 text-center text-gray-400">
                                    Nenhum log registrado ainda. Os passos aparecerão aqui durante a execução.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Histórico de logs do sistema (geral) --}}
        @if ($logs->isNotEmpty())
            <div>
                <p class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Outros logs do estabelecimento</p>
                <div class="overflow-x-auto rounded-lg border border-gray-100">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Data</th>
                                <th class="px-3 py-2 text-left font-semibold">Ação</th>
                                <th class="px-3 py-2 text-left font-semibold">Mensagem</th>
                                <th class="px-3 py-2 text-left font-semibold">Usuário</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($logs as $log)
                                <tr class="hover:bg-gray-50">
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 font-mono text-gray-700">{{ $log->acao ?: '-' }}</td>
                                    <td class="px-3 py-2 text-gray-700">{{ $log->mensagem ?: '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ $log->usuario_nome ?: 'Sistema' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

    </div>
</section>

{{-- ── Aba: E-mail Plataforma ── --}}
<section id="email-plataforma" data-tab-panel="email-plataforma" class="mt-8 hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-100 bg-gray-50/80 px-6 py-4">
        <h3 class="text-base font-bold text-gray-900"><i class="fa-solid fa-envelope mr-2 text-blue-500"></i>E-mail Plataforma</h3>
        <p class="mt-0.5 text-xs text-gray-500">Caixa de e-mail do estabelecimento em <strong>{{ config('directadmin.dominio') }}</strong> com redirecionamento automático.</p>
    </div>

    <div class="p-6">
        @if ($estabelecimento->webmail_email)
            @php $dominioPlataforma = config('directadmin.dominio'); @endphp
            <div class="max-w-lg space-y-5">
                {{-- Info do e-mail --}}
                <div class="rounded-xl border border-blue-100 bg-blue-50/60 p-5">
                    <div class="flex items-start gap-4">
                        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-blue-600 text-white">
                            <i class="fa-solid fa-at text-xl"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="break-all text-base font-bold text-gray-900">{{ $estabelecimento->webmail_email }}</p>
                            @if ($estabelecimento->email)
                                <p class="mt-1 flex items-center gap-1 text-xs text-gray-500">
                                    <i class="fa-solid fa-share text-blue-400"></i>
                                    Redireciona para <span class="font-semibold text-gray-700">{{ $estabelecimento->email }}</span>
                                </p>
                            @endif
                            @if ($estabelecimento->webmail_senha)
                                <div x-data="{ mostrar: false }" class="mt-2 flex items-center gap-2">
                                    <span class="text-xs text-gray-500">Senha:</span>
                                    <span x-show="!mostrar" class="font-mono text-xs tracking-widest text-gray-400">••••••••</span>
                                    <span x-show="mostrar" class="font-mono text-xs font-semibold text-gray-800 dark:text-gray-200">{{ $estabelecimento->webmail_senha }}</span>
                                    <button type="button" @click="mostrar = !mostrar" class="text-xs text-blue-500 hover:text-blue-700">
                                        <span x-show="!mostrar">mostrar</span>
                                        <span x-show="mostrar">ocultar</span>
                                    </button>
                                </div>
                            @else
                                <p class="mt-2 text-xs text-amber-600">
                                    <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                                    Senha não disponível. Use "Trocar Senha" para definir uma nova.
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <form action="{{ route('estabelecimentos.webmail.sso', $estabelecimento) }}" method="POST" target="_blank">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-blue-700">
                                <i class="fa-solid fa-envelope-open-text"></i> Acessar Webmail
                            </button>
                        </form>
                        <button type="button" data-modal-open="webmail-senha" class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-bold text-gray-700 shadow-sm hover:bg-gray-50">
                            <i class="fa-solid fa-key"></i> Trocar Senha
                        </button>
                        @if (auth()->user()?->tipo === 'admin' && filled($estabelecimento->email))
                            <form action="{{ route('estabelecimentos.webmail.reconfigurar-forwarder', $estabelecimento) }}" method="POST"
                                  onsubmit="return confirm('Reconfigurar o redirecionamento de e-mail?\n\nO forwarder será deletado e recriado para manter cópia local no Roundcube.')">
                                @csrf
                                <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700 shadow-sm hover:bg-amber-100">
                                    <i class="fa-solid fa-arrows-rotate"></i> Reconfigurar Redirecionamento
                                </button>
                            </form>
                        @endif
                        @if (in_array(auth()->user()?->tipo, ['admin', 'marketplace'], true))
                            <button type="button" data-modal-open="webmail-recriar" class="inline-flex items-center gap-2 rounded-lg border border-purple-300 bg-purple-50 px-4 py-2 text-sm font-semibold text-purple-700 shadow-sm hover:bg-purple-100">
                                <i class="fa-solid fa-envelope-circle-check"></i> Criar outro e-mail
                            </button>
                        @endif
                    </div>
                    @error('senha_webmail')
                        <p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Configurações IMAP/SMTP --}}
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Configuração para cliente de e-mail</p>
                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-semibold text-gray-600">Servidor IMAP</dt>
                            <dd class="font-mono text-xs text-gray-800">{{ config('directadmin.imap_host') ?: 'mail.'.config('directadmin.dominio') }}</dd>
                            <dd class="text-xs text-gray-500">Porta {{ config('directadmin.imap_porta', 993) }} · SSL</dd>
                        </div>
                        <div>
                            <dt class="font-semibold text-gray-600">Servidor SMTP</dt>
                            <dd class="font-mono text-xs text-gray-800">{{ config('directadmin.smtp_host') ?: 'mail.'.config('directadmin.dominio') }}</dd>
                            <dd class="text-xs text-gray-500">Porta {{ config('directadmin.smtp_porta', 587) }} · TLS</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="font-semibold text-gray-600">Usuário</dt>
                            <dd class="font-mono text-xs text-gray-800">{{ $estabelecimento->webmail_email }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        @elseif ($estabelecimento->email)
            @php $dominioPlataforma = config('directadmin.dominio'); @endphp
            <div class="max-w-lg">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-center">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                        <i class="fa-solid fa-envelope-circle-check text-2xl"></i>
                    </div>
                    <h4 class="mt-3 font-bold text-gray-800">Nenhum e-mail criado</h4>
                    @if (session('aviso'))
                        <p class="mt-1 text-sm text-amber-800">{{ session('aviso') }}</p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">Crie um e-mail <strong>&#64;{{ $dominioPlataforma }}</strong> para este estabelecimento. O e-mail redirecionará automaticamente para <strong>{{ $estabelecimento->email }}</strong>.</p>
                    @endif
                    <button type="button" data-modal-open="webmail-criar" class="mt-4 inline-flex items-center gap-2 rounded-lg bg-amber-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-amber-700">
                        <i class="fa-solid fa-plus"></i> Criar E-mail Plataforma
                    </button>
                </div>
            </div>
        @else
            <p class="text-sm text-gray-400">Cadastre um e-mail de contato no estabelecimento para criar uma caixa na plataforma.</p>
        @endif
    </div>
</section>

{{-- ── Aba: KYC ── --}}
@php
    $kycEhAdmin = auth()->user()?->tipo === 'admin';
    $kycStatusClass = match ($kyc->status) {
        'aprovado'                      => 'bg-emerald-100 text-emerald-700 border-emerald-200',
        'reprovado'                     => 'bg-red-100 text-red-700 border-red-200',
        'em_analise', 'revisao_manual'  => 'bg-amber-100 text-amber-700 border-amber-200',
        default                         => 'bg-gray-100 text-gray-600 border-gray-200',
    };
    $kycQtdPendentes = $kyc->documentos->where('openai_status', 'pendente')->whereNull('openai_analisado_em')->count();
@endphp
<section id="kyc" data-tab-panel="kyc" class="mt-8 hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">

    {{-- Cabeçalho com status e botões de ação --}}
    <div class="border-b border-gray-100 bg-gray-50/80 px-6 py-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-base font-bold text-gray-800">KYC — Validação de Identidade</h3>
                <p class="mt-0.5 text-xs text-gray-500">Análise de documentos e validação cadastral do estabelecimento.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-bold {{ $kycStatusClass }}">
                    {{ str_replace('_', ' ', ucfirst($kyc->status)) }}
                </span>
                <a href="{{ route('estabelecimentos.show', $estabelecimento) }}#documentos"
                   data-tab-link-target="documentos"
                   class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white hover:bg-blue-700">
                    <i class="fa-solid fa-file-arrow-up"></i> Anexar Documentos
                </a>
                @if ($kycEhAdmin && $kycQtdPendentes > 0)
                    <form method="POST" action="{{ route('admin.kyc.processar-pendentes', $kyc) }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700">
                            <i class="fa-solid fa-play"></i> Processar {{ $kycQtdPendentes }} pendente(s)
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="space-y-6 p-6">

        {{-- Avisos de configuração --}}
        @if (! $kycAtivo)
            <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">Módulo KYC desativado pelo administrador.</p>
        @elseif (! $ppidConfigurado)
            <p class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">PPID não configurada — documentos irão para revisão manual do Admin.</p>
        @endif

        {{-- Receita Federal --}}
        @if ($kyc->receita_consultado)
            <div class="grid gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4 text-sm md:grid-cols-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Situação Receita</p>
                    <p class="mt-1 font-semibold text-gray-800">{{ $kyc->receita_situacao ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Nome na Receita</p>
                    <p class="mt-1 font-semibold text-gray-800">{{ $kyc->receita_nome ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Consultado em</p>
                    <p class="mt-1 text-gray-700">{{ $kyc->receita_consultado_em?->format('d/m/Y H:i') ?: '—' }}</p>
                </div>
            </div>
        @endif

        {{-- Documentos --}}
        <div class="overflow-hidden rounded-xl border border-gray-100">
            <div class="grid gap-4 p-4 md:grid-cols-2">
                @foreach ($kycItens as $item)
                    @php
                        $kycDoc  = $item['kycDoc'];
                        $estabDoc = $item['estabDoc'];
                        $statusDoc = $kycDoc?->statusEfetivo() ?? ($estabDoc ? 'aguardando_analise' : 'pendente');
                        $divergenciasDoc = $kycDoc?->cruzamento_divergencias ?? [];
                        $provavelOcrDoc = $kycDoc && $kycDoc->cruzamento_status === 'divergencia'
                            && \App\Support\KycDivergenciaHelper::provavelErroLeitura($divergenciasDoc);
                        $statusDocLabel = $provavelOcrDoc && ! $kycEhAdmin
                            ? 'Reenviar foto'
                            : str_replace('_', ' ', ucfirst($statusDoc));
                        $statusDocClass = match ($statusDoc) {
                            'aprovado'           => 'text-emerald-600',
                            'reprovado'          => 'text-red-600',
                            'processando'        => 'text-blue-600',
                            'revisao_manual','aguardando_analise' => 'text-amber-600',
                            default              => 'text-gray-400',
                        };
                    @endphp
                    <div class="rounded-xl border {{ $estabDoc ? 'border-emerald-100 bg-emerald-50/30' : 'border-gray-100 bg-gray-50' }} p-4">
                        <div class="flex items-start justify-between gap-2">
                            <p class="font-semibold text-gray-800">{{ $item['label'] }}</p>
                            <span class="text-xs font-bold {{ $statusDocClass }}">{{ $statusDocLabel }}</span>
                        </div>
                        @if ($estabDoc)
                            <p class="mt-1 truncate text-xs text-gray-500">{{ $estabDoc->arquivo_nome ?: basename($estabDoc->arquivo_path) }}</p>
                            @if ($kycDoc && $kycDoc->cruzamento_status === 'divergencia')
                                @include('partials.kyc-divergencia-alerta', [
                                    'documento' => $kycDoc,
                                    'estabelecimento' => $estabelecimento,
                                    'mostrarBotaoReenvio' => ! $kycEhAdmin,
                                ])
                            @elseif ($kycDoc?->openai_motivo_reprovacao)
                                <p class="mt-2 text-xs text-red-600">{{ $kycDoc->openai_motivo_reprovacao }}</p>
                            @endif

                            {{-- Admin: detalhes OpenAI e ações por documento --}}
                            @if ($kycEhAdmin && $kycDoc)
                                @if ($kycDoc->openai_dados_extraidos)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-semibold text-gray-400 hover:text-gray-700">Dados extraídos (PPID OCR)</summary>
                                        <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-2 text-xs">{{ json_encode($kycDoc->openai_dados_extraidos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                                @if ($kycDoc->cruzamento_divergencias)
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs font-semibold text-gray-400 hover:text-gray-700">Detalhes técnicos (JSON)</summary>
                                        <pre class="mt-1 overflow-x-auto rounded bg-red-50 p-2 text-xs text-red-800">{{ json_encode($kycDoc->cruzamento_divergencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('admin.kyc.documentos.override', $kycDoc) }}">
                                        @csrf
                                        <input type="hidden" name="decisao" value="aprovado">
                                        <button type="submit" class="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs font-semibold text-emerald-700">Aprovar</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.kyc.documentos.override', $kycDoc) }}">
                                        @csrf
                                        <input type="hidden" name="decisao" value="reprovado">
                                        <button type="submit" class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700">Reprovar</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.kyc.documentos.reanalise', $kycDoc) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-gray-200 px-2.5 py-1.5 text-xs font-semibold text-gray-700">Reanalisar</button>
                                    </form>
                                </div>
                            @endif
                        @else
                            <p class="mt-1 text-xs text-gray-400">Nenhum arquivo enviado</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Admin: Aprovação / Reprovação final --}}
        @if ($kycEhAdmin && in_array($kyc->status, ['em_analise', 'revisao_manual'], true))
            <div class="rounded-xl border border-amber-100 bg-amber-50/50 p-5">
                <h4 class="mb-4 text-sm font-bold text-amber-800">Decisão Final do Admin</h4>
                <div class="flex flex-wrap gap-4">
                    <form method="POST" action="{{ route('admin.kyc.reprovar', $kyc) }}" class="flex-1 space-y-3">
                        @csrf
                        <textarea name="motivo" rows="3"
                            placeholder="Motivo da reprovação (obrigatório)..."
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white hover:bg-red-700">
                            <i class="fa-solid fa-xmark"></i> Reprovar KYC
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.kyc.aprovar', $kyc) }}" class="flex items-end">
                        @csrf
                        <input type="hidden" name="motivo" value="Aprovado pelo administrador">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-700">
                            <i class="fa-solid fa-check"></i> Aprovar KYC
                        </button>
                    </form>
                </div>
            </div>
        @endif

        {{-- Decisão já tomada --}}
        @if ($kyc->admin_decisao)
            <div class="rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                <span class="font-semibold">Decisão:</span> {{ $kyc->admin_decisao }}
                @if ($kyc->admin_decidido_em) em {{ $kyc->admin_decidido_em->format('d/m/Y H:i') }} @endif
                @if ($kyc->admin_motivo)<br><span class="text-gray-500">{{ $kyc->admin_motivo }}</span>@endif
            </div>
        @endif

        {{-- Histórico --}}
        @if ($kyc->historico->isNotEmpty())
            <div>
                <p class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-400">Histórico</p>
                <ul class="space-y-1 text-sm text-gray-600">
                    @foreach ($kyc->historico->take(15) as $h)
                        <li class="flex justify-between gap-4 border-b border-gray-50 py-1.5">
                            <span>{{ str_replace('_', ' ', $h->evento) }} — {{ $h->descricao }}</span>
                            <span class="shrink-0 text-xs text-gray-400">{{ $h->created_at?->format('d/m/Y H:i') }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

    </div>
</section>

<section id="documentos" data-tab-panel="documentos" class="mt-8 hidden overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    @php
        $tiposDocumento = [
            'RG/CNH - Frente',
            'RG/CNH - Verso',
            'Selfie Segurando Documento',
            'Comprovante da Atividade',
            'Comprovante de Endereço',
            'Contrato Social (Se for MEI - CCMEI)',
        ];
        $documentosPorTipo = $estabelecimento->documentos->keyBy('tipo_documento');
        $totalDocumentos = $estabelecimento->documentos->count();
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
        <div>
            <h3 class="text-base font-bold text-gray-800">Documentos</h3>
            <p class="text-xs text-gray-400">{{ $totalDocumentos }} documento(s) anexado(s). A análise KYC (PPID) inicia automaticamente ao enviar aqui.</p>
        </div>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
            {{ $documentosPorTipo->count() }}/{{ count($tiposDocumento) }} tipos enviados
        </span>
    </div>

    <div class="grid gap-6 p-5 xl:grid-cols-[1fr_420px]">
        <div class="space-y-4">
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($tiposDocumento as $tipo)
                    @php
                        $documentoTipo = $documentosPorTipo->get($tipo);
                    @endphp
                    <div class="rounded-xl border {{ $documentoTipo ? 'border-emerald-100 bg-emerald-50/40' : 'border-gray-100 bg-gray-50' }} p-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full {{ $documentoTipo ? 'bg-emerald-500 text-white' : 'bg-gray-200 text-gray-500' }}">
                                <i class="fa-solid {{ $documentoTipo ? 'fa-check' : 'fa-file-arrow-up' }}"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-gray-800">{{ $tipo }}</p>
                                @if ($documentoTipo)
                                    <p class="mt-1 truncate text-xs text-gray-500">{{ $documentoTipo->arquivo_nome ?: basename($documentoTipo->arquivo_path) }}</p>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        <a href="{{ route('documentos.download', $documentoTipo) }}" class="rounded-lg border border-emerald-200 bg-white px-3 py-1.5 text-xs font-bold text-emerald-700 hover:bg-emerald-50">
                                            <i class="fa-solid fa-eye mr-1"></i> Visualizar
                                        </a>
                                        <form method="POST" action="{{ route('estabelecimentos.documentos.destroy', [$estabelecimento, $documentoTipo]) }}" onsubmit="return confirm('Remover este documento?')">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-lg border border-red-100 bg-white px-3 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50">
                                                <i class="fa-solid fa-trash mr-1"></i> Remover
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <p class="mt-1 text-xs text-gray-400">Pendente de envio.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <form method="POST" action="{{ route('estabelecimentos.documentos.store', $estabelecimento) }}" enctype="multipart/form-data" class="rounded-xl border border-gray-100 bg-gray-50 p-4">
            @csrf
            <div class="mb-4">
                <h4 class="text-sm font-bold text-gray-800">Adicionar documento</h4>
                <p class="text-xs text-gray-400">PDF, imagem ou Word até 25MB.</p>
            </div>

            <label class="block space-y-1">
                <span class="text-xs font-bold uppercase tracking-wide text-gray-500">Tipo de Documento</span>
                <select name="tipo_documento" class="w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-700">
                    <option value="">Selecione</option>
                    @foreach ($tiposDocumento as $tipo)
                        <option>{{ $tipo }}</option>
                    @endforeach
                </select>
            </label>

            <label data-dropzone class="mt-4 flex min-h-44 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-blue-300 bg-blue-50 px-4 py-6 text-center transition-colors hover:border-blue-500 hover:bg-blue-100">
                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-blue-600 text-white shadow-sm">
                    <i class="fa-solid fa-cloud-arrow-up text-2xl"></i>
                </span>
                <span class="mt-3 text-sm font-bold text-gray-800">Arraste o documento aqui</span>
                <span data-file-name class="mt-1 text-xs text-gray-500">ou clique para escolher um arquivo</span>
                <input data-file-input type="file" name="documento" class="sr-only" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx">
            </label>

            <button class="mt-4 w-full rounded-lg bg-teal-500 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-teal-600">
                <i class="fa-solid fa-plus mr-1"></i> Adicionar Documento
            </button>
        </form>
    </div>

    <div class="border-t border-gray-100 bg-gray-50 p-5">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="mb-3 flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-indigo-700">
                    <i class="fa-solid fa-link"></i>
                </div>
                <div>
                    <p class="text-sm font-bold text-gray-800">Link para envio de documentos</p>
                    <p class="text-xs text-gray-400">Compartilhe este link para o estabelecimento anexar arquivos.</p>
                </div>
            </div>
            <div class="flex">
                <input data-public-link readonly value="{{ route('documentos.public.create', $estabelecimento->documento_token_publico) }}" class="w-full rounded-l-lg border border-gray-300 bg-gray-50 px-3 py-2.5 text-sm text-gray-700">
                <button type="button" data-copy-public-link class="rounded-r-lg border border-l-0 border-indigo-900 px-5 text-sm font-bold text-indigo-900 hover:bg-indigo-50">Copiar</button>
            </div>
        </div>
    </div>
</section>

<section id="logs" data-tab-panel="logs" class="mt-8 hidden overflow-hidden rounded border border-gray-200 bg-white shadow-sm">
    @php
        $acaoLabel = [
            'documento_inserido' => ['Documento enviado', 'bg-emerald-100 text-emerald-700', 'fa-file-circle-plus'],
            'documento_removido' => ['Documento removido', 'bg-red-100 text-red-700', 'fa-file-circle-minus'],
            'update_status' => ['Status alterado', 'bg-blue-100 text-blue-700', 'fa-rotate'],
            'insert' => ['Cadastro criado', 'bg-emerald-100 text-emerald-700', 'fa-plus'],
            'update' => ['Cadastro atualizado', 'bg-blue-100 text-blue-700', 'fa-pen'],
            'email_criado' => ['E-mail criado', 'bg-blue-100 text-blue-700', 'fa-envelope'],
            'email_senha_alterada' => ['Senha do e-mail', 'bg-blue-100 text-blue-700', 'fa-lock'],
            'email_redirecionado' => ['Redirecionamento', 'bg-blue-100 text-blue-700', 'fa-share'],
            'email_removido' => ['E-mail removido', 'bg-red-100 text-red-700', 'fa-trash'],
            'pagbank_edi_atualizado' => ['EDI PagBank', 'bg-indigo-100 text-indigo-700', 'fa-building-columns'],
        ];
    @endphp
    <div class="border-b border-gray-200 bg-gray-50 px-4 py-4">
        <h3 class="text-sm font-bold text-gray-800">Logs do Estabelecimento</h3>
        <p class="mt-0.5 text-xs text-gray-500">Ações internas do sistema.</p>
    </div>

    <div class="p-4">
        <table class="w-full border border-gray-200 text-sm">
            <thead>
                <tr class="bg-gray-100">
                    <th class="border border-gray-200 px-2 py-2 text-left font-bold text-gray-900">#</th>
                    <th class="border border-gray-200 px-2 py-2 text-left font-bold text-gray-900">Ação</th>
                    <th class="border border-gray-200 px-2 py-2 text-left font-bold text-gray-900">Usuário</th>
                    <th class="border border-gray-200 px-2 py-2 text-left font-bold text-gray-900">Mensagem</th>
                    <th class="border border-gray-200 px-2 py-2 text-left font-bold text-gray-900">Data</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($logs as $log)
                    @php
                        $acao = $acaoLabel[$log->acao] ?? [ucfirst(str_replace('_', ' ', $log->acao)), 'bg-gray-100 text-gray-700', 'fa-circle-info'];
                    @endphp
                    <tr>
                        <td class="border border-gray-200 px-2 py-2">{{ $log->id }}</td>
                        <td class="border border-gray-200 px-2 py-2">
                            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold {{ $acao[1] }}">
                                <i class="fa-solid {{ $acao[2] }}"></i>
                                {{ $acao[0] }}
                            </span>
                        </td>
                        <td class="border border-gray-200 px-2 py-2">{{ $log->usuario_nome ?: '-' }}</td>
                        <td class="border border-gray-200 px-2 py-2">{{ $log->mensagem ?: '-' }}</td>
                        <td class="border border-gray-200 px-2 py-2">{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="border border-gray-200 px-2 py-8 text-center text-gray-400">Nenhum log encontrado para este estabelecimento.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
@endsection

@push('modals')
    @include('estabelecimento.partials.automacao-confirmacao-modal')

    @if ($estabelecimento->fv_job_id)
        <div id="automacao-screenshots-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/70 p-4" aria-hidden="true">
            <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <div>
                        <h3 class="text-sm font-bold text-gray-900">Screenshots da automação</h3>
                        <p class="text-xs text-gray-500">Todas as capturas deste cadastro, em ordem de execução.</p>
                    </div>
                    <button type="button" data-fechar-screenshots-automacao class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-800">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div id="automacao-screenshots-modal-body" class="overflow-y-auto p-4">
                    <div id="automacao-screenshots-modal-loading" class="py-10 text-center text-sm text-gray-500">
                        <i class="fa-solid fa-spinner fa-spin mr-1"></i> Carregando screenshots...
                    </div>
                    <div id="automacao-screenshots-modal-grid" class="hidden grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>
                </div>
            </div>
        </div>
    @endif

    {{-- ── Modal: Criar E-mail Plataforma ── --}}
    @if ($estabelecimento->email && blank($estabelecimento->webmail_email))
    @php $usernameSugerido = preg_replace('/[^a-z0-9._-]/i', '', strtolower(str($estabelecimento->email)->before('@')->value())); @endphp
    <div data-modal="webmail-criar" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Criar E-mail Plataforma</h3>
                    @php $dominioPlataforma = config('directadmin.dominio'); @endphp
                    <p class="mt-0.5 text-xs text-gray-500">Será criado <strong>username&#64;{{ $dominioPlataforma }}</strong></p>
                </div>
                <button type="button" data-modal-close="webmail-criar" class="text-2xl text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <form method="POST" action="{{ route('estabelecimentos.webmail.criar', $estabelecimento) }}">
                @csrf
                <label class="block space-y-1">
                    <span class="text-sm font-bold text-gray-700">Nome do e-mail</span>
                    <div class="flex items-center overflow-hidden rounded-lg border border-gray-300 bg-white focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
                        <input
                            type="text"
                            name="username"
                            value="{{ old('username', $usernameSugerido) }}"
                            placeholder="nome.sobrenome"
                            class="flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-gray-800 outline-none"
                            pattern="[a-zA-Z0-9._-]+"
                            title="Apenas letras, números, ponto, hífen ou sublinhado"
                            required
                        >
                        <span class="select-none bg-gray-50 px-3 py-2.5 text-sm text-gray-500 border-l border-gray-200">&#64;{{ $dominioPlataforma }}</span>
                    </div>
                    @error('username')
                        <p class="text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </label>
                <p class="mt-2 text-xs text-gray-400">
                    O e-mail criado redirecionará automaticamente para <strong>{{ $estabelecimento->email }}</strong>.
                </p>
                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" data-modal-close="webmail-criar" class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <i class="fa-solid fa-plus mr-1"></i> Criar E-mail
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- ── Modal: Criar outro E-mail Plataforma (substituir o atual) ── --}}
    @if ($estabelecimento->webmail_email && in_array(auth()->user()?->tipo, ['admin', 'marketplace'], true))
    @php $dominioPlataforma = config('directadmin.dominio'); @endphp
    <div data-modal="webmail-recriar" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Criar outro e-mail</h3>
                    <p class="mt-0.5 text-xs text-gray-500">Substitui <strong>{{ $estabelecimento->webmail_email }}</strong></p>
                </div>
                <button type="button" data-modal-close="webmail-recriar" class="text-2xl text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                <i class="fa-solid fa-triangle-exclamation mr-1"></i>
                A caixa atual <strong>{{ $estabelecimento->webmail_email }}</strong> será removida do servidor (mensagens e senha incluídas) e substituída pela nova.
            </div>
            <form method="POST" action="{{ route('estabelecimentos.webmail.recriar', $estabelecimento) }}">
                @csrf
                <label class="block space-y-1">
                    <span class="text-sm font-bold text-gray-700">Novo nome do e-mail</span>
                    <div class="flex items-center overflow-hidden rounded-lg border border-gray-300 bg-white focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
                        <input
                            type="text"
                            name="username_novo"
                            value="{{ old('username_novo') }}"
                            placeholder="nome.sobrenome"
                            class="flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-gray-800 outline-none"
                            pattern="[a-zA-Z0-9._-]+"
                            title="Apenas letras, números, ponto, hífen ou sublinhado"
                            required
                        >
                        <span class="select-none bg-gray-50 px-3 py-2.5 text-sm text-gray-500 border-l border-gray-200">&#64;{{ $dominioPlataforma }}</span>
                    </div>
                    @error('username_novo')
                        <p class="text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </label>
                @if (filled($estabelecimento->email))
                    <p class="mt-2 text-xs text-gray-400">
                        O novo e-mail redirecionará automaticamente para <strong>{{ $estabelecimento->email }}</strong>.
                    </p>
                @endif
                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" data-modal-close="webmail-recriar" class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-purple-600 px-5 py-2 text-sm font-bold text-white hover:bg-purple-700">
                        <i class="fa-solid fa-envelope-circle-check mr-1"></i> Criar e substituir
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    {{-- ── Modal: Trocar Senha do E-mail Plataforma ── --}}
    @if ($estabelecimento->webmail_email)
    <div data-modal="webmail-senha" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="mb-5 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Trocar Senha do E-mail</h3>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $estabelecimento->webmail_email }}</p>
                </div>
                <button type="button" data-modal-close="webmail-senha" class="text-2xl text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <form method="POST" action="{{ route('estabelecimentos.webmail.senha', $estabelecimento) }}">
                @csrf
                @method('PATCH')
                <div class="space-y-3">
                    <label class="block space-y-1">
                        <span class="text-sm font-bold text-gray-700">Nova senha</span>
                        <input type="password" name="senha" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" placeholder="Mínimo 8 caracteres" required minlength="8" autocomplete="new-password">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-bold text-gray-700">Confirmar senha</span>
                        <input type="password" name="senha_confirmation" class="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100" placeholder="Repita a nova senha" required autocomplete="new-password">
                    </label>
                    @error('senha_webmail')
                        <p class="text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" data-modal-close="webmail-senha" class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-bold text-white hover:bg-blue-700">
                        <i class="fa-solid fa-key mr-1"></i> Alterar Senha
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <div data-modal="status" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-xl rounded bg-white p-6 shadow-xl">
            <div class="mb-6 flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-700">Alteração de Status</h3>
                <button type="button" data-modal-close="status" class="text-2xl text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <form method="POST" action="{{ route('estabelecimentos.status.update', $estabelecimento) }}" class="space-y-4">
                @csrf
                @method('PATCH')
                <label class="block space-y-1">
                    <span class="text-sm font-bold text-gray-800">Status operacional</span>
                    <select name="status" required class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                        @foreach (['pendente' => 'PENDENTE', 'aprovado' => 'APROVADO', 'negado' => 'NEGADO'] as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected(\App\Support\EstabelecimentoEtapaListagem::normalizarStatus($estabelecimento->status) === $valor)>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </label>
                @if ($pagbankStatusManualDisponivel)
                <label class="block space-y-1">
                    <span class="text-sm font-bold text-gray-800">Status PagBank</span>
                    <select name="pagbank_status_manual" class="w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                        <option value="">Automático — {{ $pagbankAutoLabel }} (conforme automação)</option>
                        @foreach (['pendente' => 'PENDENTE', 'aprovado' => 'APROVADO', 'negado' => 'NEGADO'] as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected($estabelecimento->pagbank_status_manual === $valor)>{{ $rotulo }} (manual)</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500">
                        Exibido na listagem:
                        <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $pagbankClass }}">{{ $pagbankLabel }}</span>
                        @if (filled($estabelecimento->pagbank_status_manual))
                            <span class="text-amber-600">· override manual ativo</span>
                        @endif
                    </p>
                </label>
                @else
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    Status PagBank na listagem: <strong>{{ $pagbankLabel }}</strong> (automático).
                    Para override manual, execute <code class="rounded bg-amber-100 px-1">php artisan migrate --force</code> no servidor.
                </div>
                @endif
                <label class="block space-y-1">
                    <span class="text-sm font-bold text-gray-800">Observação</span>
                    <textarea name="observacao" rows="4" placeholder="Observação de status..." class="w-full rounded border border-gray-300 px-3 py-2 text-sm text-gray-700"></textarea>
                </label>
                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" data-modal-close="status" class="rounded bg-gray-100 px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-200">Fechar</button>
                    <button class="rounded bg-indigo-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-indigo-800">Alterar</button>
                </div>
            </form>
        </div>
    </div>

    @if (auth()->user()?->tipo === 'admin' && $estabelecimento->ativo)
    <div data-modal="inativar-sistema" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/40 px-4">
        <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
            <div class="mb-5 flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-bold text-red-700">Inativar cadastro</h3>
                    <p class="mt-1 text-xs text-gray-500">O registro não será excluído do banco de dados.</p>
                </div>
                <button type="button" data-modal-close="inativar-sistema" class="text-2xl leading-none text-gray-400 hover:text-gray-600">&times;</button>
            </div>

            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                <p class="font-semibold">O que acontece:</p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    <li>O status será alterado para <strong>NEGADO</strong> e o cadastro ficará oculto das listagens</li>
                    <li>O cadastro deixa de aparecer nas listagens e buscas</li>
                    <li>Os dados permanecem salvos para histórico e auditoria</li>
                </ul>
            </div>

            <form method="POST" action="{{ route('estabelecimentos.inativar-sistema', $estabelecimento) }}" class="space-y-4">
                @csrf
                <label class="block space-y-1">
                    <span class="text-sm font-bold text-gray-800">Sua senha de administrador</span>
                    <div class="relative">
                        <input type="password" name="senha_admin" id="senha-admin-inativar" autocomplete="current-password" required
                               class="w-full rounded-lg border border-gray-300 py-2 pl-3 pr-10 text-sm @error('senha_admin') border-red-500 @enderror">
                        <button type="button" id="toggle-senha-admin-inativar"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                                aria-label="Mostrar senha">
                            <i class="fa-regular fa-eye" id="toggle-senha-admin-inativar-icon"></i>
                        </button>
                    </div>
                    @error('senha_admin')
                        <p class="text-xs font-medium text-red-600">{{ $message }}</p>
                    @enderror
                </label>
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="confirmacao" value="1" required class="mt-1 rounded border-gray-300">
                    <span>Confirmo que desejo inativar <strong>{{ $nome }}</strong> no sistema.</span>
                </label>
                @error('confirmacao')
                    <p class="text-xs font-medium text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" data-modal-close="inativar-sistema"
                            class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-100">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-5 py-2 text-sm font-bold text-white hover:bg-red-700">
                        <i class="fa-solid fa-trash-can"></i> Confirmar inativação
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif
@endpush

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const tabLinks = document.querySelectorAll('[data-tab-link]');
        const tabPanels = document.querySelectorAll('[data-tab-panel]');
        const tabActive = ['border-blue-200', 'bg-blue-50', 'text-blue-800'];
        const tabIdle = ['border-gray-200', 'bg-white', 'text-gray-700'];

        const setTabStyles = (link, active) => {
            tabActive.forEach((c) => link.classList.toggle(c, active));
            tabIdle.forEach((c) => link.classList.toggle(c, !active));
        };

        const showTab = (name) => {
            const tab = name || 'resumo';
            tabPanels.forEach((panel) => {
                panel.classList.toggle('hidden', panel.dataset.tabPanel !== tab);
            });
            tabLinks.forEach((link) => setTabStyles(link, link.dataset.tabLink === tab));
            if (tab !== 'resumo') {
                history.replaceState(null, '', `#${tab}`);
            } else {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        };

        tabLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                event.preventDefault();
                showTab(link.dataset.tabLink);
            });
        });

        const hashTab = window.location.hash.replace('#', '');
        const validTabs = ['resumo', 'automacao', 'kyc', 'email-plataforma', 'documentos', 'logs'];
        if (validTabs.includes(hashTab)) {
            showTab(hashTab);
        } else {
            showTab('resumo');
        }

        // Botões internos que trocam de aba (ex: "Anexar Documentos" na aba KYC)
        document.querySelectorAll('[data-tab-link-target]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                showTab(btn.dataset.tabLinkTarget);
            });
        });

        // Reabrir modal de webmail se houver erros de validação
        @if ($errors->has('username'))
            document.querySelector('[data-modal="webmail-criar"]')?.classList.add('is-open');
            showTab('email-plataforma');
        @endif
        @if ($errors->has('username_novo'))
            document.querySelector('[data-modal="webmail-recriar"]')?.classList.add('is-open');
            showTab('email-plataforma');
        @endif
        @if ($errors->has('senha_webmail') || $errors->has('senha'))
            document.querySelector('[data-modal="webmail-senha"]')?.classList.add('is-open');
            showTab('email-plataforma');
        @endif
        @if ($errors->has('senha_admin') || $errors->has('confirmacao') || session('abrir_modal_inativar'))
            document.querySelector('[data-modal="inativar-sistema"]')?.classList.add('is-open');
        @endif
        @if ($errors->has('automacao') || session('proposta_aceitando'))
            showTab('automacao');
        @endif

        document.getElementById('toggle-senha-admin-inativar')?.addEventListener('click', () => {
            const input = document.getElementById('senha-admin-inativar');
            const icon = document.getElementById('toggle-senha-admin-inativar-icon');
            if (!input || !icon) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !show);
            icon.classList.toggle('fa-eye-slash', show);
        });

        document.querySelectorAll('[data-modal-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const modalName = button.dataset.modalOpen;
                const modal = document.querySelector(`[data-modal="${modalName}"]`);
                modal?.classList.add('is-open');

                if (modalName === 'automacao-confirmar' && button.dataset.automacaoLabel) {
                    const label = document.getElementById('automacao-confirmar-label');
                    if (label) {
                        label.textContent = button.dataset.automacaoLabel;
                    }
                }
            });
        });

        const closeModal = (name) => {
            const modal = document.querySelector(`[data-modal="${name}"]`);
            modal?.classList.remove('is-open');
        };

        document.querySelectorAll('[data-modal-close]').forEach((button) => {
            button.addEventListener('click', () => closeModal(button.dataset.modalClose));
        });

        document.querySelectorAll('.modal-overlay').forEach((modal) => {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    modal.classList.remove('is-open');
                }
            });
        });

        const dropzone = document.querySelector('[data-dropzone]');
        const fileInput = document.querySelector('[data-file-input]');
        const fileName = document.querySelector('[data-file-name]');

        if (dropzone && fileInput && fileName) {
            ['dragenter', 'dragover'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropzone.classList.add('border-blue-500', 'bg-blue-100');
                });
            });

            ['dragleave', 'drop'].forEach((eventName) => {
                dropzone.addEventListener(eventName, (event) => {
                    event.preventDefault();
                    dropzone.classList.remove('border-blue-500', 'bg-blue-100');
                });
            });

            dropzone.addEventListener('drop', (event) => {
                if (event.dataTransfer.files.length) {
                    fileInput.files = event.dataTransfer.files;
                    fileName.textContent = event.dataTransfer.files[0].name;
                }
            });

            fileInput.addEventListener('change', () => {
                fileName.textContent = fileInput.files[0]?.name || 'ou clique para escolher um arquivo';
            });
        }

        document.querySelector('[data-copy-public-link]')?.addEventListener('click', async () => {
            const input = document.querySelector('[data-public-link]');
            await navigator.clipboard.writeText(input.value);
        });

        const formBuscarSafepay = document.getElementById('form-buscar-safepay');
        const btnBuscarSafepay = document.getElementById('btn-buscar-safepay');
        formBuscarSafepay?.addEventListener('submit', () => {
            if (!btnBuscarSafepay) return;
            btnBuscarSafepay.disabled = true;
            btnBuscarSafepay.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Pesquisando...</span>';
        });

        if (document.getElementById('safepay-busca-ativa')) {
            setInterval(() => window.location.reload(), 15000);
        }

        const formAceitarProposta = document.getElementById('form-aceitar-proposta');
        const btnAceitarProposta = document.getElementById('btn-aceitar-proposta');
        formAceitarProposta?.addEventListener('submit', () => {
            if (!btnAceitarProposta) return;
            btnAceitarProposta.disabled = true;
            btnAceitarProposta.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span> Iniciando...</span>';
        });

        if (document.getElementById('proposta-aceitando-ativa') || document.querySelector('[data-automacao-poll]')?.textContent?.includes('Aceitando proposta')) {
            setInterval(() => window.location.reload(), 20000);
        }

        // Polling da automação FV — atualiza etapa a cada 20s
        const automacaoPollBox = document.querySelector('[data-automacao-poll]');
        if (automacaoPollBox) {
            const statusUrl = automacaoPollBox.dataset.statusUrl;
            const etapaTexto = document.getElementById('automacao-etapa-texto');
            const logsBody = document.getElementById('automacao-logs-body');
            const statusFinal = ['concluido', 'erro', 'erro_email', 'erro_proposta', 'timeout'];

            const nivelClasse = (nivel) => {
                if (nivel === 'sucesso') return 'bg-emerald-100 text-emerald-800';
                if (nivel === 'erro') return 'bg-red-100 text-red-800';
                if (nivel === 'aviso') return 'bg-amber-100 text-amber-800';
                return 'bg-blue-50 text-blue-800';
            };

            const renderLogs = (logs) => {
                if (!logsBody || !Array.isArray(logs) || logs.length === 0) return;

                const existentes = new Set(
                    [...logsBody.querySelectorAll('[data-log-id]')].map((row) => row.dataset.logId)
                );

                const vazio = document.getElementById('automacao-logs-empty');
                if (vazio) vazio.remove();

                logs.forEach((log) => {
                    const id = String(log.id);
                    if (existentes.has(id)) return;

                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-gray-50';
                    tr.dataset.logId = id;
                    tr.innerHTML = `
                        <td class="whitespace-nowrap px-3 py-2 text-gray-500">${log.created_at || '—'}</td>
                        <td class="whitespace-nowrap px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${nivelClasse(log.nivel)}">${log.nivel || 'info'}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-600">${log.etapa || '—'}</td>
                        <td class="px-3 py-2 text-gray-800">${log.mensagem || '—'}</td>
                    `;
                    logsBody.appendChild(tr);
                    existentes.add(id);
                });

                logsBody.parentElement?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            };

            const atualizarAutomacao = async () => {
                try {
                    const resp = await fetch(statusUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!resp.ok) return;

                    const data = await resp.json();
                    if (data.etapa_atual && etapaTexto) {
                        etapaTexto.textContent = data.etapa_atual;
                    }

                    if (data.automacao_logs) {
                        renderLogs(data.automacao_logs);
                    }

                    const apiStatus = data.status || data.fv_status;
                    if (statusFinal.includes(apiStatus) || statusFinal.includes(data.fv_status)) {
                        window.location.reload();
                    }
                } catch (_) {
                    // silencioso — tenta novamente no próximo ciclo
                }
            };

            atualizarAutomacao();
            setInterval(atualizarAutomacao, 20000);
        }

        // Galeria de screenshots da automação
        const screenshotsSection = document.getElementById('automacao-screenshots-section');
        if (screenshotsSection) {
            const listUrl = screenshotsSection.dataset.listUrl;
            const imageUrl = (filename) => `${listUrl.replace(/\/$/, '')}/${encodeURIComponent(filename)}`;
            const grid = document.getElementById('automacao-screenshots-grid');
            const loading = document.getElementById('automacao-screenshots-loading');
            const empty = document.getElementById('automacao-screenshots-empty');
            const btnRecarregar = document.getElementById('automacao-screenshots-recarregar');
            const modal = document.getElementById('automacao-screenshots-modal');
            const modalGrid = document.getElementById('automacao-screenshots-modal-grid');
            const modalLoading = document.getElementById('automacao-screenshots-modal-loading');
            let screenshotsCache = [];

            const formatarTamanho = (bytes) => {
                if (!bytes || bytes < 1024) return `${bytes || 0} B`;
                if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
                return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
            };

            const buscarScreenshots = async () => {
                const resp = await fetch(listUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });

                let data = {};
                try {
                    data = await resp.json();
                } catch (_) {
                    data = {};
                }

                if (!resp.ok) {
                    throw new Error(data.erro || `Erro ${resp.status} ao carregar screenshots`);
                }

                return data.screenshots || [];
            };

            const exibirErroScreenshots = (mensagem, alvo) => {
                if (!alvo) return;
                alvo.classList.remove('hidden');
                alvo.innerHTML = `<i class="fa-solid fa-triangle-exclamation mr-1 text-amber-600"></i> ${mensagem}`;
            };

            const blobUrls = new Set();

            const carregarImagemAutenticada = async (url) => {
                const resp = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'image/png' },
                });

                if (!resp.ok) {
                    throw new Error(`Imagem indisponível (HTTP ${resp.status})`);
                }

                const blob = await resp.blob();
                const objectUrl = URL.createObjectURL(blob);
                blobUrls.add(objectUrl);
                return objectUrl;
            };

            const aplicarImagensAutenticadas = (root) => {
                if (!root) return;

                root.querySelectorAll('[data-screenshot-src]').forEach((img) => {
                    const url = img.dataset.screenshotSrc;
                    carregarImagemAutenticada(url)
                        .then((objectUrl) => {
                            img.src = objectUrl;
                            img.classList.remove('opacity-30');
                        })
                        .catch(() => {
                            img.alt = 'Falha ao carregar imagem';
                            img.classList.add('opacity-30');
                        });
                });

                root.querySelectorAll('[data-screenshot-link]').forEach((link) => {
                    const url = link.dataset.screenshotLink;
                    carregarImagemAutenticada(url)
                        .then((objectUrl) => {
                            link.href = objectUrl;
                        })
                        .catch(() => {
                            link.classList.add('pointer-events-none', 'opacity-50');
                        });
                });

                root.querySelectorAll('[data-screenshot-download]').forEach((link) => {
                    const url = link.dataset.screenshotDownload;
                    carregarImagemAutenticada(url)
                        .then((objectUrl) => {
                            link.href = objectUrl;
                        })
                        .catch(() => {
                            link.classList.add('pointer-events-none', 'opacity-50');
                        });
                });
            };

            const cardScreenshotHtml = (nome, tamanho, grande = false) => {
                const url = imageUrl(nome);
                const altura = grande ? 'max-h-[70vh] object-contain' : 'h-40 object-cover object-top';

                return `
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <a href="#" data-screenshot-link="${url}" target="_blank" rel="noopener" class="block bg-gray-900/5">
                            <img data-screenshot-src="${url}" alt="${nome}" loading="lazy"
                                 class="w-full opacity-30 transition hover:opacity-90 ${altura}">
                        </a>
                        <div class="flex items-center justify-between gap-2 border-t border-gray-100 px-3 py-2">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-[11px] text-gray-700" title="${nome}">${nome}</p>
                                ${tamanho ? `<p class="text-[10px] text-gray-400">${formatarTamanho(tamanho)}</p>` : ''}
                            </div>
                            <a href="#" data-screenshot-download="${url}" data-filename="${nome}"
                               class="shrink-0 rounded border border-gray-200 px-2 py-1 text-[10px] font-medium text-gray-600 hover:border-indigo-300 hover:text-indigo-700">
                                <i class="fa-solid fa-download"></i>
                            </a>
                        </div>
                    </div>
                `;
            };

            const renderScreenshots = (items) => {
                screenshotsCache = items;
                if (!grid || !loading || !empty) return;

                loading.classList.add('hidden');

                if (!Array.isArray(items) || items.length === 0) {
                    grid.classList.add('hidden');
                    grid.innerHTML = '';
                    empty.classList.remove('hidden');
                    return;
                }

                empty.classList.add('hidden');
                grid.classList.remove('hidden');
                grid.innerHTML = items.map((item) => {
                    const arquivo = item.arquivo || item;
                    const nome = typeof arquivo === 'string' ? arquivo : arquivo.arquivo;
                    const tamanho = typeof item === 'object' ? item.tamanho : null;
                    return cardScreenshotHtml(nome, tamanho, false);
                }).join('');
                aplicarImagensAutenticadas(grid);
            };

            const renderModalScreenshots = (items) => {
                if (!modalGrid || !modalLoading) return;

                modalLoading.classList.add('hidden');

                if (!Array.isArray(items) || items.length === 0) {
                    modalGrid.classList.add('hidden');
                    modalLoading.classList.remove('hidden');
                    modalLoading.innerHTML = 'Nenhum screenshot disponível para este cadastro.';
                    return;
                }

                modalGrid.classList.remove('hidden');
                modalGrid.innerHTML = items.map((item) => {
                    const arquivo = item.arquivo || item;
                    const nome = typeof arquivo === 'string' ? arquivo : arquivo.arquivo;
                    const tamanho = typeof item === 'object' ? item.tamanho : null;
                    return cardScreenshotHtml(nome, tamanho, true);
                }).join('');
                aplicarImagensAutenticadas(modalGrid);
            };

            const abrirModalScreenshots = async () => {
                if (!modal) return;

                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
                screenshotsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });

                if (screenshotsCache.length > 0) {
                    renderModalScreenshots(screenshotsCache);
                    return;
                }

                if (modalLoading) {
                    modalLoading.classList.remove('hidden');
                    modalLoading.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Carregando screenshots...';
                }
                if (modalGrid) modalGrid.classList.add('hidden');

                try {
                    const items = await buscarScreenshots();
                    renderModalScreenshots(items);
                } catch (error) {
                    exibirErroScreenshots(
                        error.message || 'Não foi possível carregar os screenshots.',
                        modalLoading,
                    );
                }
            };

            const fecharModalScreenshots = () => {
                if (!modal) return;
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
                blobUrls.forEach((url) => URL.revokeObjectURL(url));
                blobUrls.clear();
            };

            document.querySelectorAll('[data-abrir-screenshots-automacao]').forEach((btn) => {
                btn.addEventListener('click', abrirModalScreenshots);
            });

            document.querySelectorAll('[data-fechar-screenshots-automacao]').forEach((btn) => {
                btn.addEventListener('click', fecharModalScreenshots);
            });

            modal?.addEventListener('click', (event) => {
                if (event.target === modal) fecharModalScreenshots();
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') fecharModalScreenshots();
            });

            const carregarScreenshots = async () => {
                if (loading) loading.classList.remove('hidden');
                if (empty) empty.classList.add('hidden');
                if (grid) grid.classList.add('hidden');

                try {
                    const items = await buscarScreenshots();
                    renderScreenshots(items);
                } catch (error) {
                    if (loading) loading.classList.add('hidden');
                    exibirErroScreenshots(
                        error.message || 'Não foi possível carregar os screenshots.',
                        empty,
                    );
                    empty?.classList.remove('hidden');
                }
            };

            btnRecarregar?.addEventListener('click', carregarScreenshots);
            carregarScreenshots();
        }

    });
</script>
@endsection
