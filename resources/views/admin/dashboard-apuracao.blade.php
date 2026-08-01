<div class="dashboard-apuracao mt-6 rounded-2xl border border-blue-100 bg-gradient-to-br from-blue-50 via-white to-sky-50 p-5 shadow-sm dark:border-gray-700 dark:from-gray-900 dark:via-gray-900 dark:to-gray-950">
    <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100">Apuração das Transações</h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">Detalhamento por plano com base no EDI (últimos {{ $periodo }} dias).</p>
        </div>
        <span class="inline-flex w-fit items-center gap-2 rounded-full bg-blue-600 px-3 py-1 text-xs font-semibold text-white shadow-sm">
            <i class="fa-solid fa-layer-group"></i>
            @if (\App\Support\UsuarioComercial::ehMarketplaceOuRevenda())
                Meus planos
            @else
                Planos ativos
            @endif
        </span>
    </div>

    <div class="space-y-4">
        @if (count($planosResumo) > 0)
            <div
                x-data="{
                    current: 0,
                    total: {{ count($planosResumo) }},
                    scrollTo(index) {
                        const track = this.$refs.track;
                        const cards = track ? [...track.querySelectorAll('[data-carousel-card]')] : [];
                        const card = cards[index];
                        if (! card) return;
                        card.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
                        this.current = index;
                    },
                    prev() { this.scrollTo(Math.max(0, this.current - 1)); },
                    next() { this.scrollTo(Math.min(this.total - 1, this.current + 1)); },
                    onScroll() {
                        const track = this.$refs.track;
                        if (! track) return;
                        const cards = [...track.querySelectorAll('[data-carousel-card]')];
                        const center = track.scrollLeft + track.clientWidth / 2;
                        let closest = 0;
                        let minDist = Infinity;
                        cards.forEach((card, i) => {
                            const cardCenter = card.offsetLeft + card.offsetWidth / 2;
                            const dist = Math.abs(cardCenter - center);
                            if (dist < minDist) { minDist = dist; closest = i; }
                        });
                        this.current = closest;
                    },
                }"
                class="relative"
            >
                @if (count($planosResumo) > 1)
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <span x-text="current + 1"></span> / {{ count($planosResumo) }} planos
                        </p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="prev()"
                                :disabled="current === 0"
                                :class="current === 0 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-blue-100 dark:hover:bg-gray-700'"
                                class="flex h-9 w-9 items-center justify-center rounded-full border border-blue-200 bg-white text-blue-600 shadow-sm transition dark:border-gray-600 dark:bg-gray-800 dark:text-blue-400"
                                aria-label="Plano anterior"
                            >
                                <i class="fa-solid fa-chevron-left text-sm"></i>
                            </button>
                            <button
                                type="button"
                                @click="next()"
                                :disabled="current >= total - 1"
                                :class="current >= total - 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-blue-100 dark:hover:bg-gray-700'"
                                class="flex h-9 w-9 items-center justify-center rounded-full border border-blue-200 bg-white text-blue-600 shadow-sm transition dark:border-gray-600 dark:bg-gray-800 dark:text-blue-400"
                                aria-label="Próximo plano"
                            >
                                <i class="fa-solid fa-chevron-right text-sm"></i>
                            </button>
                        </div>
                    </div>
                @endif

                <div
                    x-ref="track"
                    @scroll.debounce.50ms="onScroll()"
                    class="dashboard-planos-carousel flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth pb-1"
                >
                    @foreach ($planosResumo as $planoResumo)
                        @php
                            $totalPartes = max($planoResumo['debito'] + $planoResumo['credito'] + $planoResumo['parcelado'] + $planoResumo['pix'], 0.01);
                            $debitoPct = round(($planoResumo['debito'] / $totalPartes) * 100, 2);
                            $creditoPct = round(($planoResumo['credito'] / $totalPartes) * 100, 2);
                            $parceladoPct = round(($planoResumo['parcelado'] / $totalPartes) * 100, 2);
                            $pixPct = round(($planoResumo['pix'] / $totalPartes) * 100, 2);
                            $debitoEnd = $debitoPct;
                            $creditoEnd = $debitoEnd + $creditoPct;
                            $parceladoEnd = $creditoEnd + $parceladoPct;
                            $itensPlano = [
                                ['label' => 'Débito', 'valor' => $planoResumo['debito'], 'percentual' => $debitoPct, 'cor' => 'bg-amber-400'],
                                ['label' => 'Crédito à vista', 'valor' => $planoResumo['credito'], 'percentual' => $creditoPct, 'cor' => 'bg-emerald-500'],
                                ['label' => 'Parcelado', 'valor' => $planoResumo['parcelado'], 'percentual' => $parceladoPct, 'cor' => 'bg-blue-500'],
                                ['label' => 'PIX', 'valor' => $planoResumo['pix'], 'percentual' => $pixPct, 'cor' => 'bg-rose-500'],
                            ];
                        @endphp

                        <div
                            data-carousel-card="{{ $loop->index }}"
                            class="w-[min(100%,22rem)] shrink-0 snap-start sm:w-[24rem] lg:w-[26rem] xl:w-[28rem]"
                        >
                            <div class="flex h-full flex-col rounded-2xl border border-blue-100 bg-white p-4 shadow-sm shadow-blue-100/60 dark:border-gray-700 dark:bg-gray-900 dark:shadow-none">
                                <div class="space-y-3 border-b border-gray-100 pb-3 dark:border-gray-700">
                                    <p
                                        class="line-clamp-2 text-xs font-bold uppercase leading-snug tracking-wide text-blue-700 dark:text-blue-300"
                                        title="{{ $planoResumo['nome'] }}"
                                    >
                                        {{ $planoResumo['nome'] }}
                                    </p>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="min-w-0">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Faturamento</p>
                                            <p class="mt-0.5 text-sm font-bold tabular-nums leading-tight text-gray-900 sm:text-base dark:text-gray-100">R$ {{ number_format($planoResumo['faturamento'], 2, ',', '.') }}</p>
                                        </div>
                                        <div class="min-w-0 text-right">
                                            <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">Comissão</p>
                                            <p class="mt-0.5 text-sm font-bold tabular-nums leading-tight text-blue-700 sm:text-base dark:text-blue-400">R$ {{ number_format($planoResumo['comissao'], 2, ',', '.') }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="my-5 flex justify-center">
                                    <div class="relative h-32 w-32 shrink-0 rounded-full shadow-inner sm:h-36 sm:w-36" style="background: conic-gradient(#f59e0b 0 {{ $debitoEnd }}%, #10b981 {{ $debitoEnd }}% {{ $creditoEnd }}%, #3b82f6 {{ $creditoEnd }}% {{ $parceladoEnd }}%, #f43f5e {{ $parceladoEnd }}% 100%);">
                                        <div class="absolute inset-8 rounded-full border border-blue-50 bg-white dark:border-gray-600 dark:bg-gray-900 sm:inset-9"></div>
                                    </div>
                                </div>

                                <div class="mt-auto space-y-2.5">
                                    @foreach ($itensPlano as $item)
                                        <div class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2 text-xs sm:text-sm">
                                            <span class="flex min-w-0 items-center gap-2 text-gray-500 dark:text-gray-400">
                                                <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $item['cor'] }}"></span>
                                                <span class="truncate">{{ $item['label'] }}</span>
                                            </span>
                                            <span class="shrink-0 font-semibold tabular-nums text-gray-900 dark:text-gray-100">R$ {{ number_format($item['valor'], 2, ',', '.') }}</span>
                                            <span class="w-10 shrink-0 text-right text-[11px] tabular-nums text-gray-400 sm:w-12">{{ number_format($item['percentual'], 1, ',', '.') }}%</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (count($planosResumo) > 1)
                    <div class="mt-3 flex justify-center gap-1.5">
                        @foreach ($planosResumo as $planoResumo)
                            <button
                                type="button"
                                @click="scrollTo({{ $loop->index }})"
                                :class="current === {{ $loop->index }} ? 'w-6 bg-blue-600' : 'w-2 bg-gray-300 dark:bg-gray-600'"
                                class="h-2 rounded-full transition-all"
                                aria-label="Ir para {{ $planoResumo['nome'] }}"
                            ></button>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-blue-200 bg-white px-6 py-12 text-center dark:border-gray-600 dark:bg-gray-900">
                <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Nenhuma transação EDI no período selecionado.</p>
                <p class="mt-1 text-xs text-gray-400">Os totais são calculados no banco por plano, sem carregar transação a transação.</p>
            </div>
        @endif

        <div class="rounded-2xl border border-blue-100 bg-white p-4 shadow-sm shadow-blue-100/60 dark:border-gray-700 dark:bg-gray-900 dark:shadow-none">
            <div class="mb-4 flex items-center gap-2">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950 dark:text-blue-400">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </span>
                <div>
                    <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100">Resumo Financeiro</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Consolidado dos planos no período</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 2xl:grid-cols-6">
                @foreach ([
                    ['label' => 'Faturamento', 'valor' => $resumoPlanos['faturamento_total'], 'cor' => 'text-gray-900 dark:text-gray-100'],
                    ['label' => 'Comissão', 'valor' => $resumoPlanos['comissao_total'], 'cor' => 'text-blue-700 dark:text-blue-400'],
                    ['label' => 'PIX', 'valor' => $resumoPlanos['pix_total'], 'cor' => 'text-rose-600 dark:text-rose-400'],
                    ['label' => 'Débito', 'valor' => $resumoPlanos['debito_total'], 'cor' => 'text-amber-600 dark:text-amber-400'],
                    ['label' => 'Crédito à vista', 'valor' => $resumoPlanos['credito_total'], 'cor' => 'text-emerald-600 dark:text-emerald-400'],
                    ['label' => 'Parcelado', 'valor' => $resumoPlanos['parcelado_total'] ?? 0, 'cor' => 'text-blue-600 dark:text-blue-400'],
                ] as $card)
                    <div class="rounded-xl border border-gray-100 bg-gray-50 px-3 py-3 dark:border-gray-600 dark:bg-gray-800">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $card['label'] }}</p>
                        <p class="mt-1 text-base font-bold leading-tight {{ $card['cor'] }} sm:text-lg">
                            R$ {{ number_format($card['valor'], 2, ',', '.') }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 gap-4 xl:grid-cols-2">
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Transações por Status</h3>
                <p class="text-xs text-gray-400">Últimos {{ $periodo }} dias · {{ $transacoesStatus['total'] }} transação(ões)</p>
            </div>
            <a href="{{ route('relatorios.faturamento') }}" class="text-xs text-blue-500 hover:underline dark:text-blue-400">↗ Faturamento</a>
        </div>
        @if ($transacoesStatus['total'] > 0)
            <div class="flex min-h-72 items-center justify-center">
                <div class="relative h-52 w-52 rounded-full shadow-inner" style="background: {{ $transacoesStatus['gradiente'] }};">
                    <div class="absolute inset-14 rounded-full bg-white dark:bg-gray-800"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ $transacoesStatus['total'] }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-gray-400">transações</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap justify-center gap-3">
                @foreach ($transacoesStatus['itens'] as $item)
                    <span class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                        <span class="inline-block h-3 w-3 rounded-full" style="background-color: {{ $item['cor'] }}"></span>
                        {{ $item['label'] }} ({{ $item['quantidade'] }})
                    </span>
                @endforeach
            </div>
        @else
            <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400">Nenhuma transação no período.</p>
        @endif
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-200">Faturamento por Bandeira</h3>
                <p class="text-xs text-gray-400">Últimos {{ $periodo }} dias · valores do EDI</p>
            </div>
            <a href="{{ route('relatorios.faturamento') }}" class="text-xs text-blue-500 hover:underline dark:text-blue-400">↗ Faturamento</a>
        </div>
        <div class="space-y-3 pt-2">
            @forelse ($faturamentoBandeiras as $bandeira)
                <div class="grid grid-cols-[120px_1fr_auto] items-center gap-3">
                    <span class="flex items-center gap-2 truncate text-xs font-semibold text-gray-600 dark:text-gray-400">
                        <x-instituicao-icone :codigo="$bandeira['codigo']" size="sm" />
                        <span class="truncate">{{ $bandeira['label'] }}</span>
                    </span>
                    <div class="h-4 overflow-hidden rounded bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded bg-blue-500 transition-all" style="width: {{ $bandeira['barra_pct'] }}%"></div>
                    </div>
                    <span class="text-right text-xs font-semibold text-gray-700 dark:text-gray-200">R$ {{ number_format($bandeira['valor'], 2, ',', '.') }}</span>
                </div>
            @empty
                <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400">Nenhum faturamento por bandeira no período.</p>
            @endforelse
        </div>
    </div>
</div>

<style>
    .dashboard-planos-carousel {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .dashboard-planos-carousel::-webkit-scrollbar {
        display: none;
    }
</style>
