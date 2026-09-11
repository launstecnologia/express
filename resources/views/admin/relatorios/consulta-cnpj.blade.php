@extends('layouts.app')

@section('title', 'Consulta por CNPJ')

@section('content')
@php
    $qtdTransacoes = (int) ($totais->total_transacoes ?? 0);
    $valorTotal = (float) ($totais->valor_total ?? 0);
    $valorLiquido = (float) ($totais->valor_liquido ?? 0);
    $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
@endphp

<div class="mb-5">
    <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Consulta de transações por CNPJ</h1>
    <p class="mt-1 text-sm text-gray-500">Informe o CNPJ e o mês para listar todas as transações EDI do estabelecimento e exportar em Excel.</p>
</div>

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $errors->first() }}
    </div>
@endif

<form method="GET" action="{{ route('admin.relatorios.consulta-cnpj') }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <label class="block space-y-1 lg:col-span-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">CNPJ</span>
            <input
                type="text"
                name="cnpj"
                value="{{ $filtros['cnpj'] }}"
                required
                maxlength="18"
                inputmode="numeric"
                placeholder="00.000.000/0000-00"
                class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
            >
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mês</span>
            <select name="mes_numero" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                @foreach ($meses as $numero => $nomeMes)
                    <option value="{{ $numero }}" @selected((int) $filtros['mes_numero'] === $numero)>{{ $nomeMes }}</option>
                @endforeach
            </select>
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ano</span>
            <input type="number" name="ano" value="{{ $filtros['ano'] }}" min="2020" max="2100" inputmode="numeric" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
        </label>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
            <i class="fa-solid fa-magnifying-glass"></i>
            Consultar
        </button>
        @if ($consultou && $estabelecimentos->isNotEmpty() && $qtdTransacoes > 0)
            <button
                type="submit"
                form="form-excel"
                class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200"
            >
                <i class="fa-solid fa-file-excel"></i>
                Baixar Excel
            </button>
        @endif
        @if ($consultou)
            <a href="{{ route('admin.relatorios.consulta-cnpj') }}" class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-4 py-2.5 text-sm font-semibold text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300">
                <i class="fa-solid fa-rotate-left"></i>
                Limpar
            </a>
        @endif
    </div>
</form>

@if ($consultou && $estabelecimentos->isNotEmpty() && $qtdTransacoes > 0)
    <form id="form-excel" method="GET" action="{{ route('admin.relatorios.consulta-cnpj.excel') }}" class="hidden">
        <input type="hidden" name="cnpj" value="{{ $filtros['cnpj'] }}">
        <input type="hidden" name="mes_numero" value="{{ $filtros['mes_numero'] }}">
        <input type="hidden" name="ano" value="{{ $filtros['ano'] }}">
    </form>
@endif

@if ($consultou && $estabelecimentos->isEmpty())
    <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
        Nenhum estabelecimento encontrado com esse documento.
    </div>
@elseif ($consultou)
    <div class="mb-6 space-y-3">
        @foreach ($estabelecimentos as $ec)
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $consulta->nomeEstabelecimento($ec) }}</p>
                        <p class="mt-1 text-xs text-gray-500">
                            ID {{ $ec->id }}
                            · {{ $ec->cnpj ?: $ec->cpf ?: 'sem documento' }}
                            @if ($ec->marketplace)
                                · Mkt: {{ $ec->marketplace->nomeExibicao() }}
                            @endif
                            @if ($ec->revenda)
                                · Revenda: {{ $ec->revenda->nomeExibicao() }}
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('estabelecimentos.show', $ec) }}" class="text-xs font-semibold text-blue-600 hover:underline">Abrir cadastro</a>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mb-6 grid grid-cols-1 gap-3 md:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-medium text-gray-500">Transações</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($qtdTransacoes, 0, ',', '.') }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-medium text-gray-500">Valor total</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">R$ {{ number_format($valorTotal, 2, ',', '.') }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="text-xs font-medium text-gray-500">Valor líquido</p>
            <p class="mt-1 text-xl font-bold tabular-nums text-gray-800 dark:text-gray-100">R$ {{ number_format($valorLiquido, 2, ',', '.') }}</p>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-gray-800">
            <div>
                <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Transações do mês</h2>
                <p class="mt-0.5 text-xs text-gray-500">{{ \Carbon\Carbon::parse($filtros['inicio'])->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($filtros['fim'])->format('d/m/Y') }}</p>
            </div>
            <p class="rounded-full bg-gray-100 px-3 py-1 text-xs font-bold text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ number_format($qtdTransacoes, 0, ',', '.') }} resultado(s)</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1100px] text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3">Tipo</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3">Instituição</th>
                        <th class="px-4 py-3">NSU / TX ID</th>
                        <th class="px-4 py-3 text-right">Valor</th>
                        <th class="px-4 py-3 text-right">Líquido</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($transacoes as $tx)
                        @php
                            $statusClass = match ((string) $tx->status_pagamento) {
                                '04', '4' => 'bg-red-100 text-red-700',
                                '03', '3' => 'bg-emerald-100 text-emerald-700',
                                '02', '2' => 'bg-amber-100 text-amber-700',
                                '01', '1' => 'bg-blue-100 text-blue-700',
                                default => 'bg-gray-100 text-gray-700',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                            <td class="whitespace-nowrap px-4 py-3">
                                <div class="font-semibold text-gray-800 dark:text-gray-100">{{ $tx->data_inicial_transacao?->format('d/m/Y') ?: '—' }}</div>
                                <div class="mt-0.5 text-xs text-gray-500">{{ $tx->hora_inicial_transacao ?: 'sem hora' }}</div>
                            </td>
                            <td class="px-4 py-3 capitalize text-gray-700 dark:text-gray-200">{{ $tx->tipo_transacao ?: '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $statusClass }}">{{ $consulta->statusLabel($tx->status_pagamento) }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-200">{{ $tx->instituicao_financeira ? \App\Support\InstituicaoFinanceira::nome($tx->instituicao_financeira) : '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="font-mono text-xs text-gray-700 dark:text-gray-200">{{ $tx->nsu ?: '—' }}</div>
                                <div class="mt-0.5 max-w-56 truncate font-mono text-xs text-gray-400" title="{{ $tx->tx_id }}">{{ $tx->tx_id ?: '—' }}</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums font-semibold text-gray-800 dark:text-gray-100">R$ {{ number_format((float) $tx->valor_total_transacao, 2, ',', '.') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">R$ {{ number_format((float) $tx->valor_liquido_transacao, 2, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-gray-500">Nenhuma transação neste mês para esse estabelecimento.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transacoes)
            <div class="border-t border-gray-100 px-4 py-3 dark:border-gray-800">
                {{ $transacoes->links() }}
            </div>
        @endif
    </div>
@endif
@endsection
