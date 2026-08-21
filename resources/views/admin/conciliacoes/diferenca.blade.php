@extends('layouts.app')

@section('title', 'Diferença da conciliação · '.$conciliacao->referenciaFormatada())

@section('content')
<div class="mb-5">
    <a href="{{ route('admin.conciliacoes.show', $conciliacao) }}" class="mb-2 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-800">
        <i class="fa-solid fa-arrow-left"></i> Voltar à conciliação
    </a>
    <h2 class="text-xl font-bold text-gray-800">Diferença · {{ $conciliacao->referenciaFormatada() }}</h2>
    <p class="text-sm text-gray-500">Estabelecimentos da planilha que não casaram com o EDI ou não estão cadastrados.</p>
</div>

<div class="mb-5 grid gap-3 md:grid-cols-3">
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <p class="text-xs font-bold uppercase tracking-wide text-gray-400">TPV só no relatório</p>
        <p class="mt-2 text-2xl font-bold text-orange-800">R$ {{ number_format($resumo['tpv_so_relatorio'], 2, ',', '.') }}</p>
    </div>
    <div class="rounded-xl border border-red-200 bg-red-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-red-700">Sem cadastro</p>
        <p class="mt-2 text-2xl font-bold text-red-800">{{ number_format($semCadastro->count(), 0, ',', '.') }}</p>
        <p class="mt-1 text-xs text-red-600">clientes PagSeguro não encontrados na plataforma</p>
    </div>
    <div class="rounded-xl border border-orange-200 bg-orange-50 p-4">
        <p class="text-xs font-bold uppercase tracking-wide text-orange-700">Sem EDI</p>
        <p class="mt-2 text-2xl font-bold text-orange-800">{{ number_format($semEdi->count(), 0, ',', '.') }}</p>
        <p class="mt-1 text-xs text-orange-600">estabelecimentos na planilha sem match no EDI</p>
    </div>
</div>

<div class="mb-8">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-base font-bold text-gray-800">Não cadastrados na plataforma</h3>
        @if ($semCadastro->isNotEmpty())
            <a href="{{ route('admin.conciliacoes.relatorio-sem-estabelecimento', $conciliacao) }}"
               class="inline-flex items-center gap-1 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                <i class="fa-solid fa-download"></i> CSV
            </a>
        @endif
    </div>
    <p class="mb-3 text-sm text-gray-500">ID cliente da planilha PagSeguro sem token correspondente em Estabelecimentos.</p>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">ID cliente</th>
                    <th class="px-4 py-3 text-right">Linhas</th>
                    <th class="px-4 py-3 text-right">TPV</th>
                    <th class="px-4 py-3 text-right">Comissão</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($semCadastro as $cliente)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $cliente->id_cliente }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($cliente->linhas, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($cliente->tpv, 2, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($cliente->comissao, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-gray-500">Nenhum cliente sem cadastro.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($semCadastro->isNotEmpty())
                <tfoot class="bg-gray-50 font-semibold">
                    <tr>
                        <td class="px-4 py-3">Total</td>
                        <td class="px-4 py-3 text-right">{{ number_format($semCadastro->sum('linhas'), 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($semCadastro->sum('tpv'), 2, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($semCadastro->sum('comissao'), 2, ',', '.') }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

<div>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-base font-bold text-gray-800">Na planilha, não encontrados no EDI</h3>
        @if ($semEdi->isNotEmpty())
            <a href="{{ route('admin.conciliacoes.relatorio-sem-edi', $conciliacao) }}"
               class="inline-flex items-center gap-1 rounded-lg border border-orange-300 bg-white px-3 py-1.5 text-xs font-semibold text-orange-700 hover:bg-orange-100">
                <i class="fa-solid fa-download"></i> CSV
            </a>
        @endif
    </div>
    <p class="mb-3 text-sm text-gray-500">Estão cadastrados e no relatório PagSeguro, mas nenhuma chave bateu com o EDI do mês.</p>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Estabelecimento</th>
                    <th class="px-4 py-3">ID cliente</th>
                    <th class="px-4 py-3 text-right">Linhas</th>
                    <th class="px-4 py-3 text-right">TPV</th>
                    <th class="px-4 py-3 text-right">Comissão</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($semEdi as $cliente)
                    @php
                        $estab = $cliente->estabelecimento;
                        $nome = $estab?->nome_fantasia ?: $estab?->razao_social ?: $estab?->nome_completo;
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            @if ($estab)
                                <a href="{{ route('estabelecimentos.show', $estab) }}" class="font-semibold text-blue-600 hover:underline">
                                    {{ $nome }}
                                </a>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $cliente->id_cliente }}</td>
                        <td class="px-4 py-3 text-right">{{ number_format($cliente->linhas, 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($cliente->tpv, 2, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($cliente->comissao, 2, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">Nenhum estabelecimento só na planilha.</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($semEdi->isNotEmpty())
                <tfoot class="bg-gray-50 font-semibold">
                    <tr>
                        <td class="px-4 py-3" colspan="2">Total</td>
                        <td class="px-4 py-3 text-right">{{ number_format($semEdi->sum('linhas'), 0, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($semEdi->sum('tpv'), 2, ',', '.') }}</td>
                        <td class="px-4 py-3 text-right">R$ {{ number_format($semEdi->sum('comissao'), 2, ',', '.') }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
@endsection
