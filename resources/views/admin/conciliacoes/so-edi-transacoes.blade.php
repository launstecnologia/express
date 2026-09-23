@extends('layouts.app')

@section('title', 'Transações só no EDI · '.$conciliacao->referenciaFormatada())

@section('content')
@php
    $excelQuery = request()->except(['page', 'por_pagina']);
@endphp
<div class="mb-5">
    <a href="{{ route('admin.conciliacoes.diferenca', $conciliacao) }}#so-edi" class="mb-2 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-800">
        <i class="fa-solid fa-arrow-left"></i> Voltar à diferença
    </a>
    <h2 class="text-xl font-bold text-gray-800">Transações no EDI, não encontradas na planilha</h2>
    <p class="text-sm text-gray-500">
        {{ $conciliacao->referenciaFormatada() }}
        @if (filled($filtros['estabelecimento_id'] ?? null))
            · filtro: {{ $filtros['estabelecimento_id'] }}
        @endif
    </p>
</div>

<div class="mb-5 grid gap-3 sm:grid-cols-2">
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-sky-700">Transações</p>
        <p class="mt-2 text-2xl font-bold text-sky-800">{{ number_format($totais['quantidade'], 0, ',', '.') }}</p>
    </div>
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-sky-700">TPV</p>
        <p class="mt-2 text-2xl font-bold text-sky-800">R$ {{ number_format($totais['valor'], 2, ',', '.') }}</p>
    </div>
</div>

<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
    <form method="GET" action="{{ route('admin.conciliacoes.so-edi-transacoes', $conciliacao) }}" class="flex flex-wrap items-center gap-2">
        @foreach (request()->except(['page', 'por_pagina']) as $chave => $valor)
            @if (is_scalar($valor) && $valor !== '')
                <input type="hidden" name="{{ $chave }}" value="{{ $valor }}">
            @endif
        @endforeach
        <label class="inline-flex items-center gap-2 text-xs font-semibold text-gray-500">
            Por página
            <select name="por_pagina" onchange="this.form.submit()" class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-sm">
                @foreach ([50, 100, 200] as $qtd)
                    <option value="{{ $qtd }}" @selected((int) request('por_pagina', 50) === $qtd)>{{ $qtd }}</option>
                @endforeach
            </select>
        </label>
    </form>
    @if ($totais['quantidade'] > 0)
        <a href="{{ route('admin.conciliacoes.relatorio-so-edi-excel', array_merge(['conciliacao' => $conciliacao], $excelQuery)) }}"
           class="inline-flex items-center gap-1 rounded-lg border border-sky-300 bg-white px-3 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-100">
            <i class="fa-solid fa-file-excel"></i> Excel das transações
        </a>
    @endif
</div>

<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">Data</th>
                <th class="px-4 py-3">Estabelecimento</th>
                <th class="px-4 py-3">ID cliente</th>
                <th class="px-4 py-3">NSU / Autorização</th>
                <th class="px-4 py-3">Tipo</th>
                <th class="px-4 py-3">Bandeira</th>
                <th class="px-4 py-3">Parcelas</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3 text-right">Valor</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($transacoes as $tx)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 whitespace-nowrap text-gray-700">
                        {{ $tx->data }}
                        @if (filled($tx->hora))
                            <span class="block text-xs text-gray-400">{{ $tx->hora }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @if (filled($tx->estabelecimento_id))
                            <a href="{{ route('estabelecimentos.show', $tx->estabelecimento_id) }}" class="font-semibold text-blue-600 hover:underline">
                                {{ $tx->estabelecimento ?: '—' }}
                            </a>
                        @else
                            <span class="text-gray-500">{{ $tx->estabelecimento ?: '—' }}</span>
                        @endif
                        @if (filled($tx->marketplace))
                            <span class="mt-0.5 block text-xs text-gray-400">{{ $tx->marketplace }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $tx->id_cliente }}</td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">
                        {{ $tx->nsu ?: '—' }}
                        @if (filled($tx->codigo_autorizacao))
                            <span class="mt-0.5 block text-gray-400">{{ $tx->codigo_autorizacao }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 capitalize text-gray-600">{{ $tx->tipo ?: $tx->meio ?: '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1.5">
                            <x-instituicao-icone :codigo="$tx->instituicao ?: $tx->bandeira" size="sm" />
                            <span class="text-xs text-gray-600">{{ $tx->bandeira ?: $tx->instituicao ?: '—' }}</span>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        @php
                            $qtdParcelas = (int) preg_replace('/\D/', '', (string) $tx->quantidade_parcela);
                            $parcela = trim((string) $tx->parcela);
                        @endphp
                        @if ($qtdParcelas > 1)
                            {{ $parcela !== '' ? $parcela.'/' : '' }}{{ $qtdParcelas }}x
                        @else
                            {{ $tx->parcelamento ?: '—' }}
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $tx->status ?: '—' }}</td>
                    <td class="px-4 py-3 text-right font-semibold text-sky-700">R$ {{ number_format((float) $tx->valor, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="px-4 py-10 text-center text-sm text-gray-500">Nenhuma transação só no EDI para este recorte.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $transacoes->links() }}</div>
@endsection
