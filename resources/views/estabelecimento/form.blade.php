@extends('layouts.app')

@section('title', $estabelecimento->exists ? 'Editar Estabelecimento' : 'Novo Estabelecimento')

@section('content')
@php
    use App\Support\UsuarioComercial;

    $inputClass = 'w-full rounded border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 shadow-sm placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    $selectClass = 'w-full rounded border border-slate-300 bg-white px-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    $labelClass = 'space-y-1';
    $labelTextClass = 'text-[11px] font-semibold text-slate-800';
    $sectionTitleClass = 'border-b border-slate-200 px-3 py-4 text-base font-semibold text-slate-600';
    $dateValue = fn (string $field) => old($field, optional($estabelecimento->{$field})->format('Y-m-d'));
    $pessoaTipo = old('pessoa_tipo', $estabelecimento->pessoa_tipo ?: 'juridica');
    $segmentoSelecionado = old('segmento', $estabelecimento->segmento);
    $mostrarMaster = UsuarioComercial::ehAdmin();
    $mostrarMarketplace = UsuarioComercial::ehAdmin();
    $mostrarRevenda = UsuarioComercial::ehAdmin() || UsuarioComercial::ehMarketplace();
    $escolherModoCadastro = ! $estabelecimento->exists && UsuarioComercial::deveEscolherModoCadastro();
@endphp

<form
    id="form-estabelecimento"
    method="POST"
    action="{{ $estabelecimento->exists ? route('estabelecimentos.update', $estabelecimento) : route('estabelecimentos.store') }}"
    class="overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm"
    @if ($escolherModoCadastro) data-escolher-modo-cadastro @endif
