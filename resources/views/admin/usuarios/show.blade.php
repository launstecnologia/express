@extends('layouts.app')

@php
    $tipoLabel = [
        'admin' => 'Administrador',
        'master' => 'Master',
        'marketplace' => 'Marketplace',
        'revenda' => 'Revenda',
    ];
    $filhos = $usuario->hierarquia?->filhos ?? collect();
    $documento = $usuario->pessoa_tipo === 'fisica' ? $usuario->cpf : $usuario->cnpj;
    $endereco = collect([$usuario->endereco, $usuario->numero, $usuario->bairro, $usuario->cidade, $usuario->uf])->filter()->join(', ');
@endphp

@section('title', 'Detalhes do '.$tipoLabel[$usuario->tipo])

@section('content')
<div class="mb-4 flex items-center justify-between gap-3">
    <div class="text-sm text-gray-500">
        <a href="{{ route('usuarios.index', in_array($usuario->tipo, ['master', 'marketplace', 'revenda'], true) ? ['tipo' => $usuario->tipo] : []) }}" class="font-semibold text-gray-700 hover:text-blue-600">{{ $tipoLabel[$usuario->tipo] }}</a>
        <span class="mx-2">›</span>
        <span>Detalhes</span>
    </div>
</div>

<section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold uppercase text-gray-800">{{ $usuario->nomeExibicao() }}</h2>
            <p class="mt-1 text-sm text-gray-400">
                @if ($usuario->tipo === 'admin')
                    Administrador da plataforma.
                @else
                    {{ $tipoLabel[$usuario->tipo] }} vinculado à hierarquia comercial.
                @endif
            </p>
        </div>
    </div>

    @if ($usuario->tipo !== 'admin')
    <div class="mt-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Documento</p>
            <p class="mt-2 font-semibold text-gray-800">{{ $documento ?: '-' }}</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-400">
                @if ($usuario->tipo === 'marketplace') Master
                @elseif ($usuario->tipo === 'revenda') Marketplace
                @else Pai Hierárquico
                @endif
            </p>
            <p class="mt-2 font-semibold text-gray-800">{{ $usuario->hierarquia?->pai?->usuario?->nomeExibicao() ?: '-' }}</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Usuários</p>
            <p class="mt-2 font-semibold text-gray-800">{{ $filhos->count() }}</p>
        </div>
        <div class="rounded-xl bg-gray-50 p-4">
            <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Estabelecimentos</p>
            <p class="mt-2 font-semibold text-gray-800">{{ $usuario->estabelecimentos->count() }}</p>
        </div>
    </div>
    @endif

    <div class="mt-6 grid gap-x-8 gap-y-3 border-t border-dashed border-gray-200 pt-5 text-sm text-gray-600 md:grid-cols-3">
        @if ($usuario->tipo === 'admin')
            <p><span class="font-semibold text-gray-700">Nome:</span> {{ $usuario->nome_completo ?: '-' }}</p>
            <p><span class="font-semibold text-gray-700">CPF:</span> {{ $usuario->cpf ?: '-' }}</p>
            <p><span class="font-semibold text-gray-700">E-mail:</span> {{ $usuario->email }}</p>
            <p><span class="font-semibold text-gray-700">Telefone:</span> {{ $usuario->telefone ?: '-' }}</p>
            <p><span class="font-semibold text-gray-700">Celular:</span> {{ $usuario->celular ?: '-' }}</p>
            @if ($endereco)
                <p><span class="font-semibold text-gray-700">Endereço:</span> {{ $endereco }}</p>
            @endif
        @else
            <p><span class="font-semibold text-gray-700">{{ $usuario->pessoa_tipo === 'fisica' ? 'Nome' : 'Razão social' }}:</span> {{ ($usuario->pessoa_tipo === 'fisica' ? $usuario->nome_completo : $usuario->razao_social) ?: '-' }}</p>
            @if ($usuario->percentual_retencao_pai !== null && in_array($usuario->tipo, ['marketplace', 'revenda'], true))
                <p>
                    <span class="font-semibold text-gray-700">Royalties:</span>
                    {{ number_format((float) $usuario->percentual_retencao_pai, 0, ',', '.') }}%
                </p>
            @endif
            <p><span class="font-semibold text-gray-700">E-mail:</span> {{ $usuario->email }}</p>
            <p><span class="font-semibold text-gray-700">Telefone:</span> {{ $usuario->telefone ?: '-' }}</p>
            <p><span class="font-semibold text-gray-700">Celular:</span> {{ $usuario->celular ?: '-' }}</p>
            <p><span class="font-semibold text-gray-700">Endereço:</span> {{ $endereco ?: '-' }}</p>
        @endif
    </div>

    @if ($usuario->tipo === 'marketplace' && \App\Support\UsuarioComercial::podeLiberarPlanosMarketplace($usuario))
        @php $planosHabilitados = $usuario->planosHabilitados; @endphp
        <div class="mt-4 border-t border-dashed border-gray-200 pt-4" @if ($planosHabilitados->count() > 6) x-data="{ verTodos: false }" @endif>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-bold uppercase tracking-wide text-gray-400">
                    Planos habilitados
                    @if ($planosHabilitados->isNotEmpty())
                        <span class="ml-1 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-700">{{ $planosHabilitados->count() }}</span>
                    @endif
                </p>
                @if ($planosHabilitados->count() > 6)
                    <button
                        type="button"
                        @click="verTodos = !verTodos"
                        class="text-xs font-semibold text-blue-600 hover:text-blue-700"
                    >
                        <span x-text="verTodos ? 'Ver menos' : 'Ver todos ({{ $planosHabilitados->count() }})'"></span>
                    </button>
                @endif
            </div>

            @if ($planosHabilitados->isNotEmpty())
                <div
                    class="mt-2 flex flex-wrap gap-1.5"
                    @if ($planosHabilitados->count() > 6) :class="verTodos ? '' : 'max-h-[4.5rem] overflow-hidden'" @endif
                >
                    @foreach ($planosHabilitados as $plano)
                        <a
                            href="{{ route('planos.show', $plano) }}"
                            class="inline-flex max-w-[14rem] items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-[11px] font-medium text-gray-700 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700"
                            title="{{ $plano->nome }}"
                        >
                            <span class="truncate">{{ $plano->nome }}</span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="mt-2 text-xs text-gray-400">Nenhum plano selecionado</p>
            @endif
        </div>
    @endif

    <div class="mt-6 flex flex-wrap gap-2 border-t border-dashed border-gray-200 pt-5">
        <a href="{{ route('usuarios.edit', $usuario) }}" class="rounded-lg bg-indigo-900 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-800">
            <i class="fa-solid fa-pen mr-1"></i> Editar
        </a>
        @if ($usuario->tipo === 'admin' || in_array($usuario->tipo, ['master', 'marketplace', 'revenda'], true))
            <form method="POST" action="{{ route('usuarios.resetar-senha', $usuario) }}" onsubmit="return confirm('Resetar a senha de login{{ $usuario->tipo !== 'admin' ? ' comercial' : '' }} para 123456? No próximo acesso será obrigatório criar uma nova senha.')">
                @csrf
                <button type="submit" class="rounded-lg border border-orange-300 bg-orange-50 px-4 py-2 text-sm font-semibold text-orange-700 shadow-sm hover:bg-orange-100">
                    <i class="fa-solid fa-rotate-left mr-1"></i> Resetar senha{{ $usuario->tipo !== 'admin' ? ' comercial' : '' }}
                </button>
            </form>
        @endif
        @if ($usuario->tipo !== 'admin')
            <a href="{{ route('usuarios.subusuarios.create', $usuario) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                <i class="fa-solid fa-user-plus mr-1"></i> Cadastrar Usuário
            </a>
            @foreach ($proximosNiveis as $nivel)
                <a href="{{ route('usuarios.create', ['tipo' => $nivel, 'pai_id' => $usuario->id]) }}" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    <i class="fa-solid fa-user-plus mr-1"></i> Cadastrar {{ $tipoLabel[$nivel] }}
                </a>
            @endforeach
            @if ($whitelabel)
                @include('admin.usuarios.partials.whitelabel-drawer')
            @endif
        @endif
    </div>

    @if (session('status'))
        <div class="mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
            @if (session('ssl_comando'))
                <p class="mt-2 font-mono text-xs text-emerald-900">Próximo passo no servidor:</p>
                <code class="mt-1 block rounded bg-white/80 px-2 py-1.5 text-xs text-gray-800">{{ session('ssl_comando') }}</code>
            @endif
        </div>
    @endif
    @if ($errors->has('ssl'))
        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 whitespace-pre-wrap">{{ $errors->first('ssl') }}</div>
    @endif
