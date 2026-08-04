@extends('layouts.app')

@section('title', 'EDI Pipefy · #'.$solicitacao->id)

@section('content')
@php [$badgeClass, $badgeLabel] = $solicitacao->statusBadge(); @endphp

<div class="mb-5">
    <a href="{{ route('admin.edi-pipefy.index') }}" class="mb-2 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-800">
        <i class="fa-solid fa-arrow-left"></i> EDI Pipefy
    </a>
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Solicitação #{{ $solicitacao->id }}</h2>
        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">{{ $badgeLabel }}</span>
    </div>
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
@endif

<div class="mb-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase text-gray-500">Disparado em</p>
        <p class="mt-1 text-sm font-semibold">{{ optional($solicitacao->disparado_em)->format('d/m/Y H:i') ?: '—' }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase text-gray-500">Concluído em</p>
        <p class="mt-1 text-sm font-semibold">{{ optional($solicitacao->concluido_em)->format('d/m/Y H:i') ?: '—' }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase text-gray-500">Total de IDs</p>
        <p class="mt-1 text-sm font-semibold">{{ $solicitacao->total_ids }}</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase text-gray-500">Card Pipefy</p>
        <p class="mt-1 font-mono text-sm font-semibold">{{ $solicitacao->pipefy_card_id ?: '—' }}</p>
    </div>
</div>

<div class="mb-5 grid gap-4 lg:grid-cols-2">
    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
        <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-700 dark:text-gray-200">Dados do chamado</h3>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Tipo</dt><dd class="font-medium">{{ $solicitacao->tipo }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">E-mail</dt><dd class="font-mono text-xs">{{ $solicitacao->email_devolutiva }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">ID Origem</dt><dd class="font-mono">{{ $solicitacao->id_origem }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Job automação</dt><dd class="font-mono text-xs">{{ $solicitacao->automacao_job_id ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Solicitado por</dt><dd>{{ $solicitacao->solicitadoPor?->nomeExibicao() ?? 'Agenda / CLI' }}</dd></div>
        </dl>
        @if ($solicitacao->erro)
            <div class="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                {{ $solicitacao->erro }}
            </div>
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
        <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-700 dark:text-gray-200">Descrição enviada</h3>
        <pre class="max-h-72 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $solicitacao->descricao }}</pre>
    </div>
</div>

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">IDs incluídos</h3>
    </div>
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800">
            <tr>
                <th class="px-5 py-3">Safepay ID</th>
                <th class="px-5 py-3">Estabelecimento</th>
                <th class="px-5 py-3">ID interno</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach ($solicitacao->itens as $item)
                <tr>
                    <td class="px-5 py-3 font-mono text-xs font-semibold">{{ $item->token_pagseguro }}</td>
                    <td class="px-5 py-3">
                        @if ($item->estabelecimento)
                            <a href="{{ route('estabelecimentos.show', $item->estabelecimento) }}" class="text-blue-600 hover:underline">
                                {{ $item->estabelecimento->nome_fantasia ?: $item->estabelecimento->razao_social ?: $item->estabelecimento->nome_completo }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-5 py-3 text-gray-500">{{ $item->estabelecimento_id ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
