<div x-show="aba === 'pagbank'" x-cloak class="space-y-5">
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Credenciais da aplicação Connect PagBank para <code class="text-xs">POST /accounts</code> (cadastro SELLER) e renovação de token.
        Obtenha Client ID e Client Secret ao criar a aplicação no portal PagBank.
    </p>

    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ambiente da API</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 transition {{ old('pagbank_ambiente', $config->pagbank_ambiente ?: 'sandbox') === 'sandbox' ? 'border-amber-400 bg-amber-50 dark:border-amber-600 dark:bg-amber-950/30' : 'border-gray-200 bg-white dark:border-gray-600 dark:bg-gray-900' }}">
                <input
                    type="radio"
                    name="pagbank_ambiente"
                    value="sandbox"
                    @checked(old('pagbank_ambiente', $config->pagbank_ambiente ?: 'sandbox') === 'sandbox')
                    class="mt-1"
                >
                <span>
                    <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Sandbox</span>
                    <span class="mt-0.5 block text-xs text-gray-500">https://sandbox.api.pagseguro.com</span>
                    <span class="mt-1 block text-xs text-amber-700 dark:text-amber-400">Use e-mails @sandbox.pagseguro.com.br nos testes.</span>
                </span>
            </label>
            <label class="flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3 transition {{ old('pagbank_ambiente', $config->pagbank_ambiente) === 'producao' ? 'border-emerald-400 bg-emerald-50 dark:border-emerald-600 dark:bg-emerald-950/30' : 'border-gray-200 bg-white dark:border-gray-600 dark:bg-gray-900' }}">
                <input
                    type="radio"
                    name="pagbank_ambiente"
                    value="producao"
                    @checked(old('pagbank_ambiente', $config->pagbank_ambiente) === 'producao')
                    class="mt-1"
                >
                <span>
                    <span class="block text-sm font-semibold text-gray-800 dark:text-gray-100">Produção</span>
                    <span class="mt-0.5 block text-xs text-gray-500">https://api.pagseguro.com</span>
                    <span class="mt-1 block text-xs text-emerald-700 dark:text-emerald-400">Contas reais — dados validados pela Receita.</span>
                </span>
            </label>
        </div>
    </div>

    <label class="block space-y-1">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Client ID (x-client-id)</span>
        <input
            type="text"
            name="pagbank_client_id"
            value="{{ old('pagbank_client_id', $config->pagbank_client_id) }}"
            autocomplete="off"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
    </label>

    <label class="block space-y-1">
        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Client Secret (x-client-secret)</span>
        <input
            type="password"
            name="pagbank_client_secret"
            value=""
            autocomplete="new-password"
            placeholder="{{ $config->pagbank_client_secret ? '••••••••  (deixe em branco para manter)' : 'Secret da aplicação Connect' }}"
            class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
    </label>

    <div x-data="pagbankCredenciais()" class="space-y-3">
        <label class="block space-y-1">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Bearer Token (Authorization)</span>
            <div class="flex gap-2">
                <input
                    type="password"
                    name="pagbank_token"
                    x-model="token"
                    autocomplete="new-password"
                    placeholder="{{ $pagbankConfigurado ? '••••••••  (deixe em branco para manter o atual)' : 'Cole o token do parceiro...' }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                <button
                    type="button"
                    @click="buscar"
                    :disabled="carregando || !token"
                    class="shrink-0 rounded-lg border border-blue-300 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100 disabled:opacity-40 dark:border-blue-700 dark:bg-blue-950 dark:text-blue-300"
                >
                    <i class="fa-solid fa-wand-magic-sparkles mr-1"></i>
                    <span x-text="carregando ? 'Buscando...' : 'Buscar credenciais'"></span>
                </button>
            </div>
            <span class="text-xs text-gray-400">
                Cole o token e clique em <strong>Buscar credenciais</strong> para preencher Client ID e Secret automaticamente.
            </span>
        </label>

        <template x-if="erro">
            <p class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700" x-text="erro"></p>
        </template>
        <template x-if="sucesso">
            <p class="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs font-semibold text-green-700">
                <i class="fa-solid fa-circle-check mr-1"></i> Client ID e Secret preenchidos automaticamente!
            </p>
        </template>

        @if ($pagbankConfigurado)
            <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
                <i class="fa-solid fa-circle-check"></i> Credenciais completas (Client ID, Secret e Bearer)
            </span>
        @endif
    </div>

    <script>
    function pagbankCredenciais() {
        return {
            token: '',
            carregando: false,
            erro: '',
            sucesso: false,
            async buscar() {
                this.erro = '';
                this.sucesso = false;
                this.carregando = true;

                const ambiente = document.querySelector('input[name="pagbank_ambiente"]:checked')?.value ?? 'sandbox';

                try {
                    const resp = await fetch('{{ route('admin.configuracoes.pagbank.buscar-credenciais') }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        },
                        body: JSON.stringify({ token: this.token, ambiente }),
                    });

                    const data = await resp.json();

                    if (data.ok) {
                        if (data.client_id) {
                            document.querySelector('input[name="pagbank_client_id"]').value = data.client_id;
                        }
                        if (data.client_secret) {
                            document.querySelector('input[name="pagbank_client_secret"]').value = data.client_secret;
                        }
                        this.sucesso = true;
                    } else {
                        this.erro = data.erro ?? 'Erro ao buscar credenciais.';
                    }
                } catch (e) {
                    this.erro = 'Falha na requisição: ' + e.message;
                } finally {
                    this.carregando = false;
                }
            },
        };
    }
    </script>

    <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-600 dark:bg-gray-800">
        <p class="font-semibold text-gray-700 dark:text-gray-200">URL ativa após salvar</p>
        <p class="mt-1 font-mono text-xs text-gray-600 dark:text-gray-300">{{ \App\Support\PlatformSettings::pagbankApiUrl() }}</p>
        <p class="mt-2 text-xs text-gray-500">
            Ambiente atual: <strong>{{ \App\Support\PlatformSettings::pagbankAmbienteRotulo() }}</strong>.
        </p>
    </div>

    {{-- EDI --}}
    <div class="border-t border-gray-100 pt-5 dark:border-gray-700">
        <h3 class="mb-1 text-sm font-semibold text-gray-800 dark:text-gray-100">EDI PagBank</h3>
        <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
            Credenciais do parceiro (modelo 1xN): <strong>USER</strong> + <strong>TOKEN</strong> em Basic Auth.
            Uma consulta por dia retorna movimentos de todos os estabelecimentos; cada loja é vinculada pelo
            <code class="text-xs">token_pagseguro</code> (ID Safepay).
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span class="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold text-amber-700">SANDBOX</span>
                    USER EDI
                </span>
                <input
                    type="text"
                    name="pagbank_edi_user_sandbox"
                    value="{{ old('pagbank_edi_user_sandbox', $config->pagbank_edi_user_sandbox) }}"
                    autocomplete="off"
                    placeholder="USER parceiro sandbox..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span class="mr-1 inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-bold text-emerald-700">PRODUÇÃO</span>
                    USER EDI
                </span>
                <input
                    type="text"
                    name="pagbank_edi_user_producao"
                    value="{{ old('pagbank_edi_user_producao', $config->pagbank_edi_user_producao) }}"
                    autocomplete="off"
                    placeholder="USER parceiro produção..."
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span class="mr-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs font-bold text-amber-700">SANDBOX</span>
                    Token EDI
                </span>
                <input
                    type="password"
                    name="pagbank_edi_token_sandbox"
                    value=""
                    autocomplete="new-password"
                    placeholder="{{ $config->pagbank_edi_token_sandbox ? '•••••••• (deixe em branco para manter)' : 'Token EDI sandbox...' }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                @if ($config->pagbank_edi_token_sandbox)
                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
                        <i class="fa-solid fa-circle-check"></i> Configurado
                    </span>
                @else
                    <span class="text-xs text-amber-600">Não configurado</span>
                @endif
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span class="mr-1 inline-block rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-bold text-emerald-700">PRODUÇÃO</span>
                    Token EDI
                </span>
                <input
                    type="password"
                    name="pagbank_edi_token_producao"
                    value=""
                    autocomplete="new-password"
                    placeholder="{{ $config->pagbank_edi_token_producao ? '•••••••• (deixe em branco para manter)' : 'Token EDI produção...' }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                @if ($config->pagbank_edi_token_producao)
                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-green-600">
                        <i class="fa-solid fa-circle-check"></i> Configurado
                    </span>
                @else
                    <span class="text-xs text-gray-400">Não configurado</span>
                @endif
            </label>
        </div>

        <div class="mt-3 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-3 text-xs text-gray-500 dark:border-gray-600 dark:bg-gray-800">
            <strong>EDI ativo agora:</strong>
            @if (\App\Support\PlatformSettings::ediConfigurado())
                <span class="font-semibold text-green-600">Configurado</span>
                (USER: <code>{{ \App\Support\PlatformSettings::ediUser() }}</code>,
                ambiente: <strong>{{ \App\Support\PlatformSettings::pagbankAmbienteRotulo() }}</strong>)
            @else
                <span class="font-semibold text-amber-600">Incompleto</span> — informe USER + TOKEN ou use
                <code>platform:edi-token</code> com <code>--user=</code>.
            @endif
        </div>
    </div>

    {{-- Força de Vendas --}}
    <div class="border-t border-gray-100 pt-5 dark:border-gray-700">
        <h3 class="mb-1 text-sm font-semibold text-gray-800 dark:text-gray-100">Força de Vendas (automação)</h3>
        <p class="mb-4 text-xs text-gray-500 dark:text-gray-400">
            Login do portal PagBank usado pela automação para cadastrar estabelecimentos.
            Se preencher aqui, vale o painel. Se deixar a senha em branco, mantém a atual (ou o <code class="text-xs">.env</code>).
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Usuário FV</span>
                <input
                    type="text"
                    name="automacao_fv_usuario"
                    value="{{ old('automacao_fv_usuario', $config->automacao_fv_usuario ?: \App\Support\PlatformSettings::automacaoFvUsuario()) }}"
                    autocomplete="off"
                    placeholder="ex.: expresspayments_08"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
            </label>

            <label class="block space-y-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Senha FV</span>
                <input
                    type="password"
                    name="automacao_fv_senha"
                    value=""
                    autocomplete="new-password"
                    placeholder="{{ filled($config->automacao_fv_senha) || filled(\App\Support\PlatformSettings::automacaoFvSenha()) ? '•••••••• (deixe em branco para manter)' : 'Senha do portal Força de Vendas' }}"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
            </label>
        </div>

        <div class="mt-3 rounded-lg border border-dashed border-gray-200 bg-gray-50 p-3 text-xs text-gray-500 dark:border-gray-600 dark:bg-gray-800">
            <strong>Login FV ativo agora:</strong>
            @if (\App\Support\PlatformSettings::automacaoFvConfigurado())
                <span class="font-semibold text-green-600">Configurado</span>
                (usuário: <code>{{ \App\Support\PlatformSettings::automacaoFvUsuario() }}</code>
                @if (filled($config->automacao_fv_usuario) || filled($config->automacao_fv_senha))
                    · origem: painel
                @else
                    · origem: .env
                @endif
                )
            @else
                <span class="font-semibold text-amber-600">Incompleto</span> — informe usuário e senha para a automação entrar no portal.
            @endif
        </div>
    </div>
</div>
