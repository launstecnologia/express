@extends('layouts.app')

@section('title', 'KYC')

@section('content')
@php
    $nomeEstab = $estabelecimento->nome_fantasia ?: $estabelecimento->razao_social ?: $estabelecimento->nome_completo;
    $statusKycClass = match ($kyc->status) {
        'aprovado' => 'bg-green-100 text-green-700',
        'reprovado' => 'bg-red-100 text-red-700',
        'em_analise', 'revisao_manual' => 'bg-yellow-100 text-yellow-700',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<div class="mb-4 text-sm text-gray-500">
    <a href="{{ route('estabelecimentos.show', $estabelecimento) }}" class="font-semibold text-gray-700 hover:text-blue-600">Estabelecimento</a>
    <span class="mx-2">›</span>
    <span>KYC</span>
</div>

<div class="space-y-6">
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-gray-800">{{ $nomeEstab }}</h2>
                <p class="mt-1 text-sm text-gray-500">Validação de identidade (KYC)</p>
            </div>
            <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $statusKycClass }}">{{ str_replace('_', ' ', ucfirst($kyc->status)) }}</span>
        </div>

        @if (! $kycAtivo)
            <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">Módulo KYC desativado pelo administrador.</p>
        @elseif (! $diditConfigurado)
            <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">DIDIT_API_KEY não configurada — documentos irão para revisão manual do Admin.</p>
        @endif

        @if ($kyc->receita_consultado)
            <div class="mt-4 grid gap-3 rounded-xl bg-gray-50 p-4 text-sm md:grid-cols-3">
                <div>
                    <p class="text-xs font-bold uppercase text-gray-400">Receita Federal</p>
                    <p class="mt-1 font-semibold text-gray-800">{{ $kyc->receita_situacao ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase text-gray-400">Nome na Receita</p>
                    <p class="mt-1 font-semibold text-gray-800">{{ $kyc->receita_nome ?: '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase text-gray-400">Consultado em</p>
                    <p class="mt-1 text-gray-700">{{ $kyc->receita_consultado_em?->format('d/m/Y H:i') ?: '—' }}</p>
                </div>
            </div>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-700">Documentos e análise</h3>
                <p class="text-xs text-gray-400">Os arquivos vêm da aba <strong>Documentos</strong> do estabelecimento. Ao anexar lá, a análise KYC dispara automaticamente.</p>
            </div>
            <a
                href="{{ route('estabelecimentos.show', $estabelecimento) }}#documentos"
                class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
            >
                <i class="fa-solid fa-file-arrow-up text-xs"></i>
                Anexar documentos
            </a>
        </div>

        <div class="grid gap-4 p-5 md:grid-cols-2">
            @foreach ($itens as $item)
                @php
                    $kycDoc = $item['kyc_documento'];
                    $estabDoc = $item['estabelecimento_documento'];
                    $statusDoc = $kycDoc?->statusEfetivo() ?? ($estabDoc ? 'aguardando_analise' : 'pendente');
                    $divergenciasDoc = $kycDoc?->cruzamento_divergencias ?? [];
                    $provavelOcrDoc = $kycDoc && $kycDoc->cruzamento_status === 'divergencia'
                        && \App\Support\KycDivergenciaHelper::provavelErroLeitura($divergenciasDoc);
                    $statusDocLabel = $provavelOcrDoc
                        ? 'Reenviar foto'
                        : str_replace('_', ' ', ucfirst($statusDoc));
                    $statusDocClass = match ($statusDoc) {
                        'aprovado' => 'text-green-600',
                        'reprovado' => 'text-red-600',
                        'processando' => 'text-blue-600',
                        'revisao_manual', 'aguardando_analise' => 'text-yellow-600',
                        default => 'text-gray-400',
                    };
                @endphp
                <div class="rounded-xl border {{ $estabDoc ? 'border-emerald-100 bg-emerald-50/30' : 'border-gray-100 bg-gray-50' }} p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-semibold text-gray-800">{{ $item['label'] }}</p>
                        <span class="text-xs font-bold {{ $statusDocClass }}">{{ $statusDocLabel }}</span>
                    </div>
                    @if ($estabDoc)
                        <p class="mt-1 truncate text-xs text-gray-500">{{ $estabDoc->arquivo_nome ?: basename($estabDoc->arquivo_path) }}</p>
                        @if ($kycDoc && $kycDoc->cruzamento_status === 'divergencia')
                            @include('partials.kyc-divergencia-alerta', [
                                'documento' => $kycDoc,
                                'estabelecimento' => $estabelecimento,
                            ])
                        @elseif ($kycDoc?->openai_motivo_reprovacao)
                            <p class="mt-2 text-xs text-red-600">{{ $kycDoc->openai_motivo_reprovacao }}</p>
                        @endif
                    @else
                        <p class="mt-1 text-xs text-gray-400">Anexe na aba Documentos</p>
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @if ($kyc->historico->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-3 text-sm font-semibold text-gray-700">Histórico</h3>
            <ul class="space-y-2 text-sm text-gray-600">
                @foreach ($kyc->historico->take(15) as $item)
                    <li class="flex justify-between gap-4 border-b border-gray-50 pb-2">
                        <span>{{ str_replace('_', ' ', $item->evento) }} — {{ $item->descricao }}</span>
                        <span class="shrink-0 text-xs text-gray-400">{{ $item->created_at?->format('d/m/Y H:i') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endsection