</section>

@if (in_array($usuario->tipo, ['master', 'marketplace']))
<section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    @php
        $tituloFilhos = match($usuario->tipo) {
            'master'      => 'Marketplaces',
            'marketplace' => 'Revendas',
            default       => 'Usuários cadastrados abaixo',
        };
        $subtituloFilhos = match($usuario->tipo) {
            'master'      => 'Marketplaces vinculados a este master.',
            'marketplace' => 'Revendas vinculadas a este marketplace.',
            default       => 'Cadastros comerciais vinculados a este ' . strtolower($tipoLabel[$usuario->tipo]) . '.',
        };
        $colunaFilho = match($usuario->tipo) {
            'master'      => 'Marketplace',
            'marketplace' => 'Revenda',
            default       => 'Nível',
        };
    @endphp
    <div class="border-b border-gray-100 px-5 py-4">
        <h3 class="text-sm font-bold text-gray-800">{{ $tituloFilhos }}</h3>
        <p class="text-xs text-gray-400">{{ $subtituloFilhos }}</p>
    </div>

    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="px-5 py-3">Código</th>
                <th class="px-5 py-3">Nome</th>
                <th class="px-5 py-3">{{ $colunaFilho }}</th>
                <th class="px-5 py-3">E-mail</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3 text-right">Ações</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($filhos as $filho)
                @php
                    $subordinado = $filho->usuario;
                @endphp
                <tr class="border-t border-gray-50 hover:bg-gray-50">
                    <td class="px-5 py-4 font-semibold text-gray-800">#{{ str_pad($subordinado->id, 4, '0', STR_PAD_LEFT) }}</td>
                    <td class="px-5 py-4 font-semibold text-gray-800">{{ $subordinado->nomeExibicao() }}</td>
                    <td class="px-5 py-4 text-gray-600">{{ $tipoLabel[$subordinado->tipo] ?? ucfirst($subordinado->tipo) }}</td>
                    <td class="px-5 py-4 text-gray-600">{{ $subordinado->email }}</td>
                    <td class="px-5 py-4 text-gray-600">{{ $subordinado->ativo ? 'Ativo' : 'Inativo' }}</td>
                    <td class="px-5 py-4 text-right">
                        <a href="{{ route('usuarios.show', $subordinado) }}" class="font-semibold text-blue-600 hover:text-blue-700">Ver detalhes</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-5 py-8 text-center text-sm text-gray-400">Nenhum usuário cadastrado abaixo dele ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</section>
