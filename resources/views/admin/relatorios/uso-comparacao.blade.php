@extends('layouts.app')

@section('title', 'Comparação de uso')

@section('content')
<div class="mb-5">
    <h1 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Comparação de uso</h1>
    <p class="mt-1 text-sm text-gray-500">Escolha o primeiro e o último mês. O intervalo entra inteiro: agosto até outubro são 3 meses, até novembro são 4. A situação compara o primeiro mês com o último.</p>
</div>

@if ($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        {{ $errors->first() }}
    </div>
@endif

@if ($avisos !== [])
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        @foreach ($avisos as $aviso)
            <p>{{ $aviso }}</p>
        @endforeach
        <p class="mt-1">Importe a planilha desses meses na conciliação antes de comparar. Sem ela, o relatório não trata o mês como uso zero.</p>
    </div>
@endif

@php
    $nomesMeses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
    $anos = range((int) now()->format('Y'), 2020);
    [$anoBase, $numeroBase] = array_pad(explode('-', (string) $filtros['mes_base']), 2, '');
    [$anoComparado, $numeroComparado] = array_pad(explode('-', (string) $filtros['mes_comparado']), 2, '');
    foreach ([(int) $anoBase, (int) $anoComparado] as $anoSelecionado) {
        if ($anoSelecionado > 0 && ! in_array($anoSelecionado, $anos, true)) {
            $anos[] = $anoSelecionado;
        }
    }
    rsort($anos);
@endphp

<form method="GET" action="{{ route('admin.relatorios.uso-comparacao') }}" class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
    <input type="hidden" name="consultar" value="1">
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-6">
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Fonte</span>
            <select name="fonte" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="edi" @selected(($filtros['fonte'] ?? 'edi') === 'edi')>EDI</option>
                <option value="planilha" @selected(($filtros['fonte'] ?? '') === 'planilha')>Planilha PagBank</option>
            </select>
        </label>
        <label class="block space-y-1">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</span>
            <select name="marketplace_id" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="">Todos</option>
                @foreach ($marketplaces as $item)
                    <option value="{{ $item['id'] }}" @selected((int) ($filtros['marketplace_id'] ?? 0) === (int) $item['id'])>{{ $item['nome'] }}</option>
                @endforeach
            </select>
        </label>
        <div class="space-y-1 lg:col-span-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Do mês</span>
            <div class="flex gap-2">
                <select name="mes_base_mes" required class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @foreach ($nomesMeses as $numero => $nomeMes)
                        <option value="{{ $numero }}" @selected((int) $numeroBase === $numero)>{{ $nomeMes }}</option>
                    @endforeach
                </select>
                <select name="mes_base_ano" required class="w-24 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @foreach ($anos as $ano)
                        <option value="{{ $ano }}" @selected((int) $anoBase === (int) $ano)>{{ $ano }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="space-y-1 lg:col-span-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Até o mês</span>
            <div class="flex gap-2">
                <select name="mes_comparado_mes" required class="min-w-0 flex-1 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @foreach ($nomesMeses as $numero => $nomeMes)
                        <option value="{{ $numero }}" @selected((int) $numeroComparado === $numero)>{{ $nomeMes }}</option>
                    @endforeach
                </select>
                <select name="mes_comparado_ano" required class="w-24 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm tabular-nums dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                    @foreach ($anos as $ano)
                        <option value="{{ $ano }}" @selected((int) $anoComparado === (int) $ano)>{{ $ano }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label class="block space-y-1 lg:col-span-2">
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Situação</span>
            <select name="situacao" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
                <option value="mudancas" @selected($filtros['situacao'] === 'mudancas')>Parou, baixou, aumentou ou começou</option>
                <option value="parou" @selected($filtros['situacao'] === 'parou')>Parou de usar</option>
                <option value="abaixou" @selected($filtros['situacao'] === 'abaixou')>Abaixou o uso</option>
                <option value="aumentou" @selected($filtros['situacao'] === 'aumentou')>Aumentou o uso</option>
                <option value="comecou" @selected($filtros['situacao'] === 'comecou')>Começou a usar</option>
                <option value="manteve" @selected($filtros['situacao'] === 'manteve')>Manteve o uso</option>
                <option value="todas" @selected($filtros['situacao'] === 'todas')>Todas, inclusive quem manteve</option>
            </select>
        </label>
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
            <i class="fa-solid fa-magnifying-glass"></i>
            Comparar
        </button>
        @if ($consultou && $resumo)
            <button type="submit" form="form-excel" class="inline-flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-200">
                <i class="fa-solid fa-file-excel"></i>
                Baixar Excel
            </button>
        @endif
    </div>
</form>

@if ($consultou && $resumo)
    <form id="form-excel" method="GET" action="{{ route('admin.relatorios.uso-comparacao.excel') }}" class="hidden">
        <input type="hidden" name="marketplace_id" value="{{ $filtros['marketplace_id'] }}">
        <input type="hidden" name="mes_base" value="{{ $filtros['mes_base'] }}">
        <input type="hidden" name="mes_comparado" value="{{ $filtros['mes_comparado'] }}">
        <input type="hidden" name="fonte" value="{{ $filtros['fonte'] }}">
        <input type="hidden" name="situacao" value="{{ $filtros['situacao'] }}">
    </form>

    @php
        $cartoes = [
            ['Parou de usar', $resumo['parou'], 'text-red-600'],
            ['Abaixou', $resumo['abaixou'], 'text-amber-600'],
            ['Aumentou', $resumo['aumentou'], 'text-emerald-600'],
            ['Começou', $resumo['comecou'], 'text-blue-600'],
            ['Manteve', $resumo['manteve'], 'text-gray-700 dark:text-gray-200'],
            ['Variação do TPV', 'R$ '.number_format($resumo['variacao'], 2, ',', '.'), $resumo['variacao'] < 0 ? 'text-red-600' : 'text-emerald-600'],
        ];
    @endphp
    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        @foreach ($cartoes as [$rotulo, $valor, $cor])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="text-xs font-medium text-gray-500">{{ $rotulo }}</p>
                <p class="mt-1 text-xl font-bold tabular-nums {{ $cor }}">{{ is_int($valor) ? number_format($valor, 0, ',', '.') : $valor }}</p>
            </div>
        @endforeach
    </div>
    <p class="mb-3 text-xs text-gray-500">
        @foreach ($resumo['tpv_por_mes'] as $mes => $tpv)
            TPV {{ $relatorio->rotuloMes($mes) }}: R$ {{ number_format($tpv, 2, ',', '.') }}
            @if (! $loop->last) · @endif
        @endforeach
        · {{ number_format($linhas->count(), 0, ',', '.') }} estabelecimento(s) neste filtro
        · {{ count($meses) }} meses
    </p>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" style="min-width: {{ 760 + (count($meses) * 130) }}px">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">Estabelecimento</th>
                        <th class="px-4 py-3">Marketplace</th>
                        <th class="px-4 py-3">Situação</th>
                        @foreach ($meses as $mes)
                            <th class="px-4 py-3 text-right">TPV {{ $relatorio->rotuloMes($mes) }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-right">Variação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($linhas as $linha)
                        @php
                            $cor = match ($linha->situacao) {
                                'parou' => 'bg-red-100 text-red-800',
                                'abaixou' => 'bg-amber-100 text-amber-800',
                                'aumentou' => 'bg-emerald-100 text-emerald-800',
                                'comecou' => 'bg-blue-100 text-blue-800',
                                default => 'bg-gray-100 text-gray-700',
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60">
                            <td class="px-4 py-3">
                                <a href="{{ route('estabelecimentos.show', $linha->id) }}" class="font-semibold text-gray-800 hover:text-blue-700 dark:text-gray-100">{{ $relatorio->nome($linha) }}</a>
                                <p class="font-mono text-xs text-gray-400">{{ $linha->cnpj ?: $linha->cpf ?: '—' }}</p>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $relatorio->marketplace($linha) }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-1 text-xs font-bold {{ $cor }}">{{ $situacoes[$linha->situacao] ?? $linha->situacao }}</span>
                            </td>
                            @foreach ($meses as $mes)
                                <td class="px-4 py-3 text-right tabular-nums">
                                    R$ {{ number_format($linha->tpvs[$mes] ?? 0, 2, ',', '.') }}
                                    <span class="block text-xs text-gray-400">{{ number_format($linha->qtds[$mes] ?? 0, 0, ',', '.') }} {{ $filtros['fonte'] === 'planilha' ? 'linhas' : 'tx' }}</span>
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-right tabular-nums font-semibold {{ $linha->variacao < 0 ? 'text-red-600' : ($linha->variacao > 0 ? 'text-emerald-600' : 'text-gray-600') }}">
                                R$ {{ number_format($linha->variacao, 2, ',', '.') }}
                                @if ($linha->variacao_percentual !== null)
                                    <span class="block text-xs font-medium">{{ number_format($linha->variacao_percentual, 1, ',', '.') }}%</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 4 + count($meses) }}" class="px-4 py-10 text-center text-gray-500">Nenhum estabelecimento nesta comparação.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
