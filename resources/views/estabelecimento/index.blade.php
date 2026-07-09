@extends('layouts.app')

@section('title', 'Estabelecimentos')

@section('content')
@php
    use App\Support\EstabelecimentoEtapaListagem;
    use App\Support\UsuarioComercial;

    $filtrosAtivos = collect($filtros ?? [])->filter(fn ($v) => $v !== null && $v !== '')->count();
    $podeCadastrarEstabelecimento = UsuarioComercial::podeCadastrarEstabelecimento();
@endphp

<div x-data="{ filtrosAberto: false }" class="space-y-6">
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-4 sm:px-5">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-gray-700">Estabelecimentos encontrados</h3>
                <p class="text-xs text-gray-400">{{ $estabelecimentos->total() }} resultado(s)</p>
            </div>
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto sm:justify-end">
                <form method="GET" action="{{ route('estabelecimentos.index') }}" class="flex min-w-0 flex-1 items-center gap-2 sm:max-w-md sm:flex-none">
                    @foreach ($filtros as $chave => $valor)
                        @if (! in_array($chave, ['busca', 'codigo_edi'], true) && $valor !== null && $valor !== '')
                            <input type="hidden" name="{{ $chave }}" value="{{ $valor }}">
                        @endif
                    @endforeach
                    @if (filled($filtros['codigo_edi'] ?? null))
                        <input type="hidden" name="codigo_edi" value="{{ $filtros['codigo_edi'] }}">
                    @endif
                    <div class="relative min-w-0 flex-1">
                        <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400"></i>
                        <input
                            type="search"
                            name="busca"
                            value="{{ $filtros['busca'] ?? '' }}"
                            placeholder="Nome, documento, cidade, marketplace, código EDI..."
                            class="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 pr-3 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                    </div>
                    <button type="submit" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                        Buscar
                    </button>
                </form>
                @if ($podeCadastrarEstabelecimento)
                    <a href="{{ route('estabelecimentos.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-blue-700">
                        <i class="fa-solid fa-plus text-xs"></i>
                        Novo Estabelecimento
                    </a>
                @endif
                <button
                    type="button"
                    @click="filtrosAberto = true"
                    class="relative inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                >
                    <i class="fa-solid fa-filter"></i>
                    Filtros
                    @if ($filtrosAtivos > 0)
                        <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 text-[10px] font-bold text-white">{{ $filtrosAtivos }}</span>
                    @endif
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[820px] text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50">
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Código</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Nome</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Documento</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Cadastro</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">PagBank</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Ações</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($estabelecimentos as $estabelecimento)
                    @php
                    $statusEtapa = EstabelecimentoEtapaListagem::statusEstabelecimento($estabelecimento);
                    [$statusClass, $statusLabel] = EstabelecimentoEtapaListagem::badge($statusEtapa);
                    $statusPagBank = EstabelecimentoEtapaListagem::statusPagBank($estabelecimento);
                    [$pagbankClass, $pagbankLabel] = EstabelecimentoEtapaListagem::badge($statusPagBank);
                    @endphp
                    <tr class="border-b border-gray-50 transition-colors hover:bg-gray-50">
                        <td class="px-5 py-4 font-semibold text-gray-800">#{{ str_pad($estabelecimento->id, 4, '0', STR_PAD_LEFT) }}</td>
                        <td class="px-5 py-4">
                            <p class="font-semibold text-gray-800">{{ $estabelecimento->nome_fantasia ?: $estabelecimento->razao_social ?: $estabelecimento->nome_completo }}</p>
                            <p class="mt-0.5 text-xs text-gray-400">
                                <span class="font-medium text-gray-500">Marketplace:</span>
                                {{ $estabelecimento->marketplace?->nomeExibicao() ?: '—' }}
                            </p>
                            <p class="text-xs text-gray-400">
                                <span class="font-medium text-gray-500">Revenda:</span>
                                {{ $estabelecimento->revenda?->nomeExibicao() ?: '—' }}
                            </p>
                        </td>
                        <td class="px-5 py-4 text-gray-600">{{ $estabelecimento->cnpj ?: $estabelecimento->cpf ?: '—' }}</td>
                        <td class="px-5 py-4 text-gray-600">{{ $estabelecimento->created_at?->format('d/m/Y') ?: '—' }}</td>
                        <td class="px-5 py-4">
                            <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                        </td>
                        <td class="px-5 py-4">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium {{ $pagbankClass }}">{{ $pagbankLabel }}</span>
                        </td>
                        <td class="px-5 py-4 text-right">
                            <a
                                href="{{ route('estabelecimentos.show', $estabelecimento) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 shadow-sm transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700"
                            >
                                <i class="fa-solid fa-circle-info text-blue-600"></i>
                                Detalhes
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-10 text-center text-sm text-gray-500">Nenhum estabelecimento encontrado para o filtro atual.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div>{{ $estabelecimentos->links() }}</div>

    {{-- Drawer de filtros --}}
    <div x-show="filtrosAberto" x-cloak class="fixed inset-0 z-50" @keydown.escape.window="filtrosAberto = false">
        <div class="absolute inset-0 bg-gray-900/50" @click="filtrosAberto = false"></div>
        <aside
            class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl"
            x-show="filtrosAberto"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @click.stop
        >
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Filtros</h3>
                    <p class="text-xs text-gray-500">Refine a listagem de estabelecimentos</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100" @click="filtrosAberto = false">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="GET" action="{{ route('estabelecimentos.index') }}" class="flex min-h-0 flex-1 flex-col">
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <div>
                        <label for="filtro-busca" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Pesquisar</label>
                        <input
                            id="filtro-busca"
                            name="busca"
                            type="search"
                            value="{{ $filtros['busca'] ?? '' }}"
                            placeholder="Nome, documento, cidade, marketplace, revenda, código EDI"
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-xs text-gray-400">Busca em nome, documento, cidade, e-mail, marketplace, revenda e código EDI.</p>
                    </div>

                    <div>
                        <label for="filtro-codigo-edi" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Código EDI (id_cliente)</label>
                        <input
                            id="filtro-codigo-edi"
                            name="codigo_edi"
                            type="search"
                            inputmode="numeric"
                            value="{{ $filtros['codigo_edi'] ?? '' }}"
                            placeholder="Ex.: 798179947"
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <p class="mt-1 text-xs text-gray-400">ID do estabelecimento no EDI PagSeguro (<code class="text-[10px]">token_pagseguro</code>).</p>
                    </div>

                    <div>
                        <label for="filtro-master" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Master</label>
                        <select id="filtro-master" name="master_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach ($masters as $master)
                                <option value="{{ $master['id'] }}" @selected(($filtros['master_id'] ?? '') == $master['id'])>{{ $master['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-marketplace" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</label>
                        <select id="filtro-marketplace" name="marketplace_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach ($marketplaces as $marketplace)
                                <option value="{{ $marketplace['id'] }}" @selected(($filtros['marketplace_id'] ?? '') == $marketplace['id'])>{{ $marketplace['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-revenda" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Revenda</label>
                        <select id="filtro-revenda" name="revenda_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach ($revendas as $revenda)
                                <option value="{{ $revenda['id'] }}" @selected(($filtros['revenda_id'] ?? '') == $revenda['id'])>{{ $revenda['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Status</label>
                        <select id="filtro-status" name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach (['pendente' => 'PENDENTE', 'aprovado' => 'APROVADO', 'negado' => 'NEGADO'] as $valor => $rotulo)
                                <option value="{{ $valor }}" @selected(($filtros['status'] ?? '') === $valor)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-pagbank" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">PagBank</label>
                        <select id="filtro-pagbank" name="pagbank" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach (['pendente' => 'PENDENTE', 'aprovado' => 'APROVADO', 'negado' => 'NEGADO'] as $valor => $rotulo)
                                <option value="{{ $valor }}" @selected(($filtros['pagbank'] ?? '') === $valor)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-risco" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Risco</label>
                        <select id="filtro-risco" name="risco" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach (['confiavel' => 'Confiável', 'atencao' => 'Atenção', 'bloqueado' => 'Bloqueado'] as $valor => $rotulo)
                                <option value="{{ $valor }}" @selected(($filtros['risco'] ?? '') === $valor)>{{ $rotulo }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-plano" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Plano</label>
                        <select id="filtro-plano" name="plano_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach ($planos as $plano)
                                <option value="{{ $plano->id }}" @selected(($filtros['plano_id'] ?? '') == $plano->id)>{{ $plano->nome }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-segmento" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Segmento</label>
                        <select id="filtro-segmento" name="segmento" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            @foreach ($segmentos as $segmento)
                                <option value="{{ $segmento->nome }}" @selected(($filtros['segmento'] ?? '') === $segmento->nome)>{{ $segmento->nome }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-pessoa" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo de pessoa</label>
                        <select id="filtro-pessoa" name="pessoa_tipo" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            <option value="juridica" @selected(($filtros['pessoa_tipo'] ?? '') === 'juridica')>Pessoa jurídica</option>
                            <option value="fisica" @selected(($filtros['pessoa_tipo'] ?? '') === 'fisica')>Pessoa física</option>
                        </select>
                    </div>

                    <div>
                        <label for="filtro-ativo" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Cadastro ativo</label>
                        <select id="filtro-ativo" name="ativo" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Todos</option>
                            <option value="1" @selected(($filtros['ativo'] ?? '') === '1' || ($filtros['ativo'] ?? '') === 1 || ($filtros['ativo'] ?? '') === true)>Sim</option>
                            <option value="0" @selected(($filtros['ativo'] ?? '') === '0' || ($filtros['ativo'] ?? '') === 0 || ($filtros['ativo'] ?? '') === false)>Não</option>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="filtro-data-inicio" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Data inicial</label>
                            <input
                                id="filtro-data-inicio"
                                name="data_inicio"
                                type="date"
                                value="{{ $filtros['data_inicio'] ?? '' }}"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                        </div>
                        <div>
                            <label for="filtro-data-fim" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Data final</label>
                            <input
                                id="filtro-data-fim"
                                name="data_fim"
                                type="date"
                                value="{{ $filtros['data_fim'] ?? '' }}"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            >
                        </div>
                    </div>
                    <p class="text-xs text-gray-400">Período com base na data de cadastro do estabelecimento.</p>
                </div>

                <div class="flex gap-2 border-t border-gray-100 px-5 py-4">
                    <a
                        href="{{ route('estabelecimentos.index') }}"
                        class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-center text-sm font-semibold text-gray-600 transition hover:bg-gray-50"
                    >
                        Limpar
                    </a>
                    <button type="submit" class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700">
                        Aplicar filtros
                    </button>
                </div>
            </form>
        </aside>
    </div>
</div>
@endsection
