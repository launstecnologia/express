<div x-show="aba === 'kyc'" x-cloak class="space-y-5">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        A validação automática de RG, CNH e comprovante de endereço usa a <strong>Didit</strong>. A chave salva aqui vale no lugar da variável <code class="text-xs">DIDIT_API_KEY</code> do servidor.
    </p>

    <label class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
        <input type="hidden" name="didit_ativo" value="0">
        <input type="checkbox" name="didit_ativo" value="1" @checked(old('didit_ativo', $config->didit_ativo ?? true)) class="h-4 w-4 rounded border-gray-300 text-blue-600">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Didit ativa</span>
    </label>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block space-y-1">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Chave da API Didit</span>
            <input
                type="password"
                name="didit_api_key"
                value=""
                autocomplete="new-password"
                placeholder="{{ $diditConfigurado ? '••••••••  (deixe em branco para manter)' : 'Cole a chave da Didit' }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
        </label>
        <label class="block space-y-1">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">URL da API Didit</span>
            <input
                type="url"
                name="didit_api_url"
                value="{{ old('didit_api_url', $config->didit_api_url ?: 'https://verification.didit.me') }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
        </label>
    </div>

    @if ($diditConfigurado)
        <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
            <i class="fa-solid fa-circle-check"></i> Didit habilitada
        </span>
    @else
        <span class="inline-flex items-center gap-1 text-xs font-semibold text-amber-600">
            <i class="fa-solid fa-triangle-exclamation"></i> Didit desligada ou sem chave — documentos irão para revisão manual
        </span>
    @endif

    <label class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
        <input type="hidden" name="kyc_ativo" value="0">
        <input type="checkbox" name="kyc_ativo" value="1" @checked(old('kyc_ativo', $config->kyc_ativo ?? true)) class="h-4 w-4 rounded border-gray-300 text-blue-600">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Módulo KYC ativo</span>
    </label>

    <label class="block space-y-1">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">URL da API PPID</span>
        <input
            type="url"
            name="ppid_api_url"
            value="{{ old('ppid_api_url', $config->ppid_api_url ?: 'https://api.ppid.com.br') }}"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
        <span class="text-xs text-gray-400">Padrão: https://api.ppid.com.br — também pode ser definido em <code class="text-xs">PPID_API_URL</code> no .env.</span>
    </label>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block space-y-1">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">E-mail PPID</span>
            <input
                type="email"
                name="ppid_email"
                value="{{ old('ppid_email', $config->ppid_email) }}"
                autocomplete="off"
                placeholder="conta@empresa.com.br"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
        </label>

        <label class="block space-y-1">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Senha PPID</span>
            <input
                type="password"
                name="ppid_senha"
                value=""
                autocomplete="new-password"
                placeholder="{{ $ppidConfigurado ? '••••••••  (deixe em branco para manter)' : 'Senha do painel PPID' }}"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
        </label>
    </div>

    <label class="block max-w-xs space-y-1">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Limite mensal de consultas OCR</span>
        <input
            type="number"
            name="ppid_limite_mensal"
            min="1"
            max="5000"
            value="{{ old('ppid_limite_mensal', $config->ppid_limite_mensal ?: 490) }}"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
        <span class="text-xs text-gray-400">Padrão: 490 (margem abaixo dos 500 do plano gratuito PPID).</span>
    </label>

    <label class="block space-y-1">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">URL BrasilAPI (Receita / CNPJ)</span>
        <input
            type="url"
            name="brasilapi_url"
            value="{{ old('brasilapi_url', $config->brasilapi_url ?: 'https://brasilapi.com.br/api') }}"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
    </label>
</div>
