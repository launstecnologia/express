@props(['compact' => false])

@if ($fvPodeRetentar ?? false)
    @php
        $btnBase = $compact
            ? 'inline-flex items-center gap-1 rounded border px-2 py-1 text-[10px] font-semibold'
            : 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-bold shadow-sm';
    @endphp

    <div class="flex flex-wrap items-center gap-2">
        @if ($fvPodeRetentarEmail ?? false)
            <form method="POST"
                  action="{{ route('admin.estabelecimentos.automacao.retentar-email', $estabelecimento) }}"
                  class="inline"
                  onsubmit="return confirm('Continuar pela etapa de e-mail e senha? O cadastro no portal FV não será refeito.')">
                @csrf
                <button type="submit"
                        class="{{ $btnBase }} border-orange-300 bg-orange-600 text-white hover:bg-orange-700">
                    <i class="fa-solid fa-rotate-right"></i>
                    Continuar (e-mail)
                </button>
            </form>
        @elseif ($fvPropostaComErro && ($fvEhAdmin ?? false))
            <form method="POST"
                  action="{{ route('admin.estabelecimentos.automacao.aceitar-proposta', $estabelecimento) }}"
                  class="inline"
                  onsubmit="return confirm('Retentar aceite da proposta comercial no PagBank?')">
                @csrf
                <button type="submit"
                        class="{{ $btnBase }} border-orange-300 bg-orange-600 text-white hover:bg-orange-700">
                    <i class="fa-solid fa-rotate-right"></i>
                    Tentar novamente
                </button>
            </form>
        @elseif ($fvPodeIniciarCompleto ?? ($fvPodeIniciar ?? false))
            <button type="button"
                    data-modal-open="automacao-confirmar"
                    data-automacao-label="Confirmar e tentar novamente"
                    class="{{ $btnBase }} border-indigo-300 bg-indigo-700 text-white hover:bg-indigo-800">
                <i class="fa-solid fa-rotate-right"></i>
                Tentar novamente
            </button>
        @endif
    </div>
@endif