>
    @csrf
    @if ($estabelecimento->exists) @method('PUT') @endif
    <input type="hidden" name="ativo" value="1">
    <input type="hidden" name="status" value="{{ old('status', $estabelecimento->status ?: 'pendente') }}">
    <input type="hidden" name="risco" value="{{ old('risco', $estabelecimento->risco ?: 'confiavel') }}">
    <input type="hidden" name="modo_cadastro" id="modo-cadastro-input" value="{{ old('modo_cadastro') }}">

    <div class="flex min-h-32 items-start justify-between px-3 py-5">
        <label class="{{ $labelClass }} mt-12 w-48">
            <span class="{{ $labelTextClass }}">ID</span>
            <input value="{{ $estabelecimento->exists ? $estabelecimento->id : '' }}" readonly class="{{ $inputClass }} bg-slate-50">
        </label>

        <div class="inline-flex overflow-hidden rounded border border-blue-600 text-xs font-semibold">
            <label data-pessoa-toggle="juridica" class="cursor-pointer px-4 py-2 {{ $pessoaTipo === 'juridica' ? 'bg-blue-600 text-white' : 'bg-white text-blue-600' }}">
                <input type="radio" name="pessoa_tipo" value="juridica" @checked($pessoaTipo === 'juridica') class="sr-only">
                Pessoa Jurídica
            </label>
            <label data-pessoa-toggle="fisica" class="cursor-pointer border-l border-blue-600 px-4 py-2 {{ $pessoaTipo === 'fisica' ? 'bg-blue-600 text-white' : 'bg-white text-blue-600' }}">
                <input type="radio" name="pessoa_tipo" value="fisica" @checked($pessoaTipo === 'fisica') class="sr-only">
                Pessoa Física
            </label>
        </div>
    </div>

    @if ($errors->any())
        <div class="mx-3 mb-4 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-700">
            <p class="font-semibold">Revise os campos antes de salvar.</p>
            <ul class="mt-2 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div data-pessoa-section="juridica" class="{{ $pessoaTipo === 'fisica' ? 'hidden' : '' }}">
        <h2 class="{{ $sectionTitleClass }}">Dados Empresariais</h2>
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            <label class="{{ $labelClass }} md:col-span-4">
                <span class="{{ $labelTextClass }}">CNPJ</span>
                <div class="flex">
                    <input data-autofill="cnpj" name="cnpj" value="{{ old('cnpj', $estabelecimento->cnpj) }}" class="{{ $inputClass }} rounded-r-none">
                    <button type="button" data-action="buscar-cnpj" class="w-9 rounded-r border border-l-0 border-teal-400 text-teal-600 hover:bg-teal-50">
                        <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                    </button>
                </div>
                <span data-status="cnpj" class="block min-h-4 text-[11px] text-slate-400"></span>
            </label>
            <label class="{{ $labelClass }} md:col-span-6">
                <span class="{{ $labelTextClass }}">Razão Social</span>
                <input data-autofill="razao_social" name="razao_social" value="{{ old('razao_social', $estabelecimento->razao_social) }}" placeholder="Razão Social" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-2">
                <span class="{{ $labelTextClass }}">Ins. Est.</span>
                <input data-autofill="inscricao_estadual" name="inscricao_estadual" value="{{ old('inscricao_estadual', $estabelecimento->inscricao_estadual) }}" placeholder="Ins. Est." class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-9">
                <span class="{{ $labelTextClass }}">Nome Fantasia</span>
                <input data-autofill="nome_fantasia" name="nome_fantasia" value="{{ old('nome_fantasia', $estabelecimento->nome_fantasia) }}" placeholder="Nome Fantasia" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">Data de Abertura</span>
                <input data-autofill="data_abertura" type="date" name="data_abertura" value="{{ $dateValue('data_abertura') }}" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-12">
                <span class="{{ $labelTextClass }}">Segmento</span>
                <select name="segmento" class="{{ $selectClass }}">
                    <option value="">Selecione</option>
                    @foreach ($segmentos as $segmento)
                        <option value="{{ $segmento->nome }}" @selected($segmentoSelecionado === $segmento->nome)>{{ $segmento->nome }}</option>
                    @endforeach
                    @if ($segmentoSelecionado && ! $segmentos->contains('nome', $segmentoSelecionado))
                        <option value="{{ $segmentoSelecionado }}" selected>{{ $segmentoSelecionado }}</option>
                    @endif
                </select>
            </label>
            <label class="{{ $labelClass }} md:col-span-12">
                <span class="{{ $labelTextClass }}">Faturamento Mensal</span>
                <select name="faturamento_mensal" class="{{ $selectClass }}">
                    <option value="">Selecione...</option>
                    @foreach (['De R$ 1 mil até R$ 5 mil', 'De R$ 5 mil até R$ 10 mil', 'Acima de R$ 10 mil'] as $faixa)
                        <option value="{{ $faixa }}" @selected(old('faturamento_mensal', $estabelecimento->faturamento_mensal) === $faixa)>{{ $faixa }}</option>
                    @endforeach
                </select>
                <span class="mt-1 text-xs text-gray-400">Usado no cadastro do portal PagBank Força de Vendas.</span>
            </label>
        </div>

        <h2 class="{{ $sectionTitleClass }}">Representante Legal</h2>
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            <label class="{{ $labelClass }} md:col-span-6">
                <span class="{{ $labelTextClass }}">Nome Completo</span>
                <input data-autofill="rep_nome" name="rep_nome" value="{{ old('rep_nome', $estabelecimento->rep_nome) }}" placeholder="Nome Completo" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">CPF</span>
                <input name="rep_cpf" value="{{ old('rep_cpf', $estabelecimento->rep_cpf) }}" placeholder="CPF" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">Data de Nascimento</span>
                <input type="date" name="rep_data_nascimento" value="{{ $dateValue('rep_data_nascimento') }}" class="{{ $inputClass }}">
            </label>
        </div>
    </div>

    <div data-pessoa-section="fisica" class="{{ $pessoaTipo === 'juridica' ? 'hidden' : '' }}">
        <h2 class="{{ $sectionTitleClass }}">Pessoa Física</h2>
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            <label class="{{ $labelClass }} md:col-span-6">
                <span class="{{ $labelTextClass }}">Nome Completo</span>
                <input name="nome_completo" value="{{ old('nome_completo', $estabelecimento->nome_completo) }}" placeholder="Nome Completo" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">CPF</span>
                <input name="cpf" value="{{ old('cpf', $estabelecimento->cpf) }}" placeholder="CPF" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">Data de Nascimento</span>
                <input type="date" name="data_nascimento" value="{{ $dateValue('data_nascimento') }}" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-12">
                <span class="{{ $labelTextClass }}">Nome Fantasia</span>
                <input name="nome_fantasia" value="{{ old('nome_fantasia', $estabelecimento->nome_fantasia) }}" placeholder="Nome Fantasia" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-12">
                <span class="{{ $labelTextClass }}">Segmento</span>
                <select name="segmento" class="{{ $selectClass }}">
                    <option value="">Selecione</option>
                    @foreach ($segmentos as $segmento)
                        <option value="{{ $segmento->nome }}" @selected($segmentoSelecionado === $segmento->nome)>{{ $segmento->nome }}</option>
                    @endforeach
                    @if ($segmentoSelecionado && ! $segmentos->contains('nome', $segmentoSelecionado))
                        <option value="{{ $segmentoSelecionado }}" selected>{{ $segmentoSelecionado }}</option>
                    @endif
                </select>
            </label>
            <label class="{{ $labelClass }} md:col-span-12">
                <span class="{{ $labelTextClass }}">Faturamento Mensal</span>
                <select name="faturamento_mensal" class="{{ $selectClass }}">
                    <option value="">Selecione...</option>
                    @foreach (['De R$ 1 mil até R$ 5 mil', 'De R$ 5 mil até R$ 10 mil', 'Acima de R$ 10 mil'] as $faixa)
                        <option value="{{ $faixa }}" @selected(old('faturamento_mensal', $estabelecimento->faturamento_mensal) === $faixa)>{{ $faixa }}</option>
                    @endforeach
                </select>
                <span class="mt-1 text-xs text-gray-400">Usado no cadastro do portal PagBank Força de Vendas.</span>
            </label>
        </div>
    </div>

    <h2 class="{{ $sectionTitleClass }}">Endereço</h2>
    <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
        <label class="{{ $labelClass }} md:col-span-3">
            <span class="{{ $labelTextClass }}">CEP</span>
            <div class="flex">
                <input data-autofill="cep" name="cep" value="{{ old('cep', $estabelecimento->cep) }}" class="{{ $inputClass }} rounded-r-none">
                <button type="button" data-action="buscar-cep" class="w-9 rounded-r border border-l-0 border-teal-400 text-teal-600 hover:bg-teal-50">
                    <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                </button>
            </div>
            <span data-status="cep" class="block min-h-4 text-[11px] text-slate-400"></span>
        </label>
        <div class="hidden md:col-span-9 md:block"></div>
        <label class="{{ $labelClass }} md:col-span-6">
            <span class="{{ $labelTextClass }}">Endereço</span>
            <input data-autofill="endereco" name="endereco" value="{{ old('endereco', $estabelecimento->endereco) }}" placeholder="Endereço" class="{{ $inputClass }}">
        </label>
        <div class="{{ $labelClass }} md:col-span-2">
            @php
                $semNumero = (bool) old('sem_numero', in_array(strtoupper(trim((string) ($estabelecimento->numero ?? ''))), ['00', 'S/N', 'SN'], true));
            @endphp
            <span class="{{ $labelTextClass }}">Número</span>
            <div class="flex items-center gap-2">
                <input
                    data-autofill="numero"
                    name="numero"
                    value="{{ old('numero', $semNumero ? '' : $estabelecimento->numero) }}"
                    placeholder="Número"
                    class="{{ $inputClass }} min-w-0 flex-1"
                    @disabled($semNumero)
                >
                <label class="flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap text-[11px] font-medium text-slate-500">
                    <input
                        type="checkbox"
                        name="sem_numero"
                        value="1"
                        data-sem-numero
                        class="rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        @checked($semNumero)
                    >
                    S/N
                </label>
            </div>
        </div>
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Complemento</span>
            <input data-autofill="complemento" name="complemento" value="{{ old('complemento', $estabelecimento->complemento) }}" placeholder="Complemento" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-5">
            <span class="{{ $labelTextClass }}">Bairro</span>
            <input data-autofill="bairro" name="bairro" value="{{ old('bairro', $estabelecimento->bairro) }}" placeholder="Bairro" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-6">
            <span class="{{ $labelTextClass }}">Cidade</span>
            <input data-autofill="cidade" name="cidade" value="{{ old('cidade', $estabelecimento->cidade) }}" placeholder="Cidade" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-1">
            <span class="{{ $labelTextClass }}">UF</span>
            <input data-autofill="uf" name="uf" value="{{ old('uf', $estabelecimento->uf) }}" maxlength="2" placeholder="UF" class="{{ $inputClass }} uppercase">
        </label>
    </div>

    <h2 class="{{ $sectionTitleClass }}">Dados contato</h2>
    <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Celular *</span>
            <input
                name="celular"
                value="{{ old('celular', $estabelecimento->celular) }}"
                placeholder="(DDD) 90000-0000"
                inputmode="numeric"
                maxlength="16"
                required
                class="{{ $inputClass }}"
            >
            <span class="text-[10px] text-slate-400">Informe manualmente · 9 dígitos após o DDD</span>
        </label>
        <label class="{{ $labelClass }} md:col-span-8">
            <span class="{{ $labelTextClass }}">E-mail *</span>
            <input type="email" name="email" value="{{ old('email', $estabelecimento->email) }}" placeholder="exemplo@gmail.com" required class="{{ $inputClass }}">
            <span class="text-[10px] text-slate-400">Informe manualmente · não vem da Receita</span>
        </label>
    </div>

    <h2 class="{{ $sectionTitleClass }}">Configurações</h2>
    <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
        <label class="{{ $labelClass }} md:col-span-8">
            <span class="{{ $labelTextClass }}">ID PagSeguro</span>
            <input name="token_pagseguro" value="{{ old('token_pagseguro', $estabelecimento->token_pagseguro) }}" placeholder="ID PagSeguro" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Plano</span>
            <select name="plano_id" class="{{ $selectClass }}">
                <option value="">Selecione</option>
                @foreach ($planos as $plano)
                    <option value="{{ $plano->id }}" @selected((int) old('plano_id', $estabelecimento->plano_id) === $plano->id)>{{ $plano->nome }}</option>
                @endforeach
            </select>
        </label>
        @if ($mostrarMaster)
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Master</span>
            <select name="master_id" class="{{ $selectClass }}">
                <option value="">Selecione</option>
                @foreach ($gestores as $gestor)
                    <option value="{{ $gestor->id }}" @selected((int) old('master_id', $estabelecimento->master_id) === $gestor->id)>{{ $gestor->nomeExibicao() }}</option>
                @endforeach
            </select>
        </label>
        @endif
        @if ($mostrarMarketplace)
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Marketplace</span>
            <select name="marketplace_id" class="{{ $selectClass }}">
                <option value="">Selecione</option>
                @foreach ($representantes as $representante)
                    <option value="{{ $representante->id }}" @selected((int) old('marketplace_id', $estabelecimento->marketplace_id) === $representante->id)>{{ $representante->nomeExibicao() }}</option>
                @endforeach
            </select>
        </label>
        @endif
        @if ($mostrarRevenda)
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Revenda</span>
            <select name="revenda_id" class="{{ $selectClass }}">
                <option value="">Selecione</option>
                @foreach ($revendas as $revenda)
                    <option value="{{ $revenda->id }}" @selected((int) old('revenda_id', $estabelecimento->revenda_id) === $revenda->id)>{{ $revenda->nomeExibicao() }}</option>
                @endforeach
            </select>
        </label>
        @endif
    </div>

    <h2 class="{{ $sectionTitleClass }}">Anotações</h2>
    <div class="space-y-3 px-3 py-4">
        <label class="{{ $labelClass }} block">
            <span class="{{ $labelTextClass }}">Anotações - Interno</span>
            <textarea name="anotacoes_interno" rows="5" placeholder="Anotações" class="{{ $inputClass }}">{{ old('anotacoes_interno', $estabelecimento->anotacoes_interno) }}</textarea>
        </label>
        <label class="{{ $labelClass }} block">
            <span class="{{ $labelTextClass }}">Anotações</span>
            <textarea name="anotacoes" rows="5" placeholder="Anotações" class="{{ $inputClass }}">{{ old('anotacoes', $estabelecimento->anotacoes) }}</textarea>
        </label>
    </div>

    <div class="flex justify-end px-3 pb-3 pt-4">
        <button type="{{ $escolherModoCadastro ? 'button' : 'submit' }}" id="btn-salvar-estabelecimento" class="rounded bg-indigo-900 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-800">
            {{ $estabelecimento->exists ? 'Atualizar' : 'Registrar' }}
        </button>
    </div>
