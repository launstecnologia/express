@extends('layouts.app')

@section('title', 'Comissões')

@section('content')
@php
    $visao = $visao ?? 'marketplace';
    $visaoRevenda = $visao === 'revenda' || ($ehRevenda ?? false);
    $ehAdmin = $ehAdmin ?? false;
    $mostrarReferencia = ! $ehAdmin;
    $totalFaturamento = $linhas->sum('total_faturamento');
    $totalComissao = $linhas->sum('total_comissao');
    $totalComissaoBruta = $linhas->sum('total_comissao_bruta');
    $totalRoyalty = $linhas->sum('total_royalty');
    $colspanVazio = $ehAdmin
        ? ($visaoRevenda ? 8 : 7)
        : ($mostrarReferencia ? 6 : 5);
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
    @if ($podeSelecionarVisao ?? false)
        <div class="min-w-[200px]">
            <label for="visao" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Visão</label>
            <select id="visao" name="visao" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm" onchange="this.form.submit()">
                <option value="marketplace" @selected($visao === 'marketplace')>{{ ($ehMarketplace ?? false) ? 'Meu marketplace' : 'Marketplace' }}</option>
                <option value="revenda" @selected($visao === 'revenda')>{{ ($ehMarketplace ?? false) ? 'Revendas abaixo' : 'Revenda' }}</option>
            </select>
        </div>
    @endif
    @if ($conciliacao)
        <div class="flex items-center gap-2 pb-2">
            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                <i class="fa-solid fa-circle-check mr-1"></i> Conciliado
            </span>
            <span class="text-xs text-gray-400">Fonte: planilha PagSeguro</span>
        </div>
    @endif
</form>

