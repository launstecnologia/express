@extends('layouts.app')

@section('title', 'Conciliação')

@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm text-gray-500">Fechamento mensal PagSeguro × EDI</p>
    </div>
    <a href="{{ route('admin.conciliacoes.create') }}" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
        <i class="fa-solid fa-file-import"></i>
        Importar relatório
    </a>
</div>

@if (session('status'))
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
@endif

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-5 py-3">Mês</th>
                <th class="px-5 py-3">Parceiro</th>
                <th class="px-5 py-3">Linhas</th>
                <th class="px-5 py-3">TPV</th>
                <th class="px-5 py-3">Comissão</th>
                <th class="px-5 py-3">Confronto</th>
                <th class="px-5 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($conciliacoes as $conciliacao)
                <tr class="hover:bg-gray-50">
                    <td class="px-5 py-4 font-semibold text-gray-800">{{ $conciliacao->referenciaFormatada() }}</td>
                    <td class="px-5 py-4 text-gray-600">{{ $conciliacao->parceiro ?: '—' }}</td>
                    <td class="px-5 py-4">{{ number_format($conciliacao->total_linhas, 0, ',', '.') }}</td>
                    <td class="px-5 py-4">R$ {{ number_format($conciliacao->total_tpv, 2, ',', '.') }}</td>
                    <td class="px-5 py-4 font-semibold text-blue-700">R$ {{ number_format($conciliacao->total_comissao, 2, ',', '.') }}</td>
                    <td class="px-5 py-4">
                        @if ($conciliacao->status === 'confrontado')
                            <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold text-emerald-700">
                                {{ $conciliacao->linhas_ok }} OK
                            </span>
                            @if ($conciliacao->linhas_divergentes > 0)
                                <span class="rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">
                                    {{ $conciliacao->linhas_divergentes }} div.
                                </span>
                            @endif
                            @if ($conciliacao->linhas_sem_estabelecimento > 0)
                                <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-semibold text-red-700">
                                    {{ $conciliacao->linhas_sem_estabelecimento }} s/ est.
                                </span>
                            @endif
                        @else
                            <span class="text-xs text-gray-400">Pendente</span>
                        @endif
                    </td>
                    <td class="px-5 py-4 text-right">
                        <a href="{{ route('admin.conciliacoes.show', $conciliacao) }}" class="font-semibold text-blue-600 hover:underline">Ver</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-5 py-10 text-center text-gray-500">Nenhuma conciliação importada.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $conciliacoes->links() }}</div>
@endsection
