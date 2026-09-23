@extends('layouts.app')

@section('title', 'Pendências de estabelecimentos')

@section('content')
@php
    use App\Support\EstabelecimentoEtapaListagem;

    $pendenciaAtual = $filtros['pendencia'] ?? null;
    $queryBase = request()->except(['pendencia', 'page']);
@endphp

<div class="mb-5">
    <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Estabelecimentos com pendência</h1>
    <p class="mt-1 text-sm text-gray-500">Lista ECs ativos sem plano, sem ID PagSeguro ou sem nenhuma transação no EDI. Clique em um card para filtrar.</p>
</div>

<form method="GET" action="{{ route('admin.relatorios.estabelecimento-pendencias') }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    @if ($pendenciaAtual)
        <input type="hidden" name="pendencia" value="{{ $pendenciaAtual }}">
    @endif
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <label class="block space-y-1 lg:col-span-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Busca</span>
            <input
                type="text"
                name="busca"
                value="{{ $filtros['busca'] }}"
                placeholder="Nome, CNPJ, CPF ou ID PagSeguro"
                class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</span>
            <select name="marketplace_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">Todos</option>
                @foreach ($marketplaces as $marketplace)
                    <option value="{{ $marketplace['id'] }}" @selected((int) ($filtros['marketplace_id'] ?? 0) === (int) $marketplace['id'])>{{ $marketplace['nome'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Status</span>
            <select name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">Todos</option>
                <option value="pendente" @selected(($filtros['status'] ?? '') === 'pendente')>Pendente</option>
                <option value="aprovado" @selected(($filtros['status'] ?? '') === 'aprovado')>Aprovado</option>
                <option value="negado" @selected(($filtros['status'] ?? '') === 'negado')>Negado</option>
            </select>
        </label>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
            <i class="fa-solid fa-magnifying-glass"></i>
            Filtrar
        </button>
        <a href="{{ route('admin.relatorios.estabelecimento-pendencias') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
            Limpar
        </a>
        @if ($linhas->total() > 0)
            <a
                href="{{ route('admin.relatorios.estabelecimento-pendencias.excel', request()->query()) }}"
                class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                <i class="fa-solid fa-file-excel"></i>
                Baixar Excel
            </a>
        @endif
    </div>
</form>

<div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
    @php
        $cards = [
            ['chave' => null, 'label' => 'Com alguma pendência', 'valor' => $contagens['total'], 'icone' => 'fa-triangle-exclamation', 'cor' => 'text-gray-800 dark:text-gray-100', 'fundo' => 'bg-gray-50 dark:bg-gray-800', 'borda' => 'border-gray-200 dark:border-gray-700'],
            ['chave' => 'sem_plano', 'label' => 'Sem plano', 'valor' => $contagens['sem_plano'], 'icone' => 'fa-credit-card', 'cor' => 'text-amber-700 dark:text-amber-300', 'fundo' => 'bg-amber-50 dark:bg-amber-950/30', 'borda' => 'border-amber-200 dark:border-amber-900'],
            ['chave' => 'sem_id', 'label' => 'Sem ID PagSeguro', 'valor' => $contagens['sem_id'], 'icone' => 'fa-fingerprint', 'cor' => 'text-orange-700 dark:text-orange-300', 'fundo' => 'bg-orange-50 dark:bg-orange-950/30', 'borda' => 'border-orange-200 dark:border-orange-900'],
            ['chave' => 'sem_transacao', 'label' => 'Sem transação', 'valor' => $contagens['sem_transacao'], 'icone' => 'fa-receipt', 'cor' => 'text-sky-700 dark:text-sky-300', 'fundo' => 'bg-sky-50 dark:bg-sky-950/30', 'borda' => 'border-sky-200 dark:border-sky-900'],
        ];
    @endphp
    @foreach ($cards as $card)
        @php
            $ativo = $pendenciaAtual === $card['chave'];
            $url = route('admin.relatorios.estabelecimento-pendencias', array_filter(array_merge($queryBase, ['pendencia' => $card['chave']]), fn ($v) => $v !== null && $v !== ''));
        @endphp
        <a href="{{ $url }}" class="rounded-xl border {{ $card['borda'] }} {{ $card['fundo'] }} p-4 shadow-sm transition hover:shadow {{ $ativo ? 'ring-2 ring-blue-500' : '' }}">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $card['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $card['cor'] }}">{{ number_format($card['valor'], 0, ',', '.') }}</p>
                </div>
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-white text-gray-500 shadow-sm dark:bg-gray-900 {{ $card['cor'] }}">
                    <i class="fa-solid {{ $card['icone'] }}"></i>
                </span>
            </div>
        </a>
    @endforeach
</div>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700">
        <div>
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Estabelecimentos encontrados</h3>
            <p class="text-xs text-gray-400">{{ $linhas->total() }} resultado(s) · clique na linha para abrir o cadastro</p>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-800">
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Estabelecimento</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Plano</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">ID PagSeguro</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Pendências</th>
                    <th class="px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($linhas as $ec)
                    @php
                        $semTransacao = ! $relatorio->temTransacao($ec);
                        $pendencias = $relatorio->pendenciasDaLinha($ec, $semTransacao);
                        [$statusClass, $statusLabel] = EstabelecimentoEtapaListagem::badge(EstabelecimentoEtapaListagem::statusEstabelecimento($ec));
                    @endphp
                    <tr
                        class="cursor-pointer border-b border-gray-50 transition-colors hover:bg-blue-50/60 dark:border-gray-800 dark:hover:bg-gray-800/80"
                        onclick="window.location='{{ route('estabelecimentos.show', $ec) }}'"
                    >
                        <td class="px-5 py-4">
                            <p class="max-w-[260px] truncate font-medium text-gray-800 dark:text-gray-100" title="{{ $relatorio->nome($ec) }}">{{ $relatorio->nome($ec) }}</p>
                            <p class="mt-0.5 text-xs tabular-nums text-gray-400">{{ $relatorio->documento($ec) }} · #{{ $ec->id }}</p>
                            <p class="mt-0.5 max-w-[260px] truncate text-xs text-gray-400">
                                {{ $ec->marketplace?->nomeExibicao() ?: 'Sem marketplace' }}
                                @if ($ec->revenda)
                                    · {{ $ec->revenda->nomeExibicao() }}
                                @endif
                            </p>
                        </td>
                        <td class="px-5 py-4">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClass }}">{{ $statusLabel }}</span>
                        </td>
                        <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                            @if ($relatorio->semPlano($ec))
                                <span class="font-semibold text-amber-700">—</span>
                            @else
                                {{ $ec->plano?->nome ?: '—' }}
                            @endif
                        </td>
                        <td class="px-5 py-4 font-mono text-xs text-gray-600 dark:text-gray-300">
                            @if ($relatorio->semId($ec))
                                <span class="font-semibold text-orange-700">—</span>
                            @else
                                {{ $ec->token_pagseguro }}
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex flex-wrap gap-1">
                                @foreach ($pendencias as $item)
                                    <span class="rounded-full bg-red-50 px-2 py-0.5 text-[11px] font-semibold text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ $item }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-5 py-4 text-right text-gray-400">
                            <i class="fa-solid fa-chevron-right text-xs"></i>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-10 text-center text-sm text-gray-500">Nenhum estabelecimento com essas pendências.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4">{{ $linhas->links() }}</div>
@endsection
