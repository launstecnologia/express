@extends('layouts.app')

@section('title', 'Transações por marketplace')

@section('content')
<div class="mb-5">
    <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Transações por marketplace</h1>
    <p class="mt-1 text-sm text-gray-500">Selecione o marketplace e o período para ver quais estabelecimentos tiveram venda no EDI (incluindo maquininha) e baixar o Excel. Tx 0 no período não significa que nunca houve venda: confira também o histórico no banco e as transações ligadas ao Safepay ID.</p>
</div>

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $errors->first() }}
    </div>
@endif

<form method="GET" action="{{ route('admin.relatorios.estabelecimentos-transacoes') }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</span>
            <select name="marketplace_id" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">Selecione</option>
                @foreach ($marketplaces as $item)
                    <option value="{{ $item['id'] }}" @selected((int) ($filtros['marketplace_id'] ?? 0) === (int) $item['id'])>{{ $item['nome'] }}</option>
                @endforeach
            </select>
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">De</span>
            <input type="date" name="de" value="{{ $filtros['de'] }}" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Até</span>
            <input type="date" name="ate" value="{{ $filtros['ate'] }}" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Exibir</span>
            <select name="filtro" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="todos" @selected($filtros['filtro'] === 'todos')>Todos</option>
                <option value="com" @selected($filtros['filtro'] === 'com')>Só com transação</option>
                <option value="sem" @selected($filtros['filtro'] === 'sem')>Só sem transação</option>
            </select>
        </label>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
            <i class="fa-solid fa-magnifying-glass"></i>
            Consultar
        </button>
        @if ($marketplace)
            <button
                type="submit"
                form="form-excel"
                class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                <i class="fa-solid fa-file-excel"></i>
                Baixar Excel
            </button>
        @endif
    </div>
</form>

@if ($marketplace)
    <form id="form-excel" method="GET" action="{{ route('admin.relatorios.estabelecimentos-transacoes.excel') }}" class="hidden">
        <input type="hidden" name="marketplace_id" value="{{ $marketplace->id }}">
        <input type="hidden" name="de" value="{{ $filtros['de'] }}">
        <input type="hidden" name="ate" value="{{ $filtros['ate'] }}">
        <input type="hidden" name="filtro" value="{{ $filtros['filtro'] }}">
    </form>

    @if ($resumo)
        <div class="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">Estabelecimentos</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($resumo['total'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">Com tx no período</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-emerald-600">{{ number_format($resumo['com_transacao'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">Sem tx no período</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-amber-600">{{ number_format($resumo['sem_transacao'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">Com histórico no banco</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($resumo['com_historico'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">Com tx pelo Safepay ID</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($resumo['com_token_edi'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">TPV no período</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">R$ {{ number_format($resumo['tpv'], 2, ',', '.') }}</p>
            </div>
        </div>
        <p class="mb-3 text-xs text-gray-500">
            Sem Safepay ID: {{ number_format($resumo['sem_token'], 0, ',', '.') }}
            · Sem transação no período com token: {{ number_format($resumo['sem_transacao_com_token'], 0, ',', '.') }}
            · Terminal: {{ number_format($resumo['qtd_terminal'], 0, ',', '.') }} de {{ number_format($resumo['qtd_transacoes'], 0, ',', '.') }} transações no período
        </p>
    @endif

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1280px] text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">ID</th>
                        <th class="px-4 py-3">Nome</th>
                        <th class="px-4 py-3">Cadastro</th>
                        <th class="px-4 py-3">Token</th>
                        <th class="px-4 py-3 text-right">Tx período</th>
                        <th class="px-4 py-3 text-right">TPV período</th>
                        <th class="px-4 py-3 text-right">Tx histórico</th>
                        <th class="px-4 py-3 text-right">Tx Safepay</th>
                        <th class="px-4 py-3">Última no banco</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($rows as $r)
                        @php
                            $ultimaBanco = $r->ultima_historico ?? $r->ultima_token ?? $r->ultima_venda ?? null;
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $r->id }}</td>
                            <td class="px-4 py-3 font-semibold text-gray-800 dark:text-gray-100">{{ $relatorio->nome($r) }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $relatorio->data($r->created_at) ?: '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $r->token_pagseguro ?: '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) $r->qtd_transacoes, 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-semibold">R$ {{ number_format((float) $r->tpv, 2, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) ($r->qtd_historico ?? 0), 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int) ($r->qtd_token ?? 0), 0, ',', '.') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $relatorio->data($ultimaBanco) ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-gray-500">Nenhum estabelecimento neste filtro.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
