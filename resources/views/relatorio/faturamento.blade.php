@extends('layouts.app')

@section('title', 'Faturamento')

@section('content')
<div
    x-data="faturamentoRelatorio()"
    class="space-y-6"
>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-blue-100 bg-blue-50 px-4 py-3 dark:border-blue-900 dark:bg-blue-950/30">
        <div class="text-sm text-blue-900 dark:text-blue-100">
            <span class="font-semibold">Período:</span> {{ $periodo['label'] }}
            @if ($periodo['modo'] === 'mes' && (int) ($periodo['ano'] ?? 0) === now()->year && (int) ($periodo['mes'] ?? 0) === now()->month)
                <span class="ml-1 text-xs text-blue-700 dark:text-blue-300">(padrão — mês atual)</span>
            @endif
        </div>
        @if ($periodo['modo'] !== 'todos')
            <a
                href="{{ route('relatorios.faturamento', array_merge(request()->except(['ano', 'mes', 'data_inicio', 'data_fim', 'page']), ['todos_periodos' => 1])) }}"
                class="text-xs font-semibold text-blue-700 underline hover:text-blue-900 dark:text-blue-300"
            >
                Ver todo o histórico
            </a>
        @else
            <a href="{{ route('relatorios.faturamento') }}" class="text-xs font-semibold text-blue-700 underline hover:text-blue-900 dark:text-blue-300">
                Voltar ao mês atual
            </a>
        @endif
    </div>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Total Transações</p>
            <div class="flex items-center justify-between">
                <span class="text-2xl font-bold text-gray-800">{{ number_format($totais->total_transacoes ?? 0, 0, ',', '.') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                    <i class="fa-solid fa-list text-sm"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Faturamento</p>
            <div class="flex items-center justify-between">
                <span class="text-2xl font-bold text-green-600">R$ {{ number_format($totais->total_valor ?? 0, 2, ',', '.') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100 text-green-600">
                    <i class="fa-solid fa-circle-check text-sm"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Comissões</p>
            <div class="flex items-center justify-between">
                <span class="text-2xl font-bold text-sky-600">R$ {{ number_format($totalRoyaltyExibido ?? 0, 2, ',', '.') }}</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-sky-100 text-sky-600">
                    <i class="fa-solid fa-hand-holding-dollar text-sm"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Pendentes</p>
            <div class="flex items-center justify-between">
                <span class="text-2xl font-bold text-yellow-600">0</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-yellow-100 text-yellow-600">
                    <i class="fa-regular fa-clock text-sm"></i>
                </div>
            </div>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Cancelados</p>
            <div class="flex items-center justify-between">
                <span class="text-2xl font-bold text-red-500">0</span>
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-red-100 text-red-500">
                    <i class="fa-solid fa-circle-xmark text-sm"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Faturamento encontrado</h3>
                <p class="text-xs text-gray-400">{{ $linhas->total() }} resultado(s) · clique na linha para ver detalhes</p>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded-lg bg-gray-100 px-3 py-2 text-xs font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">{{ $linhas->total() }} resultados</span>
                <button
                    type="button"
                    @click="filtrosAberto = true"
                    class="relative inline-flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                >
                    <i class="fa-solid fa-filter"></i>
                    Filtros
                    @if ($filtrosAtivos > 0)
                        <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 text-[10px] font-bold text-white">{{ $filtrosAtivos }}</span>
                    @endif
                </button>
            </div>
        </div>
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50">
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Data</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estabelecimento</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Instituição</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Transações</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Valor</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Comissão</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($linhas as $linha)
                    @php
                        $comissao = $linha->comissao_exibida ?? $linha->total_royalty;
                        $estabelecimentoNome = $linha->estabelecimento?->nome_fantasia
                            ?: $linha->estabelecimento?->razao_social
                            ?: $linha->estabelecimento?->nome_completo
                            ?: '—';
                        $marketplaceNome = $linha->marketplace?->nomeExibicao() ?? '—';
                    @endphp
                    <tr
                        class="cursor-pointer border-b border-gray-50 transition-colors hover:bg-blue-50/60"
                        @click="abrirDetalhe('{{ route('relatorios.faturamento.detalhe', $linha) }}')"
                    >
                        <td class="px-5 py-4 font-medium text-gray-800">{{ $linha->data?->format('d/m/Y') ?: '-' }}</td>
                        <td class="px-5 py-4">
                            <p class="max-w-[200px] truncate font-medium text-gray-800 dark:text-gray-100" title="{{ $estabelecimentoNome }}">{{ $estabelecimentoNome }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <p class="max-w-[160px] truncate text-gray-600 dark:text-gray-300" title="{{ $marketplaceNome }}">{{ $marketplaceNome }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <x-instituicao-icone :codigo="$linha->instituicao" size="lg" />
                        </td>
                        <td class="px-5 py-4">
                            <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium capitalize text-blue-700">{{ $linha->tipo_transacao ?: '-' }}</span>
                        </td>
                        <td class="px-5 py-4 text-gray-600">{{ $linha->total_transacoes }}</td>
                        <td class="px-5 py-4 font-semibold text-green-600">R$ {{ number_format($linha->total_valor, 2, ',', '.') }}</td>
                        <td class="px-5 py-4 font-semibold text-sky-600">R$ {{ number_format($comissao, 2, ',', '.') }}</td>
                        <td class="px-5 py-4 text-right text-gray-400">
                            <i class="fa-solid fa-chevron-right text-xs"></i>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-5 py-10 text-center text-sm text-gray-500">Nenhum faturamento encontrado para o filtro atual.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $linhas->links() }}</div>

    {{-- Drawer de filtros --}}
    <div x-show="filtrosAberto" x-cloak class="fixed inset-0 z-50" @keydown.escape.window="filtrosAberto = false">
        <div class="absolute inset-0 bg-gray-900/50" @click="filtrosAberto = false"></div>
        <aside
            class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl dark:bg-gray-900"
            x-show="filtrosAberto"
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
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Filtros</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Refine a busca de faturamento</p>
                </div>
                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800" @click="filtrosAberto = false">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <form method="GET" action="{{ route('relatorios.faturamento') }}" class="flex min-h-0 flex-1 flex-col">
                <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    <div class="rounded-lg border border-blue-100 bg-blue-50 p-3 dark:border-blue-900 dark:bg-blue-950/30">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-blue-800 dark:text-blue-200">Período</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label for="filtro-mes" class="mb-1 block text-xs text-gray-500">Mês</label>
                                <select id="filtro-mes" name="mes" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                    <option value="">Todos</option>
                                    @foreach (range(1, 12) as $mesOpcao)
                                        <option value="{{ $mesOpcao }}" @selected((int) ($filtros['mes'] ?? 0) === $mesOpcao)>
                                            {{ \Carbon\Carbon::createFromDate(now()->year, $mesOpcao, 1)->translatedFormat('F') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="filtro-ano" class="mb-1 block text-xs text-gray-500">Ano</label>
                                <select id="filtro-ano" name="ano" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                                    <option value="">Todos</option>
                                    @foreach (range(now()->year, now()->year - 5) as $anoOpcao)
                                        <option value="{{ $anoOpcao }}" @selected((int) ($filtros['ano'] ?? 0) === $anoOpcao)>{{ $anoOpcao }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <label class="mt-3 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" name="todos_periodos" value="1" class="rounded accent-blue-600" @checked($filtros['todos_periodos'] ?? false)>
                            Ver todo o histórico (sem filtro de período)
                        </label>
                        <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">Por padrão, abre só o mês atual. Use datas abaixo para intervalo customizado.</p>
                    </div>

                    <div>
                        <label for="filtro-estabelecimento" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Nome do estabelecimento</label>
                        <input
                            id="filtro-estabelecimento"
                            name="estabelecimento"
                            type="text"
                            value="{{ $filtros['estabelecimento'] ?? '' }}"
                            placeholder="Buscar por nome..."
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                        >
                    </div>

                    <div>
                        <label for="filtro-master" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Master</label>
                        <select id="filtro-master" name="master_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Todos</option>
                            @foreach ($masters as $master)
                                <option value="{{ $master['id'] }}" @selected(($filtros['master_id'] ?? '') == $master['id'])>{{ $master['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-marketplace" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</label>
                        <select id="filtro-marketplace" name="marketplace_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Todos</option>
                            @foreach ($marketplaces as $marketplace)
                                <option value="{{ $marketplace['id'] }}" @selected(($filtros['marketplace_id'] ?? '') == $marketplace['id'])>{{ $marketplace['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-revenda" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Revenda</label>
                        <select id="filtro-revenda" name="revenda_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Todos</option>
                            @foreach ($revendas as $revenda)
                                <option value="{{ $revenda['id'] }}" @selected(($filtros['revenda_id'] ?? '') == $revenda['id'])>{{ $revenda['nome'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-tipo" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Tipo de transação</label>
                        <select id="filtro-tipo" name="tipo_transacao" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Todos</option>
                            @foreach ($tiposTransacao as $tipo)
                                <option value="{{ $tipo }}" @selected(($filtros['tipo_transacao'] ?? '') === $tipo)>{{ ucfirst($tipo) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-instituicao" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Instituição</label>
                        <select id="filtro-instituicao" name="instituicao" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Todas</option>
                            @foreach ($instituicoes as $codigo)
                                <option value="{{ $codigo }}" @selected(($filtros['instituicao'] ?? '') === $codigo)>{{ \App\Support\InstituicaoFinanceira::nome($codigo) }} ({{ $codigo }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filtro-status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Status do pagamento</label>
                        <select id="filtro-status" name="status_pagamento" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                            <option value="">Todos</option>
                            <option value="03" @selected(($filtros['status_pagamento'] ?? '') === '03')>Concluído (03)</option>
                            <option value="01" @selected(($filtros['status_pagamento'] ?? '') === '01')>Novo (01)</option>
                            <option value="02" @selected(($filtros['status_pagamento'] ?? '') === '02')>Agendado (02)</option>
                            <option value="04" @selected(($filtros['status_pagamento'] ?? '') === '04')>Cancelado (04)</option>
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
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            >
                        </div>
                        <div>
                            <label for="filtro-data-fim" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Data final</label>
                            <input
                                id="filtro-data-fim"
                                name="data_fim"
                                type="date"
                                value="{{ $filtros['data_fim'] ?? '' }}"
                                class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                            >
                        </div>
                    </div>
                </div>

                <div class="flex gap-2 border-t border-gray-100 px-5 py-4 dark:border-gray-700">
                    <a
                        href="{{ route('relatorios.faturamento') }}"
                        class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-center text-sm font-semibold text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800"
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

    <div
        x-show="aberto"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @keydown.escape.window="fechar()"
    >
        <div class="absolute inset-0 bg-gray-900/50" @click="fechar()"></div>
        <div class="relative flex max-h-[90vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" @click.stop style="min-height: 0;">
            <div class="flex items-start justify-between border-b border-gray-100 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Detalhe do faturamento</h3>
                    <p class="text-sm text-gray-500" x-text="resumoTexto()"></p>
                </div>
                <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600" @click="fechar()">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-4">
                <p x-show="carregando" class="py-10 text-center text-sm text-gray-500">Carregando transações...</p>
                <p x-show="erro" x-text="erro" class="py-10 text-center text-sm text-red-600"></p>

                <div x-show="!carregando && !erro && dados">
                    <div class="mb-6 grid grid-cols-2 gap-3 rounded-xl bg-gray-50 p-4 text-sm md:grid-cols-4">
                        <div>
                            <p class="text-xs text-gray-500">Estabelecimento</p>
                            <p class="font-medium text-gray-800" x-text="dados?.resumo?.estabelecimento || '—'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Plano</p>
                            <p class="font-medium text-gray-800" x-text="dados?.resumo?.plano || '—'"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Faturamento</p>
                            <p class="font-semibold text-green-600" x-text="formatarMoeda(dados?.resumo?.total_valor)"></p>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500">Comissão</p>
                            <p class="font-semibold text-sky-600" x-text="formatarMoeda(dados?.resumo?.comissao)"></p>
                        </div>
                    </div>

                    <p class="mb-3 text-sm font-semibold text-gray-700">
                        Transações EDI (<span x-text="dados?.movimentos?.length || 0"></span>)
                    </p>

                    <p x-show="!dados?.movimentos?.length" class="mb-4 text-sm text-gray-500">Nenhuma transação encontrada para este agrupamento.</p>

                    <div x-show="dados?.movimentos?.length">
                        <template x-for="mov in dados.movimentos" :key="mov.id">
                            <div class="mb-6 rounded-xl border border-gray-200 p-4">
                                <div class="mb-4 flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 pb-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">Transação EDI</p>
                                        <p class="font-semibold text-gray-800" x-text="mov.codigo"></p>
                                    </div>
                                    <p class="text-lg font-bold text-green-600" x-text="formatarMoeda(mov.valor_total)"></p>
                                </div>

                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Dados completos do EDI</p>
                                <div class="mb-4 grid grid-cols-1 gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2 lg:grid-cols-3">
                                    <template x-for="campo in mov.campos_edi" :key="mov.id + '-' + campo.campo">
                                        <div class="min-w-0">
                                            <p class="text-xs text-gray-500" x-text="campo.rotulo"></p>
                                            <p class="break-all text-sm font-medium text-gray-800" x-text="campo.valor"></p>
                                        </div>
                                    </template>
                                </div>

                                <div x-show="mov.plano_taxa" class="mb-3 rounded-lg border border-blue-100 bg-blue-50/50 px-3 py-2 text-xs">
                                    <p class="font-semibold text-blue-800">Taxa do plano vinculada</p>
                                    <p class="text-blue-700">
                                        <span x-text="mov.plano_taxa?.instituicao"></span>
                                        · <span class="capitalize" x-text="mov.plano_taxa?.tipo_transacao"></span>
                                        · <span x-text="(mov.plano_taxa?.parcelas || 1) + 'x'"></span>
                                        · taxa <span x-text="(mov.plano_taxa?.taxa_percentual || 0) + '%'"></span>
                                        (<span x-text="mov.plano_taxa?.arranjo_ur"></span>)
                                    </p>
                                </div>
                                <p x-show="!mov.plano_taxa" class="mb-3 text-xs text-amber-700">Nenhuma taxa do plano encontrada para este arranjo/parcelas.</p>

                                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Comissões da transação</p>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="border-b border-gray-100 text-left text-gray-500">
                                                <th class="py-2 pr-3">Usuário</th>
                                                <th class="py-2 pr-3">Nível</th>
                                                <th class="py-2 pr-3">%</th>
                                                <th class="py-2">Valor</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="com in mov.comissoes" :key="mov.id + '-' + com.usuario + '-' + com.nivel">
                                                <tr class="border-b border-gray-50">
                                                    <td class="py-2 pr-3 font-medium text-gray-700" x-text="com.usuario"></td>
                                                    <td class="py-2 pr-3 capitalize text-gray-600" x-text="com.nivel"></td>
                                                    <td class="py-2 pr-3 text-gray-600" x-text="com.percentual + '%'"></td>
                                                    <td class="py-2 font-semibold text-sky-600" x-text="formatarMoeda(com.valor)"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function faturamentoRelatorio() {
    return {
        filtrosAberto: false,
        aberto: false,
        carregando: false,
        erro: null,
        dados: null,
        async abrirDetalhe(url) {
            this.aberto = true;
            this.carregando = true;
            this.erro = null;
            this.dados = null;
            try {
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) {
                    throw new Error('Não foi possível carregar os detalhes.');
                }
                this.dados = await response.json();
            } catch (e) {
                this.erro = e.message || 'Erro ao carregar detalhes.';
            } finally {
                this.carregando = false;
            }
        },
        fechar() {
            this.aberto = false;
            this.dados = null;
            this.erro = null;
        },
        resumoTexto() {
            if (!this.dados?.resumo) return '';
            const r = this.dados.resumo;
            return `${r.estabelecimento || '—'} · ${r.data} · ${r.instituicao} · ${r.tipo_transacao} · ${r.total_transacoes} transação(ões)`;
        },
        formatarMoeda(valor) {
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor) || 0);
        },
    };
}
</script>
@endsection
