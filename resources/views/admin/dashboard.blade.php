@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
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
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Faturamento do mês</p>
                <p class="mt-2 text-lg font-bold tabular-nums leading-tight text-green-600 sm:text-xl lg:text-2xl dark:text-green-400">R$ {{ number_format($faturamentoMes, 2, ',', '.') }}</p>
                <p class="mt-1 text-[11px] text-gray-400">{{ now()->translatedFormat('F/Y') }} · EDI</p>
            </div>
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-green-50 text-green-600 dark:bg-green-950 dark:text-green-400">
                <i class="fa-solid fa-chart-line text-lg"></i>
            </div>
        </div>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Comissões do mês</p>
                <p id="dashboard-comissao-valor" class="mt-2 text-lg font-bold tabular-nums leading-tight text-yellow-600 sm:text-xl lg:text-2xl dark:text-yellow-400">
                    <span class="inline-flex items-center gap-2 text-base text-gray-400">
                        <i class="fa-solid fa-spinner fa-spin text-sm"></i> Calculando...
                    </span>
                </p>
                <p class="mt-1 text-[11px] text-gray-400">{{ now()->translatedFormat('F/Y') }}</p>
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
        <a href="{{ $periodoLink(7) }}" class="{{ $periodoClass(7) }}">7 dias</a>
        <a href="{{ $periodoLink(30) }}" class="{{ $periodoClass(30) }}">30 dias</a>
        <a href="{{ $periodoLink(90) }}" class="{{ $periodoClass(90) }}">90 dias</a>
    </div>
</div>

<div
    id="dashboard-apuracao-container"
    class="mt-6 min-h-[24rem] rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-sky-50 p-8 shadow-sm dark:border-gray-700 dark:from-gray-900 dark:via-gray-900 dark:to-gray-950"
    data-url="{{ route('dashboard.apuracao', ['periodo' => $periodo]) }}"
>
    <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
        <i class="fa-solid fa-spinner fa-spin text-2xl text-blue-600 dark:text-blue-400"></i>
        <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Carregando apuração das transações...</p>
        <p class="text-xs text-gray-400">Agregação por plano com base no EDI</p>
    </div>
</div>
@endsection

@section('scripts')
<script>
    (function () {
        const comissaoEl = document.getElementById('dashboard-comissao-valor');
        const apuracaoEl = document.getElementById('dashboard-apuracao-container');

        fetch(@json(route('dashboard.comissao')), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => response.ok ? response.json() : Promise.reject())
            .then((data) => {
                if (comissaoEl) {
                    comissaoEl.textContent = data.formatado || ('R$ ' + Number(data.royaltiesMes || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                }
            })
            .catch(() => {
                if (comissaoEl) {
                    comissaoEl.innerHTML = '<span class="text-base text-red-500">Erro ao carregar</span>';
                }
            });

        if (! apuracaoEl) {
            return;
        }

        fetch(apuracaoEl.dataset.url, {
            headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((response) => response.ok ? response.text() : Promise.reject())
            .then((html) => {
                apuracaoEl.outerHTML = html;
            })
            .catch(() => {
                apuracaoEl.innerHTML = '<div class="rounded-xl border border-red-200 bg-red-50 px-6 py-12 text-center text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">Não foi possível carregar a apuração. <a href="' + window.location.href + '" class="font-semibold underline">Recarregar página</a></div>';
            });
    })();
</script>
@endsection
