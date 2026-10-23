@extends('layouts.app')

@section('title', 'Configurações da plataforma')

@section('content')
<div
    x-data="{ aba: '{{ in_array(request('aba'), ['marca', 'seo', 'empresa', 'email', 'kyc', 'pagbank', 'edi'], true) ? request('aba') : 'marca' }}' }"
    class="mx-auto max-w-4xl space-y-6"
>
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="border-b border-gray-100 px-6 py-4 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Configurações da plataforma</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Marca, SEO e dados da empresa usados no sistema e em relatórios PDF.
            </p>
        </div>

        <div class="flex flex-wrap gap-1 border-b border-gray-100 px-4 dark:border-gray-700">
            @foreach ([
                'marca' => ['Ícone', 'fa-palette'],
                'seo' => ['SEO', 'fa-magnifying-glass'],
                'empresa' => ['Empresa / PDF', 'fa-building'],
                'email' => ['E-mail', 'fa-envelope'],
                'kyc' => ['KYC / Didit', 'fa-shield-halved'],
                'pagbank' => ['PagBank', 'fa-building-columns'],
                'edi' => ['EDI', 'fa-arrows-rotate'],
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

        <form method="POST" action="{{ route('admin.configuracoes.update') }}" enctype="multipart/form-data" class="space-y-6 px-6 py-6" x-show="aba !== 'edi'" x-cloak>
            @csrf
            @method('PUT')
            <input type="hidden" name="_aba" x-bind:value="aba">

            @if (session('status'))
                <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                    <p class="font-semibold">Não foi possível salvar:</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $erro)
                            <li>{{ $erro }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Marca --}}
            <div x-show="aba === 'marca'" x-cloak class="space-y-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Logos e ícone exibidos no menu, login e aba do navegador.</p>

                <div class="grid gap-6 md:grid-cols-3">
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Logo padrão</p>
                        @include('partials.branding-image-hint', ['variant' => 'logo'])
                        <div class="mb-3 flex h-20 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800">
                            <img src="{{ $logoUrl }}" alt="Logo" class="max-h-16 max-w-full object-contain">
                        </div>
                        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700 dark:text-gray-300">
                        @if ($config->logo_path)
                            <label class="mt-2 flex items-center gap-2 text-xs text-red-600">
                                <input type="checkbox" name="remover_logo" value="1" class="rounded"> Remover
                            </label>
                        @endif
                    </div>

                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Logo branca</p>
                        @include('partials.branding-image-hint', ['variant' => 'logo_white'])
                        <div class="mb-3 flex h-20 items-center justify-center rounded-lg bg-blue-700">
                            <img src="{{ $logoWhiteUrl }}" alt="Logo branca" class="max-h-16 max-w-full object-contain brightness-0 invert">
                        </div>
                        <input type="file" name="logo_white" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700">
                        @if ($config->logo_white_path)
                            <label class="mt-2 flex items-center gap-2 text-xs text-red-600">
                                <input type="checkbox" name="remover_logo_white" value="1" class="rounded"> Remover
                            </label>
                        @endif
                    </div>

                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Ícone / Favicon</p>
                        @include('partials.branding-image-hint', ['variant' => 'favicon'])
                        <div class="mb-3 flex h-20 items-center justify-center rounded-lg bg-gray-50 dark:bg-gray-800">
                            <img src="{{ $faviconUrl }}" alt="Favicon" class="h-12 w-12 object-contain">
                        </div>
                        <input type="file" name="favicon" accept="image/png,image/jpeg,image/webp,image/x-icon" class="block w-full text-xs text-gray-600 file:mr-2 file:rounded file:border-0 file:bg-blue-50 file:px-2 file:py-1 file:text-xs file:font-semibold file:text-blue-700">
                        @if ($config->favicon_path)
                            <label class="mt-2 flex items-center gap-2 text-xs text-red-600">
                                <input type="checkbox" name="remover_favicon" value="1" class="rounded"> Remover
                            </label>
                        @endif
                    </div>
                </div>

                <label class="block max-w-xs space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Cor do tema (navegador)</span>
                    <input type="color" name="theme_color" value="{{ old('theme_color', $config->theme_color ?: '#2563eb') }}" class="h-10 w-full cursor-pointer rounded-lg border border-gray-300 dark:border-gray-600">
                </label>
            </div>

            {{-- SEO --}}
            <div x-show="aba === 'seo'" x-cloak class="space-y-4">
                <label class="block space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Título do site</span>
                    <input type="text" name="app_name" value="{{ old('app_name', $config->app_name) }}" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    <span class="text-xs text-gray-400">Ex.: Express Payments — usado na aba do navegador e meta tags</span>
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Meta description</span>
                    <textarea name="meta_description" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">{{ old('meta_description', $config->meta_description) }}</textarea>
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Palavras-chave (keywords)</span>
                    <input type="text" name="meta_keywords" value="{{ old('meta_keywords', $config->meta_keywords) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                </label>

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Robots</span>
                    <select name="meta_robots" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                        @foreach (['noindex, nofollow', 'index, follow', 'index, nofollow', 'noindex, follow'] as $robots)
                            <option value="{{ $robots }}" @selected(old('meta_robots', $config->meta_robots) === $robots)>{{ $robots }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            {{-- Empresa --}}
            <div x-show="aba === 'empresa'" x-cloak class="space-y-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">Dados para cabeçalho e rodapé de relatórios em PDF.</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Razão social</span>
                        <input type="text" name="razao_social" value="{{ old('razao_social', $config->razao_social) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Nome fantasia</span>
                        <input type="text" name="nome_fantasia" value="{{ old('nome_fantasia', $config->nome_fantasia) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">CNPJ</span>
                        <div class="flex">
                            <input
                                type="text"
                                name="cnpj"
                                value="{{ old('cnpj', $config->cnpj) }}"
                                data-autofill="cnpj"
                                class="w-full rounded-l-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            >
                            <button
                                type="button"
                                data-action="buscar-cnpj"
                                class="flex shrink-0 items-center justify-center rounded-r-lg border border-l-0 border-gray-300 bg-blue-50 px-3 text-blue-600 transition hover:bg-blue-100 dark:border-gray-600 dark:bg-blue-950 dark:text-blue-400 dark:hover:bg-blue-900"
                                title="Buscar dados pelo CNPJ"
                            >
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                        <span data-status="cnpj" class="block min-h-4 text-xs text-gray-400"></span>
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Inscrição estadual</span>
                        <input type="text" name="inscricao_estadual" value="{{ old('inscricao_estadual', $config->inscricao_estadual) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block space-y-1 sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">E-mail</span>
                        <input type="email" name="email" value="{{ old('email', $config->email) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Site</span>
                        <input type="url" name="site_url" value="{{ old('site_url', $config->site_url) }}" placeholder="https://" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Telefone</span>
                        <input type="text" name="telefone" value="{{ old('telefone', $config->telefone) }}" data-autofill="telefone" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Celular</span>
                        <input type="text" name="celular" value="{{ old('celular', $config->celular) }}" data-autofill="celular" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-6">
                    <label class="block space-y-1 sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">CEP</span>
                        <div class="flex">
                            <input
                                type="text"
                                name="cep"
                                value="{{ old('cep', $config->cep) }}"
                                data-autofill="cep"
                                class="w-full rounded-l-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            >
                            <button
                                type="button"
                                data-action="buscar-cep"
                                class="flex shrink-0 items-center justify-center rounded-r-lg border border-l-0 border-gray-300 bg-blue-50 px-3 text-blue-600 transition hover:bg-blue-100 dark:border-gray-600 dark:bg-blue-950 dark:text-blue-400"
                                title="Buscar endereço pelo CEP"
                            >
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </button>
                        </div>
                        <span data-status="cep" class="block min-h-4 text-xs text-gray-400"></span>
                    </label>
                    <label class="block space-y-1 sm:col-span-4">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Endereço</span>
                        <input type="text" name="endereco" value="{{ old('endereco', $config->endereco) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-4">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Número</span>
                        <input type="text" name="numero" value="{{ old('numero', $config->numero) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1 sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Complemento</span>
                        <input type="text" name="complemento" value="{{ old('complemento', $config->complemento) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Bairro</span>
                        <input type="text" name="bairro" value="{{ old('bairro', $config->bairro) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-4">
                    <label class="block space-y-1 sm:col-span-3">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Cidade</span>
                        <input type="text" name="cidade" value="{{ old('cidade', $config->cidade) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">UF</span>
                        <input type="text" name="uf" value="{{ old('uf', $config->uf) }}" maxlength="2" data-autofill="uf" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm uppercase dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Responsável</span>
                        <input type="text" name="responsavel_nome" value="{{ old('responsavel_nome', $config->responsavel_nome) }}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">CPF do responsável</span>
                        <input type="text" name="responsavel_cpf" value="{{ old('responsavel_cpf', $config->responsavel_cpf) }}" data-autofill="cpf" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    </label>
                </div>

                <label class="block space-y-1">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Observações padrão dos relatórios (PDF)</span>
                    <textarea name="observacoes_relatorio" rows="4" placeholder="Texto exibido no rodapé dos relatórios..." class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">{{ old('observacoes_relatorio', $config->observacoes_relatorio) }}</textarea>
                </label>

                <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-600 dark:bg-gray-800">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">Prévia para PDF</p>
                    <p class="mt-2 text-gray-600 dark:text-gray-300">{{ $config->nome_fantasia ?: $config->razao_social ?: '—' }}</p>
                    @if ($config->cnpj)<p class="text-gray-500">CNPJ {{ $config->cnpj }}</p>@endif
                    <p class="mt-1 whitespace-pre-line text-gray-500">{{ \App\Support\PlatformSettings::dadosEmpresa()['endereco_completo'] ?: 'Endereço não informado' }}</p>
                </div>
            </div>

            @include('admin.configuracoes.partials.email', ['config' => $config])

            @include('admin.configuracoes.partials.kyc', ['config' => $config, 'ppidConfigurado' => $ppidConfigurado, 'diditConfigurado' => $diditConfigurado])

            @include('admin.configuracoes.partials.pagbank', ['config' => $config, 'pagbankConfigurado' => $pagbankConfigurado])

            <div class="flex justify-end border-t border-gray-100 pt-4 dark:border-gray-700">
                <button type="submit" class="rounded-lg bg-blue-600 px-6 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700">
                    Salvar configurações
                </button>
            </div>
        </form>

        @include('admin.configuracoes.partials.edi', ['reprocessamentos' => $ediReprocessamentos])
    </div>
</div>
@endsection

@section('scripts')
<script>
(() => {
    const form = document.querySelector('form[action="{{ route('admin.configuracoes.update') }}"]');
    if (!form) return;

    const onlyDigits = (value) => (value || '').replace(/\D/g, '');
    const field = (name) => form.querySelector(`[name="${name}"]`);

    const setStatus = (key, message, type = 'info') => {
        const el = form.querySelector(`[data-status="${key}"]`);
        if (!el) return;
        el.textContent = message;
        el.className = 'block min-h-4 text-xs ' + ({
            success: 'text-green-600 dark:text-green-400',
            error: 'text-red-600 dark:text-red-400',
            loading: 'text-blue-600 dark:text-blue-400',
        }[type] || 'text-gray-400');
    };

    const setValue = (name, value, overwrite = false) => {
        const input = field(name);
        if (!input || value === undefined || value === null) return;
        const text = String(value).trim();
        if (!text) return;
        if (overwrite || !input.value.trim()) {
            input.value = text;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    };

    const formatCnpj = (value) => {
        const digits = onlyDigits(value);
        if (digits.length !== 14) return value;
        return digits
            .replace(/^(\d{2})(\d)/, '$1.$2')
            .replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
            .replace(/\.(\d{3})(\d)/, '.$1/$2')
            .replace(/(\d{4})(\d)/, '$1-$2');
    };

    const formatCep = (value) => {
        const digits = onlyDigits(value);
        if (digits.length !== 8) return value;
        return `${digits.slice(0, 5)}-${digits.slice(5)}`;
    };

    const formatPhone = (ddd, phone) => {
        const digits = onlyDigits(`${ddd || ''}${phone || ''}`);
        if (digits.length < 10) return '';
        return digits.length === 11
            ? `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`
            : `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
    };

    const buscarCep = async (overwrite = false) => {
        const cep = onlyDigits(field('cep')?.value);
        if (cep.length !== 8) {
            setStatus('cep', 'CEP inválido.', 'error');
            return;
        }

        setStatus('cep', 'Buscando...', 'loading');

        try {
            const response = await fetch(`https://viacep.com.br/ws/${cep}/json/`);
            const data = await response.json();
            if (!response.ok || data.erro) throw new Error('CEP não encontrado.');

            setValue('endereco', data.logradouro, overwrite);
            setValue('bairro', data.bairro, overwrite);
            setValue('cidade', data.localidade, overwrite);
            setValue('uf', data.uf, overwrite);
            setValue('complemento', data.complemento, overwrite);
            setStatus('cep', 'Endereço preenchido.', 'success');
        } catch (error) {
            setStatus('cep', error.message || 'Erro na consulta.', 'error');
        }
    };

    const buscarCnpj = async (overwrite = true) => {
        const cnpj = onlyDigits(field('cnpj')?.value);
        if (cnpj.length !== 14) {
            setStatus('cnpj', 'CNPJ inválido.', 'error');
            return;
        }

        setStatus('cnpj', 'Buscando na Receita...', 'loading');

        try {
            const response = await fetch(`https://brasilapi.com.br/api/cnpj/v1/${cnpj}`);
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'CNPJ não encontrado.');

            setValue('cnpj', formatCnpj(cnpj), true);
            setValue('razao_social', data.razao_social, overwrite);
            setValue('nome_fantasia', data.nome_fantasia || data.razao_social, overwrite);
            setValue('email', data.email, overwrite);
            setValue('telefone', formatPhone(data.ddd_telefone_1, data.telefone_1), overwrite);
            setValue('celular', formatPhone(data.ddd_telefone_2, data.telefone_2), overwrite);
            setValue('cep', formatCep(data.cep), overwrite);
            setValue('endereco', data.logradouro, overwrite);
            setValue('numero', data.numero, overwrite);
            setValue('complemento', data.complemento, overwrite);
            setValue('bairro', data.bairro, overwrite);
            setValue('cidade', data.municipio, overwrite);
            setValue('uf', data.uf, overwrite);
            setValue('responsavel_nome', data.qsa?.[0]?.nome_socio, overwrite);
            setStatus('cnpj', 'Dados preenchidos com sucesso.', 'success');
        } catch (error) {
            setStatus('cnpj', error.message || 'Erro na consulta do CNPJ.', 'error');
        }
    };

    field('cep')?.addEventListener('blur', () => buscarCep(false));
    field('cnpj')?.addEventListener('blur', () => {
        if (onlyDigits(field('cnpj')?.value).length === 14) {
            buscarCnpj(false);
        }
    });

    form.querySelector('[data-action="buscar-cep"]')?.addEventListener('click', () => buscarCep(true));
    form.querySelector('[data-action="buscar-cnpj"]')?.addEventListener('click', () => buscarCnpj(true));
})();

(() => {
    const monitor = document.querySelector('[data-edi-monitor="1"]');
    if (!monitor) return;

    setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('aba', 'edi');
        window.location.href = url.toString();
    }, 15000);
})();
</script>
@endsection