@endif

@if (in_array($usuario->tipo, ['master', 'marketplace', 'revenda']))
<section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="border-b border-gray-100 px-5 py-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-sm font-bold text-gray-800">Usuários operacionais</h3>
                <p class="text-xs text-gray-400">Acessos internos vinculados a este {{ strtolower($tipoLabel[$usuario->tipo]) }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @unless ($temSubUsuarioPrincipal ?? false)
                    <form method="POST" action="{{ route('usuarios.subusuarios.garantir-principal', $usuario) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 shadow-sm hover:bg-amber-100">
                            <i class="fa-solid fa-link mr-1"></i> Criar acesso com e-mail comercial
                        </button>
                    </form>
                @endunless
                <a href="{{ route('usuarios.subusuarios.create', $usuario) }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700">
                    + Cadastrar Usuário
                </a>
            </div>
        </div>
    </div>

    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <th class="px-5 py-3">Código</th>
                <th class="px-5 py-3">Nome</th>
                <th class="px-5 py-3">E-mail</th>
                <th class="px-5 py-3">Perfil</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3 text-right">Ações</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($usuario->subUsuarios as $subUsuario)
                <tr class="border-t border-gray-50 hover:bg-gray-50">
                    <td class="px-5 py-4 font-semibold text-gray-800">#{{ str_pad($subUsuario->id, 4, '0', STR_PAD_LEFT) }}</td>
                    <td class="px-5 py-4 font-semibold text-gray-800">{{ $subUsuario->nome }}</td>
                    <td class="px-5 py-4 text-gray-600">
                        {{ $subUsuario->email }}
                        @if (strtolower($subUsuario->email) === strtolower($usuario->email))
                            <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-800">mesmo e-mail comercial</span>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-gray-600">{{ $subUsuario->perfil?->nome ?: '-' }}</td>
                    <td class="px-5 py-4 text-gray-600">{{ $subUsuario->ativo ? 'Ativo' : 'Inativo' }}</td>
                    <td class="px-5 py-4 text-right">
                        <div class="flex flex-wrap justify-end gap-2">
                            @if (auth()->user()?->tipo === 'admin' && in_array($usuario->tipo, ['marketplace', 'revenda'], true))
                                <form method="POST" action="{{ route('usuarios.subusuarios.acessar', [$usuario, $subUsuario]) }}" target="_blank">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-700 hover:bg-violet-100">
                                        <i class="fa-solid fa-right-to-bracket mr-1"></i> Acessar
                                    </button>
                                </form>
                            @elseif ($urlAcessoOperacional ?? null)
                                <a href="{{ $urlAcessoOperacional }}" target="_blank" rel="noopener" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-1.5 text-xs font-bold text-violet-700 hover:bg-violet-100">
                                    <i class="fa-solid fa-right-to-bracket mr-1"></i> Abrir painel
                                </a>
                            @endif
                            <form method="POST" action="{{ route('usuarios.subusuarios.resetar-senha', [$usuario, $subUsuario]) }}" onsubmit="return confirm('Resetar a senha de {{ $subUsuario->nome }} para 123456? No próximo acesso será obrigatório criar uma nova senha.')">
                                @csrf
                                <button type="submit" class="rounded-lg border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-bold text-orange-700 hover:bg-orange-100">
                                    <i class="fa-solid fa-rotate-left mr-1"></i> Resetar senha operacional
                                </button>
                            </form>
                            <button type="button"
                                    data-modal-open="excluir-subusuario"
                                    data-action="{{ route('usuarios.subusuarios.destroy', [$usuario, $subUsuario]) }}"
                                    data-subusuario-nome="{{ $subUsuario->nome }}"
                                    class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700 hover:bg-red-100">
                                <i class="fa-solid fa-trash mr-1"></i> Excluir
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">
                        <p>Nenhum usuário operacional cadastrado ainda.</p>
                        @unless ($temSubUsuarioPrincipal ?? false)
                            <form method="POST" action="{{ route('usuarios.subusuarios.garantir-principal', $usuario) }}" class="mt-4">
                                @csrf
                                <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 shadow-sm hover:bg-amber-100">
                                    <i class="fa-solid fa-link mr-1"></i> Criar acesso com e-mail comercial ({{ $usuario->email }})
                                </button>
                            </form>
                        @endunless
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</section>

<div data-modal="excluir-subusuario" class="modal-overlay fixed inset-0 z-[100] items-center justify-center bg-black/40 px-4">
    <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Excluir usuário operacional</h3>
                <p class="mt-1 text-sm text-gray-500">Esta ação não pode ser desfeita.</p>
            </div>
            <button type="button" data-modal-close="excluir-subusuario" class="text-2xl leading-none text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <form id="form-excluir-subusuario" method="POST" action="" class="space-y-4">
            @csrf
            <p class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
                Usuário: <strong id="excluir-subusuario-nome">—</strong>
            </p>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700">Sua senha de administrador</span>
                <div class="relative">
                    <input type="password" name="senha_admin" id="senha-admin-excluir-subusuario" autocomplete="current-password" required
                           class="w-full rounded-lg border border-gray-300 py-2 pl-3 pr-10 text-sm @error('senha_admin') border-red-500 @enderror">
                    <button type="button" id="toggle-senha-admin-excluir-subusuario"
                            class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="fa-regular fa-eye" id="toggle-senha-admin-excluir-subusuario-icon"></i>
                    </button>
                </div>
                @error('senha_admin')
                    <p class="text-xs text-red-600">{{ $message }}</p>
                @enderror
            </label>

            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="hidden" name="confirmacao" value="0">
                <input type="checkbox" name="confirmacao" value="1" required class="mt-0.5 h-4 w-4 rounded accent-red-600">
                <span>Confirmo que desejo excluir este usuário operacional.</span>
            </label>
            @error('confirmacao')
                <p class="text-xs text-red-600">{{ $message }}</p>
            @enderror

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" data-modal-close="excluir-subusuario" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">
                    Cancelar
                </button>
                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    Excluir usuário
                </button>
            </div>
        </form>
    </div>
</div>
@endif
@endsection

@section('scripts')
@if (in_array($usuario->tipo, ['master', 'marketplace', 'revenda']))
<script>
    (() => {
        const modal = document.querySelector('[data-modal="excluir-subusuario"]');
        const form = document.getElementById('form-excluir-subusuario');
        const nomeEl = document.getElementById('excluir-subusuario-nome');

        const abrirModal = (action, nome) => {
            if (!modal || !form) return;
            form.action = action;
            if (nomeEl) nomeEl.textContent = nome || '—';
            modal.classList.add('is-open');
        };

        document.querySelectorAll('[data-modal-open="excluir-subusuario"]').forEach((button) => {
            button.addEventListener('click', () => {
                abrirModal(button.dataset.action, button.dataset.subusuarioNome);
            });
        });

        document.querySelectorAll('[data-modal-close="excluir-subusuario"]').forEach((button) => {
            button.addEventListener('click', () => modal?.classList.remove('is-open'));
        });

        modal?.addEventListener('click', (event) => {
            if (event.target === modal) modal.classList.remove('is-open');
        });

        document.getElementById('toggle-senha-admin-excluir-subusuario')?.addEventListener('click', () => {
            const input = document.getElementById('senha-admin-excluir-subusuario');
            const icon = document.getElementById('toggle-senha-admin-excluir-subusuario-icon');
            if (!input || !icon) return;
            const oculta = input.type === 'password';
            input.type = oculta ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !oculta);
            icon.classList.toggle('fa-eye-slash', oculta);
        });

        @if (session('abrir_modal_excluir_subusuario'))
            @php $subReabrir = $usuario->subUsuarios->firstWhere('id', (int) session('abrir_modal_excluir_subusuario')); @endphp
            @if ($subReabrir)
                abrirModal(@json(route('usuarios.subusuarios.destroy', [$usuario, $subReabrir])), @json($subReabrir->nome));
            @endif
        @elseif ($errors->has('senha_admin') || $errors->has('confirmacao'))
            modal?.classList.add('is-open');
        @endif
    })();
</script>
@endif
@endsection
