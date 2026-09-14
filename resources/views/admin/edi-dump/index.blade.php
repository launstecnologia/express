@extends('layouts.app')

@section('title', 'Dump EDI')

@section('content')
@php
    $meses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
@endphp

<div class="mb-6">
    <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Dump EDI do mês</h1>
    <p class="mt-1 text-sm text-gray-500">Baixa o EDI de <strong>todos os dias</strong> do mês, página a página, e grava as linhas cruas. Não altera o faturamento operacional.</p>
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>
@endif

@unless ($ediConfigurado)
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Credenciais EDI não configuradas. Vá em Configurações → PagBank.
    </div>
@endunless

<form method="POST" action="{{ route('admin.edi-dump.store') }}" class="mb-8 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    @csrf
    <div class="grid gap-4 sm:grid-cols-3">
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Mês</span>
            <select name="mes_numero" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                @foreach ($meses as $numero => $nome)
                    <option value="{{ $numero }}" @selected((int) old('mes_numero', $mesNumero) === $numero)>{{ $nome }}</option>
                @endforeach
            </select>
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Ano</span>
            <input type="number" name="ano" value="{{ old('ano', $ano) }}" min="2020" max="2100" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
        </label>
        <div class="flex items-end">
            <button type="submit" @disabled(! $ediConfigurado) class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                <i class="fa-solid fa-download"></i>
                Executar EDI do mês
            </button>
        </div>
    </div>
</form>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Execuções</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Mês</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Dias</th>
                    <th class="px-4 py-3 text-right">Páginas</th>
                    <th class="px-4 py-3 text-right">Itens API</th>
                    <th class="px-4 py-3 text-right">Linhas</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($dumps as $item)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $item->id }}</td>
                        <td class="px-4 py-3 font-semibold text-gray-800 dark:text-gray-100">{{ $item->competencia->translatedFormat('F/Y') }}</td>
                        <td class="px-4 py-3">
                            @include('admin.edi-dump.partials.status', ['status' => $item->status])
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $item->dias_ok }}/{{ $item->total_dias }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-semibold">{{ number_format($item->total_paginas, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($item->total_itens_api, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ number_format($item->total_linhas, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('admin.edi-dump.show', $item) }}" class="text-xs font-semibold text-blue-600 hover:underline">Abrir</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-10 text-center text-gray-500">Nenhum dump ainda.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
