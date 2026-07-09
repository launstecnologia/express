@extends('layouts.app')

@section('title', 'Minha marca')

@section('content')
@php
    $baseDomain = config('tenant.base_domain');
@endphp
<div
    x-data="{ aba: 'marca' }"
    class="mx-auto max-w-4xl space-y-6"
>
    @if (session('status'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Whitelabel do marketplace</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Personalize logo, cores e domínio de acesso para revendas e usuários do seu marketplace.
            </p>
        </div>

        <div class="flex flex-wrap gap-1 border-b border-gray-100 px-4 dark:border-gray-700">
            @foreach ([
                'marca' => ['Marca', 'fa-palette'],
                'dominio' => ['Domínio', 'fa-globe'],
            ] as $key => [$label, $icon])
                <button
                    type="button"
                    @click="aba = '{{ $key }}'"
                    :class="aba === '{{ $key }}' ? 'border-blue-600 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                    class="border-b-2 px-4 py-3 text-sm font-semibold transition"
                >
                    <i class="fa-solid {{ $icon }} mr-1.5"></i>{{ $label }}
                </button>
            @endforeach
        </div>

        <form method="POST" action="{{ route('marketplace.branding.update') }}" enctype="multipart/form-data" class="space-y-6 px-6 py-6">
            @csrf
            @method('PUT')

            <div x-show="aba === 'marca'" x-cloak class="space-y-6">
                <label class="flex items-center gap-3 text-sm font-medium text-gray-700 dark:text-gray-300">
                    <input type="hidden" name="whitelabel_ativo" value="0">
                    <input type="checkbox" name="whitelabel_ativo" value="1" class="h-4 w-4 rounded accent-blue-600" @checked(old('whitelabel_ativo', $branding->whitelabel_ativo))>
                    Whitelabel ativo (exibir marca personalizada no subdomínio)
                </label>

                <div class="grid gap-4 md:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Nome exibido</span>
                        <input type="text" name="app_name" value="{{ old('app_name', $branding->app_name) }}" maxlength="120" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100" placeholder="{{ $marketplace->nomeExibicao() }}">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Cor primária</span>
                        <div class="flex gap-2">
                            <input type="color" value="{{ old('primary_color', $branding->primary_color) }}" class="h-10 w-14 cursor-pointer rounded border border-gray-300 dark:border-gray-600" oninput="this.nextElementSibling.value = this.value">
                            <input type="text" name="primary_color" value="{{ old('primary_color', $branding->primary_color) }}" pattern="^#[0-9A-Fa-f]{6}$" required class="flex-1 rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        </div>
                    </label>
                </div>

                <div class="grid gap-6 md:grid-cols-3">
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
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300">
                        @include('partials.branding-logo-acoes', [
                            'name' => 'logo_acao',
                            'oculta' => (bool) $branding->logo_oculta,
                            'temArquivo' => filled($branding->logo_path),
                        ])
                    </div>

                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Logo branca</p>
                        @include('partials.branding-image-hint', ['variant' => 'logo_white'])
                        <div class="mb-3 flex h-20 items-center justify-center rounded-lg" style="background-color: {{ $branding->primary_color }}">
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
                                <input type="checkbox" name="remover_favicon" value="1" class="rounded"> Remover
                            </label>
                        @endif
                    </div>
                </div>
            </div>

            <div x-show="aba === 'dominio'" x-cloak class="space-y-6">
                @include('partials.branding-urls-acesso', ['urls' => $urlsAcesso, 'slug' => $branding->slug])

                <label class="block space-y-1">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Slug do subdomínio</span>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" name="slug" value="{{ old('slug', $branding->slug) }}" required pattern="^[a-z0-9]([a-z0-9-]*[a-z0-9])?$" class="min-w-0 flex-1 rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        <span class="text-xs text-gray-500">
                            @if ($urlsAcesso['ehLocal'] ?? false)
                                <span class="text-emerald-700">localhost · prod: .{{ $baseDomain }}</span>
                            @else
                                .{{ $baseDomain }}
                            @endif
                        </span>
                    </div>
                    <p class="text-xs text-gray-500">Somente letras minúsculas, números e hífens.</p>
                </label>

                <label class="block space-y-1">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Domínio personalizado (opcional)</span>
                    <input type="text" name="custom_domain" value="{{ old('custom_domain', $branding->custom_domain) }}" placeholder="painel.suamarca.com.br" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @if ($branding->custom_domain)
                        <p class="mt-2 text-xs {{ $branding->custom_domain_verified_at ? 'text-emerald-600' : 'text-amber-600' }}">
                            @if ($branding->custom_domain_verified_at)
                                <i class="fa-solid fa-circle-check mr-1"></i> Domínio verificado em {{ $branding->custom_domain_verified_at->format('d/m/Y H:i') }}
                            @else
                                <i class="fa-solid fa-clock mr-1"></i> Aguardando verificação DNS — aponte o domínio para este servidor e clique em verificar.
                            @endif
                        </p>
                    @endif
                </label>
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
                <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">
                    Salvar whitelabel
                </button>
            </div>
        </form>

        @if ($branding->custom_domain && ! $branding->custom_domain_verified_at)
            <form method="POST" action="{{ route('marketplace.branding.verificar-dominio') }}" class="border-t border-gray-100 px-6 py-4 dark:border-gray-700">
                @csrf
                <button type="submit" class="rounded-lg border border-emerald-600 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/30">
                    Marcar domínio como verificado
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
