@extends('layouts.app')

@section('title', 'Dump EDI #'.$dump->id)

@section('content')
@if ($dump->emAndamento())
    <script>setTimeout(() => window.location.reload(), 12000);</script>
@endif

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="{{ route('admin.edi-dump.index') }}" class="text-xs font-semibold text-blue-600 hover:underline">← Dumps</a>
        <h1 class="mt-1 text-lg font-semibold text-gray-800 dark:text-gray-100">
            Dump #{{ $dump->id }} · {{ $dump->competencia->translatedFormat('F/Y') }}
        </h1>
        <p class="mt-1 text-sm text-gray-500">
            {{ $dump->total_dias }} dias do mês
            @if ($dump->iniciado_por_nome)
                · {{ $dump->iniciado_por_nome }}
            @endif
            @if ($dump->emAndamento())
                · atualiza sozinho a cada 12s
            @endif
        </p>
    </div>
    @include('admin.edi-dump.partials.status', ['status' => $dump->status])
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
@endif

@if ($dump->erro)
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $dump->erro }}</div>
@endif

<div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-medium text-gray-500">Páginas (soma dos dias)</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($dump->total_paginas, 0, ',', '.') }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-medium text-gray-500">Itens declarados pela API</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($dump->total_itens_api, 0, ',', '.') }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-medium text-gray-500">Linhas gravadas</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($dump->total_linhas, 0, ',', '.') }}</p>
        @if ($dump->total_itens_api > 0 && $dump->total_linhas !== $dump->total_itens_api)
            <p class="mt-1 text-xs text-amber-600">Difere do total da API</p>
        @endif
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-medium text-gray-500">Dias</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-800 dark:text-gray-100">{{ $dump->dias_ok }}/{{ $dump->total_dias }}</p>
        <p class="mt-1 text-xs text-gray-500">{{ $dump->dias_nao_validados }} não validado · {{ $dump->dias_erro }} erro</p>
    </div>
</div>

<form method="GET" action="{{ route('admin.edi-dump.show', $dump) }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <p class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Somar por ID</p>
    <div class="flex flex-wrap gap-2">
        <input
            type="text"
            name="id"
            value="{{ $idBusca }}"
            placeholder="ID interno ou código EDI (Safepay)"
            class="min-w-64 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
        >
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
            Somar
        </button>
    </div>
    @if ($soma)
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <p class="text-xs text-gray-500">Linhas</p>
                <p class="text-lg font-bold tabular-nums">{{ number_format($soma['quantidade'], 0, ',', '.') }}</p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <p class="text-xs text-gray-500">Valor total</p>
                <p class="text-lg font-bold tabular-nums">R$ {{ number_format($soma['valor_total'], 2, ',', '.') }}</p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                <p class="text-xs text-gray-500">Valor líquido</p>
                <p class="text-lg font-bold tabular-nums">R$ {{ number_format($soma['valor_liquido'], 2, ',', '.') }}</p>
            </div>
        </div>
        <p class="mt-2 text-xs text-gray-500">
            @if ($soma['nome'])
                {{ $soma['nome'] }}
                @if ($soma['estabelecimento_id']) · ID {{ $soma['estabelecimento_id'] }} @endif
                @if ($soma['estabelecimento']) · EDI {{ $soma['estabelecimento'] }} @endif
            @else
                ID informado: {{ $idBusca }} (sem cadastro local)
            @endif
        </p>
    @endif
</form>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Cada dia do mês</h2>
        <p class="text-xs text-gray-500">Páginas percorridas, itens que a API declarou e linhas gravadas.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Dia</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Páginas</th>
                    <th class="px-4 py-3 text-right">Itens API</th>
                    <th class="px-4 py-3 text-right">Linhas</th>
                    <th class="px-4 py-3">Obs.</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($dump->dias as $dia)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <td class="px-4 py-3 font-semibold tabular-nums text-gray-800 dark:text-gray-100">{{ $dia->data->format('d/m/Y') }}</td>
                        <td class="px-4 py-3">@include('admin.edi-dump.partials.status', ['status' => $dia->status])</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ number_format($dia->paginas, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($dia->total_itens_api, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($dia->linhas, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-xs text-gray-500">{{ $dia->motivo ?: '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                            @if ($dump->emAndamento())
                                Ainda não processou o primeiro dia.
                            @else
                                Nenhum dia registrado.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
