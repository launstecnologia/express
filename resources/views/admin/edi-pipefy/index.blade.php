@extends('layouts.app')

@section('title', 'EDI Pipefy')

@section('content')
<div class="mb-5 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">EDI Pipefy</h2>
        <p class="mt-1 text-sm text-gray-500">
            Chamados semanais de replicação do token API EDI (Safepay IDs desde {{ \Illuminate\Support\Carbon::parse($desde)->format('d/m/Y') }}).
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ $pipefyUrl }}" target="_blank" rel="noopener"
           class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
            <i class="fa-solid fa-up-right-from-square"></i>
            Abrir portal
        </a>
        <form method="POST" action="{{ route('admin.edi-pipefy.criar-email') }}">
            @csrf
            <button class="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-800 hover:bg-indigo-100">
                <i class="fa-solid fa-envelope"></i>
                Garantir {{ $emailDevolutiva }}
            </button>
        </form>
        <form method="POST" action="{{ route('admin.edi-pipefy.solicitar') }}"
              onsubmit="return confirm('Abrir chamado Pipefy com os {{ $preview['total'] }} ID(s) pendentes?')">
            @csrf
            <button @disabled($preview['total'] < 1)
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                <i class="fa-solid fa-paper-plane"></i>
                Abrir chamado agora
            </button>
        </form>
    </div>
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $errors->first() }}
    </div>
@endif

<div class="mb-5 grid gap-4 sm:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">IDs pendentes</p>
        <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ number_format($preview['total'], 0, ',', '.') }}</p>
        <p class="mt-1 text-xs text-gray-500">Ainda não enviados com sucesso</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">E-mail devolutiva</p>
        <p class="mt-1 font-mono text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $emailDevolutiva }}</p>
        <p class="mt-1 text-xs text-gray-500">Recebe o retorno do PagBank</p>
    </div>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Agenda</p>
        <p class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">Toda segunda · 09:00</p>
        <p class="mt-1 text-xs text-gray-500">Job automático <code class="text-[11px]">edi:pipefy-solicitar</code></p>
    </div>
</div>

@if ($preview['total'] > 0)
    @php
        $idsPendentesTexto = collect($preview['ids'])->implode("\n");
    @endphp
    <div class="mb-5 overflow-hidden rounded-xl border border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 px-5 py-3 dark:border-amber-900">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                Próximos IDs a enviar
                <span class="ml-1 font-normal text-amber-700 dark:text-amber-300">({{ number_format($preview['total'], 0, ',', '.') }})</span>
            </p>
            <button
                type="button"
                data-copy-ids
                class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
            >
                <i class="fa-regular fa-copy"></i>
                Copiar todos
            </button>
        </div>
        <textarea id="edi-pipefy-ids-pendentes" class="hidden" readonly>{{ $idsPendentesTexto }}</textarea>
        <div class="max-h-[28rem] overflow-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 bg-amber-100 text-left text-xs font-semibold uppercase tracking-wide text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    <tr>
                        <th class="px-5 py-2 w-12">#</th>
                        <th class="px-5 py-2">Safepay ID</th>
                        <th class="px-5 py-2">Estabelecimento</th>
                        <th class="px-5 py-2">Cadastro</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-amber-100 dark:divide-amber-900">
                    @foreach ($preview['estabelecimentos'] as $i => $ec)
                        <tr class="hover:bg-amber-100/70 dark:hover:bg-amber-900/40">
                            <td class="px-5 py-2 tabular-nums text-amber-800 dark:text-amber-300">{{ $i + 1 }}</td>
                            <td class="px-5 py-2 font-mono text-xs font-semibold text-amber-950 dark:text-amber-100">{{ $ec->token_pagseguro }}</td>
                            <td class="px-5 py-2 text-amber-950 dark:text-amber-100">
                                {{ $ec->nome_fantasia ?: $ec->razao_social ?: $ec->nome_completo ?: 'Estabelecimento #'.$ec->id }}
                                <span class="ml-1 text-xs font-normal text-amber-700 dark:text-amber-400">#{{ $ec->id }}</span>
                            </td>
                            <td class="whitespace-nowrap px-5 py-2 text-xs text-amber-800 dark:text-amber-300">
                                {{ optional($ec->created_at)->format('d/m/Y') ?: '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-amber-200 px-5 py-2 text-[11px] text-amber-800 dark:border-amber-900 dark:text-amber-300">
            Lista completa no mesmo critério do chamado (Safepay IDs ainda não enviados com sucesso).
        </p>
    </div>
    <script>
        document.querySelector('[data-copy-ids]')?.addEventListener('click', async function () {
            const texto = document.getElementById('edi-pipefy-ids-pendentes')?.value || '';
            try {
                await navigator.clipboard.writeText(texto);
                this.innerHTML = '<i class="fa-solid fa-check"></i> Copiado';
                setTimeout(() => { this.innerHTML = '<i class="fa-regular fa-copy"></i> Copiar todos'; }, 1600);
            } catch (e) {
                alert('Não foi possível copiar os IDs.');
            }
        });
    </script>
@endif

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800">
            <tr>
                <th class="px-5 py-3">#</th>
                <th class="px-5 py-3">Data</th>
                <th class="px-5 py-3">IDs</th>
                <th class="px-5 py-3">ID Origem</th>
                <th class="px-5 py-3">Status</th>
                <th class="px-5 py-3">Falha</th>
                <th class="px-5 py-3">Card</th>
                <th class="px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
            @forelse ($solicitacoes as $solicitacao)
                @php [$badgeClass, $badgeLabel] = $solicitacao->statusBadge(); @endphp
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                    <td class="px-5 py-4 font-semibold text-gray-800 dark:text-gray-100">{{ $solicitacao->id }}</td>
                    <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                        {{ optional($solicitacao->disparado_em ?? $solicitacao->created_at)->format('d/m/Y H:i') }}
                    </td>
                    <td class="px-5 py-4">{{ $solicitacao->total_ids }}</td>
                    <td class="px-5 py-4 font-mono text-xs">{{ $solicitacao->id_origem }}</td>
                    <td class="px-5 py-4">
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badgeClass }}">{{ $badgeLabel }}</span>
                    </td>
                    <td class="max-w-[220px] px-5 py-4 text-xs text-red-700 dark:text-red-300">
                        @if ($solicitacao->erro)
                            <span title="{{ $solicitacao->erro }}">{{ \Illuminate\Support\Str::limit($solicitacao->erro, 80) }}</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-5 py-4 font-mono text-xs text-gray-600">{{ $solicitacao->pipefy_card_id ?: '—' }}</td>
                    <td class="px-5 py-4 text-right">
                        <a href="{{ route('admin.edi-pipefy.show', $solicitacao) }}" class="font-semibold text-blue-600 hover:underline">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-5 py-10 text-center text-gray-500">Nenhum chamado registrado ainda.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $solicitacoes->links() }}</div>
@endsection