</form>

@if ($escolherModoCadastro)
<div id="modal-modo-cadastro" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/40 px-4">
    <div class="w-full max-w-lg rounded-xl bg-white shadow-2xl">
        <div class="border-b border-gray-100 px-5 py-4">
            <h3 class="text-lg font-semibold text-gray-800">Como deseja salvar?</h3>
            <p class="mt-1 text-sm text-gray-500">O e-mail da plataforma e a PagBank não são criados neste momento.</p>
        </div>
        <div class="space-y-3 p-5">
            <button type="button" data-modo-cadastro="completo" class="flex w-full items-start gap-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-100">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-700 text-white">
                    <i class="fa-solid fa-robot text-sm"></i>
                </span>
                <span>
                    <span class="block text-sm font-bold text-indigo-900">Cadastro completo</span>
                    <span class="mt-1 block text-xs text-indigo-800/80">Grava o estabelecimento. O e-mail da plataforma e o cadastro na PagBank começam somente quando o status for aprovado.</span>
                </span>
            </button>
            <button type="button" data-modo-cadastro="apenas_dados" class="flex w-full items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 text-left transition hover:border-gray-300 hover:bg-gray-100">
                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gray-600 text-white">
                    <i class="fa-solid fa-floppy-disk text-sm"></i>
                </span>
                <span>
                    <span class="block text-sm font-bold text-gray-800">Salvar apenas os dados</span>
                    <span class="mt-1 block text-xs text-gray-600">Grava o estabelecimento sem criar e-mail e sem iniciar automação. Você pode acionar depois na aba Automação.</span>
                </span>
            </button>
        </div>
        <div class="border-t border-gray-100 px-5 py-4 text-right">
            <button type="button" id="modal-modo-cadastro-fechar" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">
                Cancelar
            </button>
        </div>
    </div>
