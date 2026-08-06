@extends('layouts.app')

@php
    use App\Support\UsuarioComercial;

    $tipoLabel = [
        'admin' => 'Administrador',
        'master' => 'Master',
        'marketplace' => 'Marketplace',
        'revenda' => 'Revenda',
    ];
    $tipoPlural = [
        'master' => 'Masters',
        'marketplace' => 'Marketplaces',
        'revenda' => 'Revendas',
    ];
    $tipoAtual = old('tipo', $tipoFixo ?: ($usuario->exists ? $usuario->tipo : ($niveis[0] ?? null)));
    $tituloTipo = $tipoAtual === 'admin' ? 'Admin' : ($tipoLabel[$tipoAtual] ?? 'Usuário');
@endphp

@section('title', $usuario->exists ? 'Editar '.$tituloTipo : 'Novo '.$tituloTipo)

@section('content')
@php
    $inputClass = 'w-full rounded border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 shadow-sm placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    $selectClass = 'w-full rounded border border-slate-300 bg-white px-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    $labelClass = 'space-y-1';
    $labelTextClass = 'text-[11px] font-semibold text-slate-800';
    $sectionTitleClass = 'border-b border-slate-200 px-3 py-4 text-base font-semibold text-slate-600';
    $dateValue = fn (string $field) => old($field, optional($usuario->{$field})->format('Y-m-d'));
    $pessoaTipo = $tipoAtual === 'admin' ? 'fisica' : old('pessoa_tipo', $usuario->pessoa_tipo ?: 'juridica');
    $segmentoSelecionado = old('segmento', $usuario->segmento);
    $paiAtual = old('pai_id', $paiSelecionado?->id ?: $usuario->hierarquia?->pai?->usuario_id);
    $exibeRetencaoPai = UsuarioComercial::podeDefinirRetencaoPai($tipoAtual);
    $rotuloRetencaoPai = match ($tipoAtual) {
        'marketplace' => UsuarioComercial::ehMaster()
            ? 'Retenção do Master (% sobre a comissão do marketplace)'
            : 'Retenção do Admin (% sobre a comissão do marketplace)',
        'revenda' => 'Participação da revenda (% sobre a comissão líquida do marketplace, após royalty do admin)',
        default => 'Retenção do pai (%)',
    };
@endphp

