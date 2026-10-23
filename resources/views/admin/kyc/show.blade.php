@extends('layouts.app')

@section('title', 'KYC — Detalhes')

@section('content')
@php
    $nomeEstab = $estabelecimento
        ? ($estabelecimento->nome_fantasia ?: $estabelecimento->razao_social ?: $estabelecimento->nome_completo ?: 'Estabelecimento #'.$kyc->estabelecimento_id)
        : 'Estabelecimento removido #'.$kyc->estabelecimento_id;
    $documento = $estabelecimento ? ($estabelecimento->cnpj ?: $estabelecimento->cpf ?: '—') : '—';
@endphp

<div class="mb-4 text-sm text-gray-500">
    <a href="{{ route('admin.kyc.index') }}" class="font-semibold text-gray-700 hover:text-blue-600">KYC</a>
    <span class="mx-2">›</span>
    <span>Detalhes</span>
</div>

<div class="space-y-6">
    <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-bold text-gray-800">{{ $nomeEstab }}</h2>
        <p class="text-sm text-gray-500">
            @if ($estabelecimento)
                {{ $estabelecimento->pessoa_tipo === 'fisica' ? 'CPF' : 'CNPJ' }} {{ $documento }}
                @if (! $estabelecimento->ativo)
                    <span class="ml-2 rounded bg-gray-200 px-2 py-0.5 text-xs font-semibold text-gray-600">Cadastro inativo</span>
                @endif
            @else
                Estabelecimento não encontrado no sistema
            @endif
        </p>
        <p class="mt-2">
            <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-700">KYC: {{ str_replace('_', ' ', ucfirst($kyc->status)) }}</span>
        </p>
    </div>

    @if ($kyc->receita_consultado)
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-gray-500">Receita Federal</h3>
            <div class="grid gap-3 text-sm md:grid-cols-3">
                <p><span class="font-semibold text-gray-700">Situação:</span> {{ $kyc->receita_situacao ?: '—' }}</p>
                <p><span class="font-semibold text-gray-700">Nome:</span> {{ $kyc->receita_nome ?: '—' }}</p>
                <p><span class="font-semibold text-gray-700">Consultado:</span> {{ $kyc->receita_consultado_em?->format('d/m/Y H:i') }}</p>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-5 py-4">
            <h3 class="text-sm font-semibold text-gray-700">Documentos</h3>
            @php $qtdPendentes = $kyc->documentos->where('openai_status', 'pendente')->whereNull('openai_analisado_em')->count(); @endphp
            @if ($qtdPendentes > 0)
                <form method="POST" action="{{ route('admin.kyc.processar-pendentes', $kyc) }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">
                        <i class="fa-solid fa-play mr-1"></i> Processar {{ $qtdPendentes }} pendente(s)
                    </button>
                </form>
            @endif
        </div>
        <div class="divide-y divide-gray-100">
            @forelse ($kyc->documentos as $doc)
                @php
                    $status = $doc->statusEfetivo();
                    $statusClass = match ($status) {
                        'aprovado' => 'text-green-600',
                        'reprovado' => 'text-red-600',
                        default => 'text-yellow-600',
                    };
                @endphp
                <div class="grid gap-4 p-5 lg:grid-cols-[200px_1fr]">
                    <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
                        @if (str_starts_with($doc->mime_type, 'image/'))
                            <img src="{{ route('admin.kyc.documentos.arquivo', $doc) }}" alt="" class="max-h-48 w-full object-contain">
                        @else
                            <a href="{{ route('admin.kyc.documentos.arquivo', $doc) }}" target="_blank" class="flex h-32 items-center justify-center text-sm text-blue-600">
                                <i class="fa-solid fa-file-pdf mr-2"></i> Abrir PDF
                            </a>
                        @endif
                    </div>
                    <div>
                        <p class="font-semibold text-gray-800">{{ \App\Support\KycDocumentosObrigatorios::labelTipo($doc->tipo) }}</p>
                        <p class="mt-1 text-sm {{ $statusClass }}">Didit: {{ strtoupper(str_replace('_', ' ', $status)) }}</p>
                        <p class="text-sm text-gray-500">Cruzamento: {{ str_replace('_', ' ', $doc->cruzamento_status) }}</p>
                        @if ($doc->cruzamento_status === 'divergencia')
                            @include('partials.kyc-divergencia-alerta', [
                                'documento' => $doc,
                                'estabelecimento' => $estabelecimento,
                                'mostrarBotaoReenvio' => false,
                            ])
                            @if ($doc->cruzamento_divergencias)
                                <details class="mt-2">
                                    <summary class="cursor-pointer text-xs font-semibold text-gray-500">Detalhes técnicos (JSON)</summary>
                                    <pre class="mt-1 overflow-x-auto rounded bg-red-50 p-2 text-xs text-red-800">{{ json_encode($doc->cruzamento_divergencias, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @endif
                        @elseif ($doc->openai_motivo_reprovacao)
                            <p class="mt-2 text-sm text-red-600">{{ $doc->openai_motivo_reprovacao }}</p>
                        @endif
                        @if ($doc->openai_dados_extraidos)
                            <details class="mt-2">
                                <summary class="cursor-pointer text-xs font-semibold text-gray-500">Dados extraídos</summary>
                                <pre class="mt-1 overflow-x-auto rounded bg-gray-50 p-2 text-xs">{{ json_encode($doc->openai_dados_extraidos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </details>
                        @endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('admin.kyc.documentos.override', $doc) }}">
                                @csrf
                                <input type="hidden" name="decisao" value="aprovado">
                                <button type="submit" class="rounded-lg border border-green-200 bg-green-50 px-3 py-1.5 text-xs font-semibold text-green-700">Aprovar manualmente</button>
                            </form>
                            <form method="POST" action="{{ route('admin.kyc.documentos.override', $doc) }}">
                                @csrf
                                <input type="hidden" name="decisao" value="reprovado">
                                <button type="submit" class="rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700">Reprovar</button>
                            </form>
                            <form method="POST" action="{{ route('admin.kyc.documentos.reanalise', $doc) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-semibold text-gray-700">Reanalisar (Didit)</button>
                            </form>
                        </div>
                    </div>
                </div>
            @empty
                <p class="p-5 text-sm text-gray-500">Nenhum documento enviado.</p>
            @endforelse
        </div>
    </div>

    @if (in_array($kyc->status, ['em_analise', 'revisao_manual'], true))
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 text-sm font-semibold text-gray-700">Decisão final do Admin</h3>
            <form method="POST" action="{{ route('admin.kyc.reprovar', $kyc) }}" class="mb-4 space-y-3">
                @csrf
                <label class="block space-y-1">
                    <span class="text-xs font-semibold uppercase text-gray-500">Motivo (obrigatório para reprovar)</span>
                    <textarea name="motivo" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" placeholder="Descreva o motivo da reprovação..."></textarea>
                </label>
                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">Reprovar KYC</button>
            </form>
            <form method="POST" action="{{ route('admin.kyc.aprovar', $kyc) }}">
                @csrf
                <input type="hidden" name="motivo" value="Aprovado pelo administrador">
                <button type="submit" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Aprovar KYC</button>
            </form>
        </div>
    @endif

    @if ($kyc->status === 'aprovado')
        <div class="rounded-xl border border-indigo-100 bg-indigo-50/40 p-5 shadow-sm">
            <h3 class="mb-3 text-sm font-bold uppercase tracking-wide text-indigo-800">PagBank</h3>
            <p class="mb-4 text-sm text-indigo-900">KYC aprovado — o cadastro da conta SELLER é enviado automaticamente para a API PagBank.</p>
            @include('estabelecimento.partials.pagbank-status', ['estabelecimento' => $estabelecimento])
            <a href="{{ route('estabelecimentos.show', $estabelecimento) }}" class="mt-4 inline-flex items-center gap-1 text-sm font-semibold text-indigo-700 hover:underline">
                Ver detalhes do estabelecimento <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    @endif

    @if ($kyc->admin_decisao)
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
            <p><span class="font-semibold">Decisão:</span> {{ $kyc->admin_decisao }} em {{ $kyc->admin_decidido_em?->format('d/m/Y H:i') }}</p>
            @if ($kyc->admin_motivo)<p class="mt-1">{{ $kyc->admin_motivo }}</p>@endif
        </div>
    @endif
</div>
@endsection