</div>
@endif
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const onlyDigits = (value) => (value || '').replace(/\D/g, '');
        const field = (name) => document.querySelector(`[data-autofill="${name}"]`);
        const status = (name) => document.querySelector(`[data-status="${name}"]`);

        const setStatus = (name, message, type = 'info') => {
            const element = status(name);

            if (!element) {
                return;
            }

            const classes = {
                info: 'text-slate-400',
                loading: 'text-blue-500',
                success: 'text-green-600',
                error: 'text-red-500',
            };

            element.className = `block min-h-4 text-[11px] ${classes[type]}`;
            element.textContent = message;
        };

        const setValue = (name, value, overwrite = false) => {
            const element = field(name);

            if (!element || value === undefined || value === null || value === '') {
                return;
            }

            if (!overwrite && element.value.trim() !== '') {
                return;
            }

            if (element.tagName === 'SELECT' && !Array.from(element.options).some((option) => option.value === value)) {
                element.add(new Option(value, value));
            }

            element.value = value;
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));

            if (name === 'numero' && value) {
                const semNumeroCheckbox = document.querySelector('[data-sem-numero]');
                if (semNumeroCheckbox?.checked) {
                    semNumeroCheckbox.checked = false;
                    syncSemNumero?.();
                }
            }
        };

        const normalizeDate = (value) => {
            if (!value) {
                return '';
            }

            if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                return value;
            }

            const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);

            return match ? `${match[3]}-${match[2]}-${match[1]}` : '';
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

                if (!response.ok || data.erro) {
                    throw new Error('CEP não encontrado.');
                }

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

        const buscarCnpj = async (overwrite = false) => {
            const cnpj = onlyDigits(field('cnpj')?.value);

            if (cnpj.length !== 14) {
                setStatus('cnpj', 'CNPJ inválido.', 'error');
                return;
            }

            setStatus('cnpj', 'Buscando...', 'loading');

            try {
                const response = await fetch(`https://brasilapi.com.br/api/cnpj/v1/${cnpj}`);
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'CNPJ não encontrado.');
                }

                setValue('razao_social', data.razao_social, overwrite);
                setValue('nome_fantasia', data.nome_fantasia || data.razao_social, overwrite);
                setValue('data_abertura', normalizeDate(data.data_inicio_atividade), overwrite);
                setValue('cep', data.cep, overwrite);
                setValue('endereco', data.logradouro, overwrite);
                setValue('numero', data.numero, overwrite);
                setValue('complemento', data.complemento, overwrite);
                setValue('bairro', data.bairro, overwrite);
                setValue('cidade', data.municipio, overwrite);
                setValue('uf', data.uf, overwrite);
                setValue('rep_nome', data.qsa?.[0]?.nome_socio, overwrite);

                setStatus('cnpj', 'Dados preenchidos.', 'success');
            } catch (error) {
                setStatus('cnpj', error.message || 'Erro na consulta.', 'error');
            }
        };

        field('cep')?.addEventListener('blur', () => buscarCep(false));
        field('cnpj')?.addEventListener('blur', () => buscarCnpj(false));

        document.querySelector('[data-action="buscar-cep"]')?.addEventListener('click', () => buscarCep(true));
        document.querySelector('[data-action="buscar-cnpj"]')?.addEventListener('click', () => buscarCnpj(true));

        const semNumeroCheckbox = document.querySelector('[data-sem-numero]');
        const numeroInput = field('numero');

        const syncSemNumero = () => {
            const sem = !!semNumeroCheckbox?.checked;
            if (!numeroInput) return;
            numeroInput.disabled = sem;
            if (sem) {
                numeroInput.value = '';
                numeroInput.placeholder = 'Sem número';
            } else {
                numeroInput.placeholder = 'Número';
            }
        };

        semNumeroCheckbox?.addEventListener('change', syncSemNumero);
        syncSemNumero();

        document.querySelector('form')?.addEventListener('submit', () => {
            if (semNumeroCheckbox?.checked && numeroInput) {
                numeroInput.disabled = false;
                numeroInput.value = '';
            }
        });

        const syncPessoaTipo = (value) => {
            document.querySelectorAll('[data-pessoa-toggle]').forEach((label) => {
                const active = label.dataset.pessoaToggle === value;
                label.classList.toggle('bg-blue-600', active);
                label.classList.toggle('text-white', active);
                label.classList.toggle('bg-white', !active);
                label.classList.toggle('text-blue-600', !active);
            });

            document.querySelectorAll('[data-pessoa-section]').forEach((section) => {
                const active = section.dataset.pessoaSection === value;
                section.classList.toggle('hidden', !active);
                section.querySelectorAll('input, select, textarea, button').forEach((control) => {
                    control.disabled = !active;
                });
            });
        };

        document.querySelectorAll('input[name="pessoa_tipo"]').forEach((input) => {
            input.addEventListener('change', () => syncPessoaTipo(input.value));
        });

        syncPessoaTipo(document.querySelector('input[name="pessoa_tipo"]:checked')?.value || 'juridica');

        @if (! empty($prefillDocumento ?? false))
            const prefillCnpj = field('cnpj');
            if (prefillCnpj?.value && prefillCnpj.value.replace(/\D/g, '').length === 14) {
                buscarCnpj(true);
            }
        @endif

        const formEstabelecimento = document.getElementById('form-estabelecimento');
        const modalModoCadastro = document.getElementById('modal-modo-cadastro');
        const modoCadastroInput = document.getElementById('modo-cadastro-input');
        const btnSalvarEstabelecimento = document.getElementById('btn-salvar-estabelecimento');

        const abrirModalModoCadastro = () => {
            if (!modalModoCadastro) return;
            modalModoCadastro.classList.remove('hidden');
            modalModoCadastro.classList.add('flex');
        };

        const fecharModalModoCadastro = () => {
            if (!modalModoCadastro) return;
            modalModoCadastro.classList.add('hidden');
            modalModoCadastro.classList.remove('flex');
        };

        btnSalvarEstabelecimento?.addEventListener('click', () => {
            if (!formEstabelecimento?.hasAttribute('data-escolher-modo-cadastro')) return;
            if (!formEstabelecimento.reportValidity()) return;
            abrirModalModoCadastro();
        });

        document.getElementById('modal-modo-cadastro-fechar')?.addEventListener('click', fecharModalModoCadastro);
        modalModoCadastro?.addEventListener('click', (event) => {
            if (event.target === modalModoCadastro) fecharModalModoCadastro();
        });

        document.querySelectorAll('[data-modo-cadastro]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!modoCadastroInput || !formEstabelecimento) return;
                modoCadastroInput.value = button.dataset.modoCadastro || '';
                fecharModalModoCadastro();
                formEstabelecimento.submit();
            });
        });
    });
</script>
@endsection