<form method="POST" action="{{ $usuario->exists ? route('usuarios.update', $usuario) : route('usuarios.store') }}" class="overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm">
    @csrf
    @if ($usuario->exists) @method('PUT') @endif

    <div class="flex min-h-32 items-start justify-between px-3 py-5">
        <label class="{{ $labelClass }} mt-12 w-48">
            <span class="{{ $labelTextClass }}">ID</span>
            <input value="{{ $usuario->exists ? $usuario->id : '' }}" readonly class="{{ $inputClass }} bg-slate-50">
        </label>

        @if ($tipoAtual !== 'admin')
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
    @else
        <input type="hidden" name="pessoa_tipo" value="fisica">
    @endif
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

    @if ($tipoAtual === 'admin')
        <input type="hidden" name="tipo" value="admin">
        <div class="px-3 py-4">
            <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                <input type="hidden" name="ativo" value="0">
                <input type="checkbox" name="ativo" value="1" class="h-4 w-4 rounded accent-blue-600" @checked(old('ativo', $usuario->ativo ?? true))>
                Ativo
            </label>
        </div>
    @else
        @if ($tipoAtual !== 'master')
        <h2 class="{{ $sectionTitleClass }}">Acesso e hierarquia</h2>
        @endif
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            @if ($tipoFixo)
                <input type="hidden" name="tipo" value="{{ $tipoFixo }}">
            @else
                <label class="{{ $labelClass }} md:col-span-3">
                    <span class="{{ $labelTextClass }}">Nível</span>
                    <select name="tipo" class="{{ $selectClass }}">
                        @foreach ($niveis as $nivel)
                            <option value="{{ $nivel }}" @selected($tipoAtual === $nivel)>{{ $tipoLabel[$nivel] ?? ucfirst($nivel) }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($tipoAtual !== 'master')
                <label class="{{ $labelClass }} {{ $tipoFixo ? 'md:col-span-6' : 'md:col-span-5' }}">
                    <span class="{{ $labelTextClass }}">
                        @if ($tipoAtual === 'marketplace') Master responsável
                        @elseif ($tipoAtual === 'revenda') Marketplace responsável
                        @else Pai hierárquico
                        @endif
                    </span>
                    <select name="pai_id" class="{{ $selectClass }}">
                        <option value="">Selecione</option>
                        @foreach ($pais as $pai)
                            <option value="{{ $pai->id }}" @selected((int) $paiAtual === $pai->id)>{{ $pai->nomeExibicao() }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @if ($exibeRetencaoPai)
                <label class="{{ $labelClass }} md:col-span-3">
                    <span class="{{ $labelTextClass }}">Retenção (%)</span>
                    <input
                        name="percentual_retencao_pai"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value="{{ old('percentual_retencao_pai', $usuario->percentual_retencao_pai) }}"
                        placeholder="Ex.: 20"
                        @required(! $usuario->exists)
                        class="{{ $inputClass }}"
                    >
                    <p class="mt-1 text-[10px] text-slate-500">{{ $rotuloRetencaoPai }}</p>
                </label>
            @endif

            <label class="flex items-end gap-2 pb-2 text-xs font-semibold text-slate-700 md:col-span-3">
                <input type="hidden" name="ativo" value="0">
                <input type="checkbox" name="ativo" value="1" class="h-4 w-4 rounded accent-blue-600" @checked(old('ativo', $usuario->ativo ?? true))>
                Ativo
            </label>
        </div>
    @endif

    <div data-pessoa-section="juridica" class="{{ ($tipoAtual === 'admin' || $pessoaTipo === 'fisica') ? 'hidden' : '' }}">
        <h2 class="{{ $sectionTitleClass }}">Dados Empresariais</h2>
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            <label class="{{ $labelClass }} md:col-span-4">
                <span class="{{ $labelTextClass }}">CNPJ</span>
                <div class="flex">
                    <input data-autofill="cnpj" name="cnpj" value="{{ old('cnpj', $usuario->cnpj) }}" class="{{ $inputClass }} rounded-r-none">
                    <button type="button" data-action="buscar-cnpj" class="w-9 rounded-r border border-l-0 border-teal-400 text-teal-600 hover:bg-teal-50">
                        <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                    </button>
                </div>
                <span data-status="cnpj" class="block min-h-4 text-[11px] text-slate-400"></span>
            </label>
            <label class="{{ $labelClass }} md:col-span-6">
                <span class="{{ $labelTextClass }}">Razão Social</span>
                <input data-autofill="razao_social" name="razao_social" value="{{ old('razao_social', $usuario->razao_social) }}" placeholder="Razão Social" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-2">
                <span class="{{ $labelTextClass }}">Ins. Est.</span>
                <input data-autofill="inscricao_estadual" name="inscricao_estadual" value="{{ old('inscricao_estadual', $usuario->inscricao_estadual) }}" placeholder="Ins. Est." class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-9">
                <span class="{{ $labelTextClass }}">Nome Fantasia</span>
                <input data-autofill="nome_fantasia" name="nome_fantasia" value="{{ old('nome_fantasia', $usuario->nome_fantasia) }}" placeholder="Nome Fantasia" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">Data de Abertura</span>
                <input data-autofill="data_abertura" type="date" name="data_abertura" value="{{ $dateValue('data_abertura') }}" class="{{ $inputClass }}">
            </label>
        </div>

        <h2 class="{{ $sectionTitleClass }}">Representante Legal</h2>
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            <label class="{{ $labelClass }} md:col-span-6">
                <span class="{{ $labelTextClass }}">Nome Completo</span>
                <input data-autofill="rep_nome" name="rep_nome" value="{{ old('rep_nome', $usuario->rep_nome) }}" placeholder="Nome Completo" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">CPF</span>
                <input name="rep_cpf" value="{{ old('rep_cpf', $usuario->rep_cpf) }}" placeholder="CPF" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">Data de Nascimento</span>
                <input type="date" name="rep_data_nascimento" value="{{ $dateValue('rep_data_nascimento') }}" class="{{ $inputClass }}">
            </label>
        </div>
    </div>

    <div data-pessoa-section="fisica" class="{{ ($tipoAtual !== 'admin' && $pessoaTipo === 'juridica') ? 'hidden' : '' }}">
        <h2 class="{{ $sectionTitleClass }}">{{ $tipoAtual === 'admin' ? 'Dados pessoais' : 'Pessoa Física' }}</h2>
        <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
            <label class="{{ $labelClass }} md:col-span-6">
                <span class="{{ $labelTextClass }}">Nome Completo</span>
                <input name="nome_completo" value="{{ old('nome_completo', $usuario->nome_completo) }}" placeholder="Nome Completo" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">CPF</span>
                <input name="cpf" value="{{ old('cpf', $usuario->cpf) }}" placeholder="CPF" class="{{ $inputClass }}">
            </label>
            <label class="{{ $labelClass }} md:col-span-3">
                <span class="{{ $labelTextClass }}">Data de Nascimento</span>
                <input type="date" name="data_nascimento" value="{{ $dateValue('data_nascimento') }}" class="{{ $inputClass }}">
            </label>
            @if ($tipoAtual !== 'admin')
            <label class="{{ $labelClass }} md:col-span-12">
                <span class="{{ $labelTextClass }}">Nome Fantasia</span>
                <input name="nome_fantasia" value="{{ old('nome_fantasia', $usuario->nome_fantasia) }}" placeholder="Nome Fantasia" class="{{ $inputClass }}">
            </label>
            @endif
        </div>
    </div>

    <h2 class="{{ $sectionTitleClass }}">Endereço</h2>
    <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
        <label class="{{ $labelClass }} md:col-span-3">
            <span class="{{ $labelTextClass }}">CEP</span>
            <div class="flex">
                <input data-autofill="cep" name="cep" value="{{ old('cep', $usuario->cep) }}" class="{{ $inputClass }} rounded-r-none">
                <button type="button" data-action="buscar-cep" class="w-9 rounded-r border border-l-0 border-teal-400 text-teal-600 hover:bg-teal-50">
                    <i class="fa-solid fa-magnifying-glass text-[10px]"></i>
                </button>
            </div>
            <span data-status="cep" class="block min-h-4 text-[11px] text-slate-400"></span>
        </label>
        <div class="hidden md:col-span-9 md:block"></div>
        <label class="{{ $labelClass }} md:col-span-6">
            <span class="{{ $labelTextClass }}">Endereço</span>
            <input data-autofill="endereco" name="endereco" value="{{ old('endereco', $usuario->endereco) }}" placeholder="Endereço" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-2">
            <span class="{{ $labelTextClass }}">Número</span>
            <input data-autofill="numero" name="numero" value="{{ old('numero', $usuario->numero) }}" placeholder="Número" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">Complemento</span>
            <input data-autofill="complemento" name="complemento" value="{{ old('complemento', $usuario->complemento) }}" placeholder="Complemento" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-5">
            <span class="{{ $labelTextClass }}">Bairro</span>
            <input data-autofill="bairro" name="bairro" value="{{ old('bairro', $usuario->bairro) }}" placeholder="Bairro" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-6">
            <span class="{{ $labelTextClass }}">Cidade</span>
            <input data-autofill="cidade" name="cidade" value="{{ old('cidade', $usuario->cidade) }}" placeholder="Cidade" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-1">
            <span class="{{ $labelTextClass }}">UF</span>
            <input data-autofill="uf" name="uf" value="{{ old('uf', $usuario->uf) }}" maxlength="2" placeholder="UF" class="{{ $inputClass }} uppercase">
        </label>
    </div>

    <h2 class="{{ $sectionTitleClass }}">Dados de contato</h2>
    <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
        <label class="{{ $labelClass }} md:col-span-3">
            <span class="{{ $labelTextClass }}">Telefone</span>
            <input data-autofill="telefone" name="telefone" value="{{ old('telefone', $usuario->telefone) }}" placeholder="(DDD) 0000-000" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-3">
            <span class="{{ $labelTextClass }}">Celular</span>
            <input name="celular" value="{{ old('celular', $usuario->celular) }}" placeholder="(DDD) 0000-000" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-6">
            <span class="{{ $labelTextClass }}">E-mail</span>
            <input data-autofill="email" type="email" name="email" value="{{ old('email', $usuario->email) }}" placeholder="exemplo@gmail.com" class="{{ $inputClass }}">
        </label>
    </div>

    @if ($tipoAtual === 'marketplace' && UsuarioComercial::podeLiberarPlanosMarketplace($usuario->exists ? $usuario : null) && ($todosPlanos ?? collect())->isNotEmpty())
        @php
            $planosSelecionados = collect(old('planos_habilitados', $usuario->exists ? $usuario->planosHabilitados->pluck('id')->all() : []))->map(fn ($id) => (int) $id);
        @endphp
        <h2 class="{{ $sectionTitleClass }}">Planos e taxas disponíveis</h2>
        <div class="px-3 py-4">
            <p class="mb-3 text-[11px] text-slate-500">Selecione quais planos este marketplace poderá usar ao cadastrar estabelecimentos. A revenda do marketplace herda automaticamente esses planos.</p>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($todosPlanos as $plano)
                    <label class="flex items-center gap-2 rounded border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700">
                        <input
                            type="checkbox"
                            name="planos_habilitados[]"
                            value="{{ $plano->id }}"
                            class="h-4 w-4 rounded accent-blue-600"
                            @checked($planosSelecionados->contains($plano->id))
                        >
                        {{ $plano->nome }}
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex justify-end gap-3 px-3 pb-3 pt-4">
        <a href="{{ $usuario->exists ? route('usuarios.show', $usuario) : route('usuarios.index', $tipoFixo ? ['tipo' => $tipoFixo] : []) }}" class="rounded border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 shadow-sm hover:bg-slate-50">Cancelar</a>
        <button class="rounded bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700">
            {{ $usuario->exists ? 'Atualizar' : 'Registrar' }}
        </button>
    </div>
</form>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const onlyDigits = (value) => (value || '').replace(/\D/g, '');
        const field = (name) => document.querySelector(`[data-autofill="${name}"]`);
        const status = (name) => document.querySelector(`[data-status="${name}"]`);

        const setStatus = (name, message, type = 'info') => {
            const element = status(name);
            if (!element) return;

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
            if (!element || value === undefined || value === null || value === '') return;
            if (!overwrite && element.value.trim() !== '') return;

            if (element.tagName === 'SELECT' && !Array.from(element.options).some((option) => option.value === value)) {
                element.add(new Option(value, value));
            }

            element.value = value;
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const normalizeDate = (value) => {
            if (!value) return '';
            if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value;

            const match = value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
            return match ? `${match[3]}-${match[2]}-${match[1]}` : '';
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
                if (!response.ok) throw new Error(data.message || 'CNPJ não encontrado.');

                setValue('razao_social', data.razao_social, overwrite);
                setValue('nome_fantasia', data.nome_fantasia || data.razao_social, overwrite);
                setValue('segmento', data.cnae_fiscal_descricao, overwrite);
                setValue('data_abertura', normalizeDate(data.data_inicio_atividade), overwrite);
                setValue('email', data.email, overwrite);
                setValue('telefone', formatPhone(data.ddd_telefone_1, data.telefone_1), overwrite);
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

        const pessoaTipoInicial = document.querySelector('input[name="pessoa_tipo"]:checked')?.value
            ?? document.querySelector('input[name="pessoa_tipo"]')?.value
            ?? 'juridica';
        syncPessoaTipo(pessoaTipoInicial);
    });
</script>
@endsection
