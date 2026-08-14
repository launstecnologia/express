@php $mobileMenu = $mobileMenu ?? false; @endphp
<div class="flex h-full min-h-0 flex-col">
    <div class="flex items-center justify-between px-4 pb-4 pt-4">
        <a href="{{ route('dashboard') }}" class="block" @if($mobileMenu) @click="sidebarOpen = false" @endif>
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="h-12 w-auto object-contain sm:h-14 lg:h-16">
            @else
                <span class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ $appName }}</span>
            @endif
        </a>
        @if ($mobileMenu)
            <button type="button" @click="sidebarOpen = false" class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800" aria-label="Fechar menu">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        @endif
    </div>

    <nav class="flex-1 overflow-y-auto pb-4" @if($mobileMenu ?? false) @click="if ($event.target.closest('a')) sidebarOpen = false" @endif>
        <p class="mb-1 mt-6 px-4 text-xs font-semibold uppercase tracking-widest text-gray-400">Geral</p>
        <a href="{{ route('dashboard') }}" class="{{ $navClass('dashboard') }}">
            <i class="fa-solid fa-gauge-high w-5 text-center text-[15px]"></i>
            <span>Dashboard</span>
        </a>
        <a href="{{ route('relatorios.faturamento') }}" class="{{ $navClass('relatorios.*') }}">
            <i class="fa-solid fa-chart-line w-5 text-center text-[15px]"></i>
            <span>Faturamento</span>
        </a>
        <a href="{{ route('comissoes.index') }}" class="{{ $navClass('comissoes.index') }}">
            <i class="fa-solid fa-hand-holding-dollar w-5 text-center text-[15px]"></i>
            <span>Comissão</span>
        </a>
        @if (\App\Support\UsuarioComercial::ehAdmin() || $ehMaster)
            <a href="{{ route('comissoes.configuracoes.index') }}" class="{{ $navClass('comissoes.configuracoes.*') }}">
                <i class="fa-solid fa-sliders w-5 text-center text-[15px]"></i>
                <span>Config. Comissão</span>
            </a>
        @endif
        <a href="{{ route($ehAdmin ? 'admin.chamados.index' : 'chamados.index') }}" class="{{ request()->routeIs('admin.chamados.*') || request()->routeIs('chamados.*') ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
            <i class="fa-solid fa-ticket w-5 text-center text-[15px]"></i>
            <span>Chamados</span>
            @if (($chamadosAbertos ?? 0) > 0)
                <span class="ml-auto rounded-full bg-red-500 px-1.5 py-0.5 text-xs font-bold text-white">{{ $chamadosAbertos > 99 ? '99+' : $chamadosAbertos }}</span>
            @endif
        </a>

        <p class="mb-1 mt-6 px-4 text-xs font-semibold uppercase tracking-widest text-gray-400">Operações</p>
        <a href="{{ route('estabelecimentos.index') }}" class="{{ $navClass('estabelecimentos.*') }}">
            <i class="fa-solid fa-store w-5 text-center text-[15px]"></i>
            <span>Estabelecimentos</span>
        </a>
        @if ($ehAdmin || \App\Support\UsuarioComercial::podeCadastrarEstabelecimento())
            <a href="{{ route('fv-documento.index') }}" class="{{ $navClass('fv-documento.*') }}">
                <i class="fa-solid fa-magnifying-glass w-5 text-center text-[15px]"></i>
                <span>Pesquisar Doc.</span>
            </a>
        @endif
        @if (\App\Support\UsuarioComercial::ehMarketplaceOuRevenda())
            <a href="{{ route('comissoes.meu-plano') }}" class="{{ $navClass('comissoes.meu-plano') }}">
                <i class="fa-solid fa-credit-card w-5 text-center text-[15px]"></i>
                <span>Planos e Taxas</span>
            </a>
        @elseif (\App\Support\UsuarioComercial::podeGerirPlanos() || $ehMaster)
            <a href="{{ route('planos.index') }}" class="{{ $navClass('planos.*') }}">
                <i class="fa-solid fa-credit-card w-5 text-center text-[15px]"></i>
                <span>Planos e Taxas</span>
            </a>
        @endif

        <p class="mb-1 mt-6 px-4 text-xs font-semibold uppercase tracking-widest text-gray-400">Administração</p>
        @if ($ehMarketplace && $principal)
            @php
                $perfilMarketplaceAtivo = request()->routeIs('usuarios.show') && (int) request()->route('usuario')?->id === (int) $principal->id;
            @endphp
            <a href="{{ route('usuarios.show', $principal) }}" class="{{ $perfilMarketplaceAtivo ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                <i class="fa-solid fa-users w-5 text-center text-[15px]"></i>
                <span>Usuários operacionais</span>
            </a>
            <a href="{{ route('usuarios.index', ['tipo' => 'revenda']) }}" class="{{ $usuarioMenuTipo === 'revenda' ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                <i class="fa-solid fa-handshake w-5 text-center text-[15px]"></i>
                <span>Revendas</span>
            </a>
        @else
            @if ($ehAdmin)
                <a href="{{ route('usuarios.index') }}" class="{{ $usuarioBaseActive ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                    <i class="fa-solid fa-users w-5 text-center text-[15px]"></i>
                    <span>Usuários</span>
                </a>
                <a href="{{ route('usuarios.index', ['tipo' => 'master']) }}" class="{{ $usuarioMenuTipo === 'master' ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                    <i class="fa-solid fa-crown w-5 text-center text-[15px]"></i>
                    <span>Masters</span>
                </a>
                <a href="{{ route('usuarios.index', ['tipo' => 'marketplace']) }}" class="{{ $usuarioMenuTipo === 'marketplace' ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                    <i class="fa-solid fa-network-wired w-5 text-center text-[15px]"></i>
                    <span>Marketplaces</span>
                </a>
            @endif
            @if ($ehAdmin || $ehMaster)
                @unless ($ehAdmin)
                    <a href="{{ route('usuarios.index') }}" class="{{ $usuarioBaseActive ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                        <i class="fa-solid fa-users w-5 text-center text-[15px]"></i>
                        <span>Usuários</span>
                    </a>
                @endunless
                @if ($ehMaster)
                    <a href="{{ route('usuarios.index', ['tipo' => 'marketplace']) }}" class="{{ $usuarioMenuTipo === 'marketplace' ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                        <i class="fa-solid fa-network-wired w-5 text-center text-[15px]"></i>
                        <span>Marketplaces</span>
                    </a>
                @endif
                <a href="{{ route('usuarios.index', ['tipo' => 'revenda']) }}" class="{{ $usuarioMenuTipo === 'revenda' ? 'flex items-center gap-3 px-4 py-2.5 text-sm font-semibold text-blue-600 bg-blue-50 rounded-lg mx-2 dark:bg-blue-950/50 dark:text-blue-400' : 'flex items-center gap-3 px-4 py-2.5 text-sm text-gray-600 rounded-lg mx-2 hover:bg-gray-100 hover:text-gray-900 transition-colors dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100' }}">
                    <i class="fa-solid fa-handshake w-5 text-center text-[15px]"></i>
                    <span>Revendas</span>
                </a>
            @endif
            @if ($ehAdmin)
                <a href="{{ route('segmentos.index') }}" class="{{ $navClass('segmentos.*') }}">
                    <i class="fa-solid fa-tags w-5 text-center text-[15px]"></i>
                    <span>Segmentos</span>
                </a>
                <a href="{{ route('admin.kyc.index') }}" class="{{ $navClass('admin.kyc.*') }}">
                    <i class="fa-solid fa-shield-halved w-5 text-center text-[15px]"></i>
                    <span>KYC</span>
                </a>
                <a href="{{ route('admin.email-templates.index') }}" class="{{ $navClass('admin.email-templates.*') }}">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center text-[15px]"></i>
                    <span>Templates E-mail</span>
                </a>
                <a href="{{ route('admin.conciliacoes.index') }}" class="{{ $navClass('admin.conciliacoes.*') }}">
                    <i class="fa-solid fa-scale-balanced w-5 text-center text-[15px]"></i>
                    <span>Conciliação</span>
                </a>
                <a href="{{ route('admin.relatorios.estabelecimentos-transacoes') }}" class="{{ $navClass('admin.relatorios.*') }}">
                    <i class="fa-solid fa-file-excel w-5 text-center text-[15px]"></i>
                    <span>Transações MKT</span>
                </a>
                <a href="{{ route('admin.edi-pipefy.index') }}" class="{{ $navClass('admin.edi-pipefy.*') }}">
                    <i class="fa-solid fa-right-left w-5 text-center text-[15px]"></i>
                    <span>EDI Pipefy</span>
                </a>
                <a href="{{ route('admin.configuracoes.edit') }}" class="{{ $navClass('admin.configuracoes.*') }}">
                    <i class="fa-solid fa-gear w-5 text-center text-[15px]"></i>
                    <span>Configurações</span>
                </a>
            @endif
        @endif
    </nav>
    <div class="mt-auto flex shrink-0 items-center gap-3 border-t border-gray-200 px-4 py-3 dark:border-gray-800">
        <a href="{{ route('perfil.edit') }}" class="shrink-0" @if($mobileMenu ?? false) @click="sidebarOpen = false" @endif>
            @if ($avatarUrl ?? null)
                <img src="{{ $avatarUrl }}" alt="" class="h-8 w-8 rounded-full border border-gray-200 object-cover dark:border-gray-700">
            @else
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white">
                    {{ $userIniciais ?? mb_substr($userName, 0, 1) }}
                </div>
            @endif
        </a>
        <div class="min-w-0">
            <a href="{{ route('perfil.edit') }}" class="truncate text-sm font-semibold text-gray-800 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400" @if($mobileMenu ?? false) @click="sidebarOpen = false" @endif>{{ $userName }}</a>
            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $userRole }}</p>
        </div>
        @auth
            <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                @csrf
                <button class="text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-300" title="Sair"><i class="fa-solid fa-right-from-bracket"></i></button>
            </form>
        @endauth
    </div>
</div>
