@extends('layouts.app')

@section('title', 'Comissões')

@section('content')
@php
    $totalFaturamento = $linhas->sum('total_faturamento');
    $totalComissao = $linhas->sum('total_comissao');
    $totalComissaoBruta = $linhas->sum('total_comissao_bruta');
    $totalRoyalty = $linhas->sum('total_royalty');
    $colspanVazio = ($ehAdmin ?? false) ? 7 : 5;
@endphp

<form method="GET" action="{{ route('comissoes.index') }}" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
    <div class="min-w-[220px] flex-1">
        <label for="mes" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Mês de referência</label>
        <select id="mes" name="mes" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" onchange="this.form.submit()">
            @forelse ($mesesDisponiveis as $opcao)
                <option value="{{ $opcao->valor }}" @selected($mesSelecionado === $opcao->valor)>{{ $opcao->rotulo }}</option>
            @empty
                <option value="">Nenhuma planilha importada</option>
            @endforelse
        </select>
    </div>
    @if ($conciliacao)
        <div class="flex items-center gap-2 pb-2">
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                <i class="fa-solid fa-circle-check mr-1"></i> Conciliado
            </span>
            <span class="text-xs text-gray-400">Fonte: planilha PagSeguro</span>
        </div>
    @endif
</form>

<div class="mb-6 grid grid-cols-1 gap-3 {{ ($ehAdmin ?? false) ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="mb-1 text-xs font-medium text-gray-500">Faturamento{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}</p>
        <span class="text-2xl font-bold text-green-600">R$ {{ number_format($totalFaturamento, 2, ',', '.') }}</span>
    </div>
    @if ($ehAdmin ?? false)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Comissão bruta{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}</p>
            <span class="text-2xl font-bold text-slate-700">R$ {{ number_format($totalComissaoBruta, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">Valor da planilha PagSeguro</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Comissão líquida{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}</p>
            <span class="text-2xl font-bold text-sky-600">R$ {{ number_format($totalComissao, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">
                Após royalties
                @if ($totalRoyalty > 0)
                    (−R$ {{ number_format($totalRoyalty, 2, ',', '.') }})
                @endif
            </p>
        </div>
    @else
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Comissões{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}</p>
            <span class="text-2xl font-bold text-sky-600">R$ {{ number_format($totalComissao, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">Líquida após desconto de royalties</p>
        </div>
    @endif
</div>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">Extrato de comissões</h3>
            <p class="text-xs text-gray-400">
                {{ $linhas->total() }} marketplace(s)
                @if ($periodoRotulo)
                    · {{ $periodoRotulo }}
                @endif
                · dados da planilha PagSeguro
            </p>
        </div>
        <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Exportar</button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50">
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Período</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Faturamento</th>
                    @if ($ehAdmin ?? false)
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Comissão bruta</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Royalty</th>
                    @endif
                    <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Comissão líquida</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($linhas as $linha)
                    <tr class="border-b border-gray-50 transition-colors hover:bg-gray-50">
                        <td class="px-5 py-4">
                            <p class="font-semibold text-gray-800">{{ $linha->marketplace_nome }}</p>
                        </td>
                        <td class="px-5 py-4">
                            <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700">{{ $linha->periodo }}</span>
                        </td>
                        <td class="px-5 py-4">
                            @if ($linha->conciliado)
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">Conciliado</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">Sem planilha</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 font-semibold text-green-600">R$ {{ number_format($linha->total_faturamento, 2, ',', '.') }}</td>
                        @if ($ehAdmin ?? false)
                            <td class="px-5 py-4 font-semibold text-slate-700">R$ {{ number_format($linha->total_comissao_bruta, 2, ',', '.') }}</td>
                            <td class="px-5 py-4">
                                @if (($linha->total_royalty ?? 0) > 0)
                                    <p class="font-semibold text-amber-700">−R$ {{ number_format($linha->total_royalty, 2, ',', '.') }}</p>
                                    <p class="mt-0.5 text-[11px] text-gray-400">{{ number_format($linha->percentual_retencao, 0, ',', '.') }}%</p>
                                @elseif (($linha->percentual_retencao ?? 0) > 0)
                                    <p class="text-sm text-gray-400">R$ 0,00</p>
                                    <p class="mt-0.5 text-[11px] text-gray-400">{{ number_format($linha->percentual_retencao, 0, ',', '.') }}%</p>
                                @else
                                    <p class="text-sm text-gray-400">—</p>
                                @endif
                            </td>
                        @endif
                        <td class="px-5 py-4 font-semibold text-sky-600">R$ {{ number_format($linha->total_comissao, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colspanVazio }}" class="px-5 py-10 text-center text-sm text-gray-500">
                            @if ($mesesDisponiveis->isEmpty())
                                Nenhuma planilha PagSeguro importada. Importe em Admin → Conciliação.
                            @else
                                Nenhuma comissão de marketplace encontrada para o período selecionado.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6">{{ $linhas->links() }}</div>
@endsection
