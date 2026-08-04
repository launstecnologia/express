@extends('layouts.app')

@section('title', 'EDI Pipefy · #'.$solicitacao->id)

@section('content')
@php
    [$badgeClass, $badgeLabel] = $solicitacao->statusBadge();
    $resultadoJson = $solicitacao->resultado
        ? json_encode($solicitacao->resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : null;
@endphp

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

@if ($solicitacao->erro)
    <div class="mb-5 rounded-xl border border-red-300 bg-red-50 p-5 dark:border-red-900 dark:bg-red-950/40">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-circle-exclamation mt-0.5 text-lg text-red-600"></i>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold uppercase tracking-wide text-red-800 dark:text-red-200">Falha exata</p>
                @if ($etapaAtual)
                    <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-300">Etapa: {{ $etapaAtual }}</p>
                @endif
                <pre class="mt-2 whitespace-pre-wrap break-words font-mono text-sm text-red-900 dark:text-red-100">{{ $solicitacao->erro }}</pre>
            </div>
        </div>
    </div>
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
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Job automação</dt><dd class="break-all font-mono text-xs">{{ $solicitacao->automacao_job_id ?: '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-gray-500">Solicitado por</dt><dd>{{ $solicitacao->solicitadoPor?->nomeExibicao() ?? 'Agenda / CLI' }}</dd></div>
        </dl>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
        <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-700 dark:text-gray-200">Descrição enviada</h3>
        <pre class="max-h-72 overflow-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-xs text-gray-700 dark:bg-gray-800 dark:text-gray-200">{{ $solicitacao->descricao }}</pre>
    </div>
</div>

{{-- Screenshots --}}
<div class="mb-5 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-5 py-3 dark:border-gray-800">
        <div>
            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">Prints da tela (automação)</h3>
            <p class="text-xs text-gray-500">
                @if ($solicitacao->automacao_job_id)
                    Job {{ $solicitacao->automacao_job_id }}
                @else
                    Sem job de automação — prints só aparecem quando o Selenium chega a rodar.
                @endif
            </p>
        </div>
        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
            {{ count($screenshots) }} arquivo(s)
        </span>
    </div>

    <div class="p-5">
        @if (count($screenshots) === 0)
            <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-4 py-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-800/50">
                @if (blank($solicitacao->automacao_job_id))
                    Nenhum print: a falha ocorreu antes de iniciar o navegador
                    (ex.: API de automação fora do ar).
                @else
                    Nenhum screenshot retornado por este job.
                    Confira se o container <code>automacao</code> está up.
                @endif
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($screenshots as $shot)
                    <a href="{{ $shot['url'] }}" target="_blank" rel="noopener"
                       class="group overflow-hidden rounded-lg border border-gray-200 bg-gray-50 hover:border-blue-300 hover:shadow-md dark:border-gray-700 dark:bg-gray-800">
                        <div class="aspect-video overflow-hidden bg-gray-100 dark:bg-gray-900">
                            @if (! empty($shot['src']))
                                <img src="{{ $shot['src'] }}" alt="{{ $shot['arquivo'] }}"
                                     class="h-full w-full object-cover object-top transition group-hover:scale-[1.02]">
                            @else
                                <div class="flex h-full items-center justify-center text-xs text-gray-400">
                                    Falha ao carregar print
                                </div>
                            @endif
                        </div>
                        <div class="border-t border-gray-100 px-3 py-2 dark:border-gray-700">
                            <p class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $shot['rotulo'] }}</p>
                            <p class="truncate font-mono text-[10px] text-gray-400">{{ $shot['arquivo'] }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>

@if ($resultadoJson)
    <details class="mb-5 rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <summary class="cursor-pointer px-5 py-3 text-sm font-bold text-gray-700 dark:text-gray-200">
            Resultado técnico (JSON)
        </summary>
        <pre class="max-h-96 overflow-auto border-t border-gray-100 p-4 text-xs text-gray-700 dark:border-gray-800 dark:text-gray-200">{{ $resultadoJson }}</pre>
    </details>
@endif

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <div class="border-b border-gray-100 px-5 py-3 dark:border-gray-800">
        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">IDs incluídos</h3>
    </div>
    <div class="max-h-[28rem] overflow-auto">
        <table class="w-full text-sm">
            <thead class="sticky top-0 bg-gray-50 text-left text-xs font-semibold uppercase text-gray-500 dark:bg-gray-800">
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
</div>
@endsection
