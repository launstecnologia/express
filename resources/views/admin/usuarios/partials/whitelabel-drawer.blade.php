@php
    $branding = $whitelabel['branding'];
    $urlsAcesso = $whitelabel['urlsAcesso'];
    $logoUrl = $whitelabel['logoUrl'];
    $logoWhiteUrl = $whitelabel['logoWhiteUrl'];
    $faviconUrl = $whitelabel['faviconUrl'];
    $baseDomain = config('tenant.base_domain');
    $abrirDrawer = $errors->any();
@endphp

<div x-data="{ whitelabelAberto: {{ $abrirDrawer ? 'true' : 'false' }}, aba: 'marca' }">
    <button
        type="button"
        @click="whitelabelAberto = true"
        class="rounded-lg border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-800 shadow-sm hover:bg-violet-100 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-200 dark:hover:bg-violet-900/50"
    >
        <i class="fa-solid fa-palette mr-1"></i> Whitelabel
    </button>

    @if ($branding->slug)
        <span class="ml-2 text-xs text-gray-500">
            @if ($urlsAcesso['ehLocal'] ?? false)
                <a href="{{ $urlsAcesso['local'] }}" target="_blank" rel="noopener" class="font-mono text-violet-700 underline dark:text-violet-300">{{ parse_url(config('app.url'), PHP_URL_HOST) }}?{{ config('tenant.local_query', 'tenant') }}={{ $branding->slug }}</a>
            @else
                <code class="rounded bg-gray-100 px-1.5 py-0.5 dark:bg-gray-800">{{ $branding->slug }}.{{ $baseDomain }}</code>
            @endif
            @if ($branding->whitelabel_ativo)
                <span class="ml-1 text-emerald-600">· ativo</span>
            @endif
        </span>
    @endif

    <div x-show="whitelabelAberto" x-cloak class="fixed inset-0 z-[60]" @keydown.escape.window="whitelabelAberto = false">
        <div class="absolute inset-0 bg-gray-900/50" @click="whitelabelAberto = false"></div>
        <aside
            class="absolute right-0 top-0 flex h-full w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-gray-900"
            x-show="whitelabelAberto"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @click.stop
        >
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Whitelabel</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $usuario->nomeExibicao() }}</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" @click="whitelabelAberto = false">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            @if ($errors->any())
                <div class="mx-5 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">
                    <p class="font-semibold">Corrija os campos abaixo:</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex gap-1 border-b border-gray-100 px-4 dark:border-gray-700">
                @foreach ([
                    'marca' => ['Marca', 'fa-palette'],
                    'dominio' => ['Domínio', 'fa-globe'],
                ] as $key => [$label, $icon])
                    <button
                        type="button"
                        @click="aba = '{{ $key }}'"
                        :class="aba === '{{ $key }}' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                        class="border-b-2 px-3 py-2.5 text-xs font-semibold transition"
                    >
                        <i class="fa-solid {{ $icon }} mr-1"></i>{{ $label }}
                    </button>
                @endforeach
            </div>

            <form
                method="POST"
                action="{{ route('usuarios.whitelabel.update', $usuario) }}"
                enctype="multipart/form-data"
                class="flex min-h-0 flex-1 flex-col"
            >
                @csrf
                @method('PUT')

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <div x-show="aba === 'marca'" x-cloak class="space-y-5">
                        <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                            <input type="hidden" name="whitelabel_ativo" value="0">
                            <input type="checkbox" name="whitelabel_ativo" value="1" class="h-4 w-4 rounded accent-blue-600" @checked(old('whitelabel_ativo', $branding->whitelabel_ativo))>
                            Whitelabel ativo
                        </label>

                        <label class="block space-y-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nome exibido</span>
                            <input type="text" name="app_name" value="{{ old('app_name', $branding->app_name) }}" maxlength="120" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        </label>

                        <label class="block space-y-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cor primária</span>
                            <div class="flex gap-2">
                                <input type="color" value="{{ old('primary_color', $branding->primary_color) }}" class="h-10 w-14 cursor-pointer rounded border border-gray-300 dark:border-gray-600" oninput="this.nextElementSibling.value = this.value">
                                <input type="text" name="primary_color" value="{{ old('primary_color', $branding->primary_color) }}" pattern="^#[0-9A-Fa-f]{6}$" required class="flex-1 rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            </div>
                        </label>

                        <div class="space-y-4">
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Logo padrão</p>
                                @include('partials.branding-image-hint', ['variant' => 'logo'])
                                <div class="mb-3 flex h-20 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800">
                                    @if ($branding->logo_oculta)
                                        <p class="text-xs italic text-gray-400">Sem logo</p>
                                    @elseif ($logoUrl)
                                        <img src="{{ $logoUrl }}" alt="Logo" class="max-h-16 max-w-full object-contain">
                                    @else
                                        <p class="text-xs text-gray-400">Logo padrão da plataforma</p>
                                    @endif
                                </div>
                                <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700">
                                @include('partials.branding-logo-acoes', [
                                    'name' => 'logo_acao',
                                    'oculta' => (bool) $branding->logo_oculta,
                                    'temArquivo' => filled($branding->logo_path),
                                ])
                            </div>

                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Logo branca</p>
                                @include('partials.branding-image-hint', ['variant' => 'logo_white'])
                                <div class="mb-3 flex h-20 items-center justify-center rounded-lg" style="background-color: {{ old('primary_color', $branding->primary_color) }}">
                                    @if ($branding->logo_white_oculta)
                                        <p class="text-xs italic text-white/70">Sem logo</p>
                                    @elseif ($logoWhiteUrl)
                                        <img src="{{ $logoWhiteUrl }}" alt="Logo branca" class="max-h-16 max-w-full object-contain brightness-0 invert">
                                    @else
                                        <p class="text-xs text-white/70">Logo branca padrão</p>
                                    @endif
                                </div>
                                <input type="file" name="logo_white" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700">
                                @include('partials.branding-logo-acoes', [
                                    'name' => 'logo_white_acao',
                                    'oculta' => (bool) $branding->logo_white_oculta,
                                    'temArquivo' => filled($branding->logo_white_path),
                                ])
                            </div>

                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Ícone / Favicon</p>
                                @include('partials.branding-image-hint', ['variant' => 'favicon'])
                                <div class="mb-3 flex h-20 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800">
                                    <img src="{{ $faviconUrl }}" alt="Favicon" class="h-12 w-12 object-contain">
                                </div>
                                <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/x-icon" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700">
                                @if ($branding->favicon_path)
                                    <label class="mt-2 flex items-center gap-2 text-xs text-red-600">
                                        <input type="checkbox" name="remover_favicon" value="1" class="rounded"> Remover ícone
                                    </label>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div x-show="aba === 'dominio'" x-cloak class="space-y-4">
                        @include('partials.branding-urls-acesso', ['urls' => $urlsAcesso, 'slug' => $branding->slug])

                        <label class="block space-y-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Slug do subdomínio</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <input type="text" name="slug" value="{{ old('slug', $branding->slug) }}" required pattern="^[a-z0-9]([a-z0-9-]*[a-z0-9])?$" class="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                <span class="shrink-0 text-xs text-gray-500">
                                    @if ($urlsAcesso['ehLocal'] ?? false)
                                        <span class="text-emerald-700 dark:text-emerald-400">→ {{ config('tenant.local_query', 'tenant') }}=slug no localhost</span>
                                        <span class="mx-1 text-gray-300">|</span>
                                        prod: .{{ $baseDomain }}
                                    @else
                                        .{{ $baseDomain }}
                                    @endif
                                </span>
                            </div>
                        </label>

                        <label class="block space-y-1">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Domínio personalizado</span>
                            <input type="text" name="custom_domain" value="{{ old('custom_domain', $branding->custom_domain) }}" placeholder="painel.suamarca.com.br" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            @if ($branding->custom_domain)
                                <p class="mt-2 text-xs {{ $branding->custom_domain_verified_at ? 'text-emerald-600' : 'text-amber-600' }}">
                                    @if ($branding->custom_domain_verified_at)
                                        Verificado em {{ $branding->custom_domain_verified_at->format('d/m/Y H:i') }}
                                    @else
                                        Aguardando verificação DNS
                                    @endif
                                </p>
                                @if ($branding->ssl_provisioned_at)
                                    <p class="mt-1 text-xs text-emerald-600">
                                        <i class="fa-solid fa-lock mr-1"></i> SSL ativo desde {{ $branding->ssl_provisioned_at->format('d/m/Y H:i') }}
                                    </p>
                                @elseif ($branding->ssl_last_error)
                                    <p class="mt-1 text-xs text-red-600">{{ Str::limit($branding->ssl_last_error, 120) }}</p>
                                @endif
                                <form method="POST" action="{{ route('usuarios.whitelabel.provisionar-ssl', $usuario) }}" class="mt-3"
                                      onsubmit="return confirm('Configurar SSL para {{ $branding->custom_domain }}?\n\nO DNS deve apontar para o IP do servidor (TENANT_SERVER_IP).');">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-800 hover:bg-indigo-100 dark:border-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-200">
                                        <i class="fa-solid fa-lock"></i> Configurar SSL automaticamente
                                    </button>
                                </form>
                                <p class="mt-1 text-[11px] text-gray-400">Valida o DNS, emite certificado Let's Encrypt e ativa HTTPS automaticamente.</p>
                            @endif
                        </label>

                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="verificar_dominio" value="1" class="rounded" @checked(old('verificar_dominio'))>
                            Marcar domínio como verificado
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 border-t border-gray-100 px-5 py-4 dark:border-gray-700">
                    <button type="button" @click="whitelabelAberto = false" class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                        Cancelar
                    </button>
                    <button type="submit" class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                        Salvar whitelabel
                    </button>
                </div>
            </form>
        </aside>
    </div>
</div>
