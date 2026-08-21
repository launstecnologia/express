@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $periodoRotulo = $periodo === 0
        ? now()->translatedFormat('F/Y')
        : "últimos {$periodo} dias";
@endphp
<div class="grid grid-cols-1 gap-4 md:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Estabelecimentos</p>
                <p class="mt-2 text-2xl font-bold tabular-nums text-gray-800 sm:text-3xl dark:text-gray-100">{{ number_format($totalEstabelecimentos, 0, ',', '.') }}</p>
            </div>
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400">
                <i class="fa-solid fa-store text-lg"></i>
            </div>
        </div>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Faturamento</p>
                <p class="mt-2 text-lg font-bold tabular-nums leading-tight text-green-600 sm:text-xl lg:text-2xl dark:text-green-400">R$ {{ number_format($faturamentoMes, 2, ',', '.') }}</p>
                <p class="mt-1 text-[11px] text-gray-400">{{ $periodoRotulo }} · EDI</p>
            </div>
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-50 text-green-600 dark:bg-green-950 dark:text-green-400">
                <i class="fa-solid fa-chart-line text-lg"></i>
            </div>
        </div>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Comissões</p>
                <p class="mt-2 text-lg font-bold tabular-nums leading-tight text-yellow-600 sm:text-xl lg:text-2xl dark:text-yellow-400">R$ {{ number_format($royaltiesMes, 2, ',', '.') }}</p>
                <p class="mt-1 text-[11px] text-gray-400">
                    @if (\App\Support\UsuarioComercial::ehRevenda())
                        {{ $periodoRotulo }} · conciliação PagSeguro
                    @elseif (\App\Support\UsuarioComercial::ehMarketplace())
                        {{ $periodoRotulo }} · líquida após royalties
                    @else
                        {{ $periodoRotulo }}
                    @endif
                </p>
            </div>
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-yellow-50 text-yellow-600 dark:bg-yellow-950 dark:text-yellow-400">
                <i class="fa-solid fa-hand-holding-dollar text-lg"></i>
            </div>
        </div>
    </div>
</div>

@php
    $periodoLink = fn (int $dias) => request()->fullUrlWithQuery(['periodo' => $dias]);
    $periodoClass = fn (int $dias) => $periodo === $dias
        ? 'rounded-md bg-blue-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm'
        : 'rounded-md px-3 py-1.5 text-sm text-gray-500 transition-colors hover:bg-white dark:text-gray-400 dark:hover:bg-gray-700';
@endphp

<div class="mt-6 flex items-center justify-between rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Período de Análise</h2>
    <div class="flex items-center gap-1 rounded-lg bg-gray-100 p-1 dark:bg-gray-800">
        <a href="{{ $periodoLink(0) }}" class="{{ $periodoClass(0) }}">Mês atual</a>
        <a href="{{ $periodoLink(7) }}" class="{{ $periodoClass(7) }}">7 dias</a>
        <a href="{{ $periodoLink(30) }}" class="{{ $periodoClass(30) }}">30 dias</a>
        <a href="{{ $periodoLink(90) }}" class="{{ $periodoClass(90) }}">90 dias</a>
    </div>
</div>

@include('admin.dashboard-apuracao')
@endsection
