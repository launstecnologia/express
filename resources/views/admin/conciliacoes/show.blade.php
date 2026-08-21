@extends('layouts.app')

@section('title', 'Conciliação · '.$conciliacao->referenciaFormatada())

@section('content')
@php
    $confrontoEmAndamento = in_array($conciliacao->confronto_status, ['na_fila', 'processando'], true);
    $semEdiResumo = $resumo['por_status']['sem_edi'] ?? ['tpv' => 0, 'linhas' => 0, 'comissao' => 0];
    $statusClasses = [
        'ok' => 'bg-emerald-100 text-emerald-700',
        'divergente' => 'bg-amber-100 text-amber-700',
        'sem_estabelecimento' => 'bg-red-100 text-red-700',
        'sem_edi' => 'bg-orange-100 text-orange-700',
        'pendente' => 'bg-gray-100 text-gray-600',
    ];
@endphp

<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="{{ route('admin.conciliacoes.index') }}" class="mb-2 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-800">
            <i class="fa-solid fa-arrow-left"></i> Conciliações
        </a>
        <h2 class="text-xl font-bold text-gray-800">{{ $conciliacao->referenciaFormatada() }}</h2>
        <p class="text-sm text-gray-500">
            {{ $conciliacao->parceiro ?: 'Parceiro não informado' }}
            @if ($conciliacao->data_referencia)
                · ref. {{ $conciliacao->data_referencia->format('d/m/Y') }}
            @endif
            @if ($conciliacao->arquivo_nome)
                · {{ $conciliacao->arquivo_nome }}
            @endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('admin.conciliacoes.diferenca', $conciliacao) }}" class="inline-flex items-center gap-2 rounded-lg border border-orange-200 bg-orange-50 px-4 py-2 text-sm font-semibold text-orange-800 hover:bg-orange-100">
            <i class="fa-solid fa-table"></i>
            Relatório da diferença
        </a>
        <form method="POST" action="{{ route('admin.conciliacoes.confrontar', $conciliacao) }}">
            @csrf
            <button @disabled($confrontoEmAndamento) class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100 disabled:cursor-wait disabled:opacity-60" title="Religa estabelecimentos cadastrados depois da importação e confronta de novo com o EDI">
                <i class="fa-solid {{ $confrontoEmAndamento ? 'fa-spinner fa-spin' : 'fa-rotate' }}"></i>
                {{ $confrontoEmAndamento ? 'Atualização em andamento' : 'Atualizar vínculos e reconfrontar' }}
            </button>
        </form>
        <form method="POST" action="{{ route('admin.conciliacoes.destroy', $conciliacao) }}" onsubmit="return confirm('Remover esta conciliação?')">
            @csrf
            @method('DELETE')
            <button class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                Remover
            </button>
        </form>
    </div>
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
@endif

@if ($errors->has('confronto'))
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $errors->first('confronto') }}
    </div>
@endif

@if ($conciliacao->confronto_status === 'na_fila')
    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
        <i class="fa-solid fa-clock"></i>
        Confronto aguardando na fila. Esta página será atualizada automaticamente.
    </div>
@elseif ($conciliacao->confronto_status === 'processando')
    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
        <i class="fa-solid fa-spinner fa-spin"></i>
        Confrontando com o EDI em segundo plano. Você pode sair desta página.
    </div>
@elseif ($conciliacao->confronto_status === 'erro')
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <strong>O confronto falhou.</strong>
        {{ $conciliacao->confronto_erro ?: 'Consulte os logs do worker para mais detalhes.' }}
    </div>
@endif