<div class="mb-6 grid grid-cols-1 gap-3 {{ ($ehAdmin || $mostrarReferencia) ? 'md:grid-cols-3' : 'md:grid-cols-2' }}">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="mb-1 text-xs font-medium text-gray-500">Faturamento{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}</p>
        <span class="text-2xl font-bold text-green-600">R$ {{ number_format($totalFaturamento, 2, ',', '.') }}</span>
    </div>
    @if ($ehAdmin)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">
                {{ $visaoRevenda ? 'Comissão marketplace (carteira)' : 'Comissão bruta' }}{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}
            </p>
            <span class="text-2xl font-bold text-slate-700">R$ {{ number_format($totalComissaoBruta, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">
                {{ $visaoRevenda ? 'ms_comissão dos clientes das revendas' : 'Valor da planilha PagSeguro' }}
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">
                {{ $visaoRevenda ? 'Comissão das revendas' : 'Comissão líquida' }}{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}
            </p>
            <span class="text-2xl font-bold text-sky-600">R$ {{ number_format($totalComissao, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">
                @if ($visaoRevenda)
                    % da revenda sobre a líquida do marketplace
                @else
                    Após royalties
                    @if ($totalRoyalty > 0)
                        (−R$ {{ number_format($totalRoyalty, 2, ',', '.') }})
                    @endif
                @endif
            </p>
        </div>
    @else
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">Comissão conciliação{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}</p>
            <span class="text-2xl font-bold text-slate-700">R$ {{ number_format($totalComissaoBruta, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">Referência · planilha PagSeguro</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="mb-1 text-xs font-medium text-gray-500">
                {{ $visaoRevenda ? 'Comissão das revendas' : 'Comissões' }}{{ $periodoRotulo ? " · {$periodoRotulo}" : '' }}
            </p>
            <span class="text-2xl font-bold text-sky-600">R$ {{ number_format($totalComissao, 2, ',', '.') }}</span>
            <p class="mt-1 text-[11px] text-gray-400">
                {{ $visaoRevenda
                    ? '% da revenda sobre a comissão da conciliação'
                    : 'Líquida após desconto de royalties' }}
            </p>
        </div>
    @endif
</div>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">Extrato de comissões</h3>
            <p class="text-xs text-gray-400">
                {{ $linhas->total() }} {{ $visaoRevenda ? 'revenda(s)' : 'marketplace(s)' }}
                @if ($periodoRotulo)
                    · {{ $periodoRotulo }}
                @endif
                · {{ $visaoRevenda ? 'comissão da conciliação dos clientes da revenda' : 'dados da planilha PagSeguro' }}
            </p>
        </div>
        <button type="button" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-700">Exportar</button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" style="min-width: {{ $ehAdmin ? ($visaoRevenda ? '1200px' : '1100px') : ($mostrarReferencia ? '980px' : '860px') }}">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50">
                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $visaoRevenda ? 'Revenda' : 'Marketplace' }}</th>
                    @if (($ehAdmin ?? false) && $visaoRevenda)
                        <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</th>
                    @endif
                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Período</th>
                    <th class="whitespace-nowrap px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                    <th class="whitespace-nowrap px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">Faturamento</th>
                    @if ($ehAdmin)
                        <th class="whitespace-nowrap px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ $visaoRevenda ? 'Com. marketplace' : 'Comissão bruta' }}
                        </th>
                        <th class="whitespace-nowrap px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                            {{ $visaoRevenda ? 'Royalty admin' : 'Royalty' }}
                        </th>
                    @elseif ($mostrarReferencia)
                        <th class="whitespace-nowrap px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Comissão conciliação
                        </th>
                    @endif
                    <th class="whitespace-nowrap px-5 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                        {{ $visaoRevenda ? 'Comissão revenda' : 'Comissão líquida' }}
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse ($linhas as $linha)
                    <tr class="border-b border-gray-50 transition-colors hover:bg-gray-50">
                        <td class="max-w-[280px] px-5 py-4">
                            <p class="truncate font-semibold text-gray-800" title="{{ $linha->parceiro_nome ?? $linha->marketplace_nome }}">{{ $linha->parceiro_nome ?? $linha->marketplace_nome }}</p>
                            @if ($visaoRevenda && ($linha->percentual_retencao ?? 0) > 0)
                                <p class="mt-0.5 text-[11px] text-gray-400">Participação {{ number_format($linha->percentual_retencao, 0, ',', '.') }}%</p>
                            @endif
                        </td>
                        @if (($ehAdmin ?? false) && $visaoRevenda)
                            <td class="max-w-[220px] px-5 py-4">
                                <p class="truncate text-sm text-gray-600" title="{{ $linha->marketplace_nome }}">{{ $linha->marketplace_nome }}</p>
                            </td>
                        @endif
                        <td class="whitespace-nowrap px-5 py-4">
                            <span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700">{{ $linha->periodo }}</span>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4">
                            @if ($linha->conciliado)
                                <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">{{ $visaoRevenda ? 'Apurado' : 'Conciliado' }}</span>
                            @else
                                <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600">Sem planilha</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-green-600">R$&nbsp;{{ number_format($linha->total_faturamento, 2, ',', '.') }}</td>
                        @if ($ehAdmin)
                            <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-slate-700">R$&nbsp;{{ number_format($linha->total_comissao_bruta, 2, ',', '.') }}</td>
                            <td class="whitespace-nowrap px-5 py-4 text-right tabular-nums">
                                @php
                                    $pctRoyalty = $visaoRevenda
                                        ? ($linha->percentual_admin ?? 0)
                                        : ($linha->percentual_retencao ?? 0);
                                @endphp
                                @if (($linha->total_royalty ?? 0) > 0)
                                    <span class="font-semibold text-amber-700">−R$&nbsp;{{ number_format($linha->total_royalty, 2, ',', '.') }}</span>
                                    <span class="ml-1 text-[11px] text-gray-400">({{ number_format($pctRoyalty, 0, ',', '.') }}%)</span>
                                @elseif ($pctRoyalty > 0)
                                    <span class="text-sm text-gray-400">R$&nbsp;0,00</span>
                                    <span class="ml-1 text-[11px] text-gray-400">({{ number_format($pctRoyalty, 0, ',', '.') }}%)</span>
                                @else
                                    <span class="text-sm text-gray-400">—</span>
                                @endif
                            </td>
                        @elseif ($mostrarReferencia)
                            <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-slate-700">R$&nbsp;{{ number_format($linha->total_comissao_bruta, 2, ',', '.') }}</td>
                        @endif
                        <td class="whitespace-nowrap px-5 py-4 text-right font-semibold tabular-nums text-sky-600">R$&nbsp;{{ number_format($linha->total_comissao, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $colspanVazio }}" class="px-5 py-10 text-center text-sm text-gray-500">
                            @if ($mesesDisponiveis->isEmpty())
                                Nenhuma planilha PagSeguro importada. Importe em Admin → Conciliação.
                            @elseif ($visaoRevenda)
                                Nenhuma comissão de revenda encontrada para o período. Confira se os ECs têm revenda vinculada.
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