<div class="mb-5 grid gap-3 md:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">TPV PagSeguro</p>
        <p class="mt-2 text-2xl font-bold text-gray-800">R$ {{ number_format($resumo['pagseguro_tpv'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-gray-500">Volume da planilha</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">TPV EDI (confrontado)</p>
        <p class="mt-2 text-2xl font-bold text-gray-800">R$ {{ number_format($resumo['edi_tpv'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-gray-500">Volume do EDI que casou com a planilha</p>
    </div>
    <div class="rounded-xl border border-orange-200 bg-orange-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-orange-700">TPV só no relatório</p>
        <p class="mt-2 text-2xl font-bold text-orange-800">R$ {{ number_format($resumo['tpv_so_relatorio'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-orange-700">
            {{ number_format($semEdiResumo['linhas'], 0, ',', '.') }} linhas sem EDI
            · R$ {{ number_format($semEdiResumo['tpv'], 2, ',', '.') }}
        </p>
        <a href="{{ route('admin.conciliacoes.diferenca', $conciliacao) }}" class="mt-2 inline-block text-xs font-semibold text-orange-800 hover:underline">
            Ver estabelecimentos
        </a>
    </div>
</div>

<div class="mb-5 grid gap-3 md:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Comissão PagSeguro</p>
        <p class="mt-2 text-2xl font-bold text-blue-700">R$ {{ number_format($resumo['pagseguro_comissao'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-gray-500">MS Comissão da planilha</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">Comissão no TPV EDI</p>
        <p class="mt-2 text-2xl font-bold text-sky-700">R$ {{ number_format($resumo['edi_comissao'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-gray-500">MS Comissão da planilha no volume que casou (não a grade)</p>
    </div>
    <div class="rounded-xl border border-orange-200 bg-orange-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-orange-700">Diferença de comissão</p>
        <p class="mt-2 text-2xl font-bold text-orange-800">R$ {{ number_format($resumo['comissao_so_relatorio'], 2, ',', '.') }}</p>
        <p class="mt-1 text-xs text-orange-700">
            Planilha − volume EDI
            · R$ {{ number_format($semEdiResumo['comissao'], 2, ',', '.') }} nas linhas sem EDI
        </p>
    </div>
</div>

<div class="mb-5 grid gap-3 md:grid-cols-2">
    @php
        $com = $resumoEstabelecimentos['com_estabelecimento'];
        $sem = $resumoEstabelecimentos['sem_estabelecimento'];
    @endphp
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
        <p class="text-xs font-bold uppercase text-emerald-700">Com estabelecimento</p>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <p class="text-xs text-emerald-600">Linhas</p>
                <p class="text-xl font-bold text-emerald-900">{{ number_format($com['linhas'], 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-emerald-600">Clientes</p>
                <p class="text-xl font-bold text-emerald-900">{{ number_format($com['clientes'], 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-emerald-600">TPV</p>
                <p class="text-lg font-bold text-emerald-900">R$ {{ number_format($com['tpv'], 2, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-emerald-600">Comissão</p>
                <p class="text-lg font-bold text-emerald-900">R$ {{ number_format($com['comissao'], 2, ',', '.') }}</p>
            </div>
        </div>
    </div>
    <div class="rounded-xl border border-red-200 bg-red-50 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="text-xs font-bold uppercase text-red-700">Sem estabelecimento</p>
            @if ($sem['clientes'] > 0)
                <a href="{{ route('admin.conciliacoes.diferenca', $conciliacao) }}"
                   class="inline-flex items-center gap-1 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                    <i class="fa-solid fa-table"></i> Ver relatório
                </a>
            @endif
        </div>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <p class="text-xs text-red-600">Linhas</p>
                <p class="text-xl font-bold text-red-900">{{ number_format($sem['linhas'], 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-red-600">Clientes</p>
                <p class="text-xl font-bold text-red-900">{{ number_format($sem['clientes'], 0, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-red-600">TPV</p>
                <p class="text-lg font-bold text-red-900">R$ {{ number_format($sem['tpv'], 2, ',', '.') }}</p>
            </div>
            <div>
                <p class="text-xs text-red-600">Comissão</p>
                <p class="text-lg font-bold text-red-900">R$ {{ number_format($sem['comissao'], 2, ',', '.') }}</p>
            </div>
        </div>
    </div>
</div>

<div class="mb-5 grid gap-3 md:grid-cols-4">
    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
        <p class="text-xs font-bold uppercase text-emerald-700">OK</p>
        <p class="mt-1 text-2xl font-bold text-emerald-800">{{ $conciliacao->linhas_ok }}</p>
    </div>
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
        <p class="text-xs font-bold uppercase text-amber-700">Divergentes</p>
        <p class="mt-1 text-2xl font-bold text-amber-800">{{ $conciliacao->linhas_divergentes }}</p>
    </div>
    <div class="rounded-xl border border-red-200 bg-red-50 p-4">
        <p class="text-xs font-bold uppercase text-red-700">Sem estabelecimento</p>
        <p class="mt-1 text-2xl font-bold text-red-800">{{ $conciliacao->linhas_sem_estabelecimento }}</p>
        <p class="mt-1 text-xs text-red-600">{{ $sem['clientes'] }} clientes distintos</p>
        @if ($sem['clientes'] > 0)
            <a href="{{ route('admin.conciliacoes.diferenca', $conciliacao) }}" class="mt-2 inline-block text-xs font-semibold text-red-800 hover:underline">
                Ver relatório
            </a>
        @endif
    </div>
    <div class="rounded-xl border border-orange-200 bg-orange-50 p-4">
        <p class="text-xs font-bold uppercase text-orange-700">Sem EDI</p>
        <p class="mt-1 text-2xl font-bold text-orange-800">{{ $conciliacao->linhas_sem_edi }}</p>
        <p class="mt-1 text-xs text-orange-600">Estão na planilha e não no EDI</p>
        <a href="{{ route('admin.conciliacoes.diferenca', $conciliacao) }}" class="mt-2 inline-block text-xs font-semibold text-orange-800 hover:underline">
            Ver relatório
        </a>
    </div>
</div>

<form class="mb-4 grid gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm md:grid-cols-5">
    <input name="busca" value="{{ $filtros['busca'] ?? '' }}" placeholder="Buscar ID, chave, bandeira..." class="rounded-lg border border-gray-200 px-3 py-2 text-sm md:col-span-2">
    <input name="id_cliente" value="{{ $filtros['id_cliente'] ?? '' }}" placeholder="ID cliente" class="rounded-lg border border-gray-200 px-3 py-2 text-sm">
    <select name="status" class="rounded-lg border border-gray-200 bg-white px-3 text-sm">
        <option value="">Status</option>
        @foreach (['ok', 'divergente', 'sem_estabelecimento', 'sem_edi', 'pendente'] as $status)
            <option value="{{ $status }}" @selected(($filtros['status'] ?? '') === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>
        @endforeach
    </select>
    <button class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Filtrar</button>
</form>

<div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
    <table class="min-w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">ID cliente</th>
                <th class="px-4 py-3">Estabelecimento</th>
                <th class="px-4 py-3">Meio / Bandeira</th>
                <th class="px-4 py-3">Parcelas</th>
                <th class="px-4 py-3">Solução</th>
                <th class="px-4 py-3 text-right">TPV PS</th>
                <th class="px-4 py-3 text-right">TPV EDI</th>
                <th class="px-4 py-3 text-right">Com. PS</th>
                <th class="px-4 py-3 text-right">Com. EDI</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($linhas as $linha)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-1 text-xs font-semibold {{ $statusClasses[$linha->status] ?? $statusClasses['pendente'] }}">
                            {{ $linha->statusLabel() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">{{ $linha->id_cliente }}</td>
                    <td class="px-4 py-3">
                        @if ($linha->estabelecimento)
                            <a href="{{ route('estabelecimentos.show', $linha->estabelecimento) }}" class="font-semibold text-blue-600 hover:underline">
                                {{ $linha->estabelecimento->nome_fantasia ?: $linha->estabelecimento->razao_social ?: $linha->estabelecimento->nome_completo }}
                            </a>
                        @else
                            <span class="text-red-600">Não cadastrado</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">{{ $linha->meio_pagamento }} / {{ $linha->bandeira }}</td>
                    <td class="px-4 py-3">{{ $linha->parcelamento_agrupado }}</td>
                    <td class="px-4 py-3">{{ $linha->solucao }}</td>
                    <td class="px-4 py-3 text-right">R$ {{ number_format($linha->tpv, 2, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right {{ abs((float) $linha->diff_tpv) > 0.02 ? 'text-amber-700 font-semibold' : '' }}">
                        {{ $linha->edi_tpv !== null ? 'R$ '.number_format($linha->edi_tpv, 2, ',', '.') : '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">R$ {{ number_format($linha->ms_comissao, 2, ',', '.') }}</td>
                    <td class="px-4 py-3 text-right {{ abs((float) $linha->diff_comissao) > 0.02 ? 'text-amber-700 font-semibold' : '' }}">
                        {{ $linha->edi_comissao !== null ? 'R$ '.number_format($linha->edi_comissao, 2, ',', '.') : '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-8 text-center text-gray-500">Nenhuma linha encontrada.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $linhas->links() }}</div>

@if ($confrontoEmAndamento)
<script>
window.setTimeout(() => window.location.reload(), 8000);
</script>
@endif
@endsection
