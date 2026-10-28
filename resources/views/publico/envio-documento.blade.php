<!doctype html>
<html lang="pt-BR">
<head>
    @include('partials.head-meta', [
        'pageTitle' => 'Documentos e aceite',
        'description' => 'Envie os documentos do estabelecimento, confira as taxas e assine o aceite.',
        'robots' => 'noindex, nofollow',
    ])
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { padding-bottom: calc(env(safe-area-inset-bottom) + 8rem); }
        #quadro-assinatura { touch-action: none; -webkit-user-select: none; user-select: none; }
        input, select, textarea, button { font-size: 16px; }
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
@php
    $aceite = $estabelecimento->relationLoaded('aceite') ? $estabelecimento->getRelation('aceite') : null;
    $faltando = collect($documentosExigidos)->reject(fn ($doc) => $documentosEnviados->has($doc['tipo']));
    $enviados = count($documentosExigidos) - $faltando->count();
    $percentual = count($documentosExigidos) > 0 ? (int) round(($enviados / count($documentosExigidos)) * 100) : 0;
    $taxasOk = count($resumo['taxas']) > 0;
    $podeAssinar = $faltando->isEmpty() && $taxasOk && ! $aceite;
    $exigeAssinatura = \App\Support\MarketplaceCadastro::exigeAssinatura($estabelecimento);
    $primeiroFaltando = $faltando->first();
    $proximoTipo = is_array($primeiroFaltando) ? ($primeiroFaltando['tipo'] ?? null) : null;
    $fmtTaxa = fn ($valor) => number_format((float) $valor, 2, ',', '.').'%';
    $declaracaoModelo = \App\Support\AceiteContratacao::declaracao(
        $resumo['estabelecimento'],
        $resumo['documento'],
        $resumo['plano_nome'],
        '__NOME__',
        '__CPF__',
        '__CONDICAO__',
    );
@endphp

    <header class="bg-white px-4 pb-4 pt-[max(1rem,env(safe-area-inset-top))] shadow-sm">
        <div class="mx-auto flex max-w-lg items-center gap-3">
            @if (! empty($logoUrl))
                <img src="{{ $logoUrl }}" alt="{{ $appName }}" class="h-10 w-auto max-w-[9rem] object-contain">
            @else
                <span class="text-sm font-bold text-blue-700">{{ $appName }}</span>
            @endif
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Documentos e aceite</p>
                <h1 class="truncate text-base font-bold text-slate-900">{{ $resumo['estabelecimento'] }}</h1>
            </div>
        </div>
        <p class="mx-auto mt-3 max-w-lg text-sm text-slate-500">
            {{ $resumo['pessoa_label'] }} · {{ $resumo['documento'] }}
        </p>
    </header>

    <nav class="no-print sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="mx-auto grid max-w-lg {{ ($aceite || ! $exigeAssinatura) ? 'grid-cols-2' : 'grid-cols-3' }} text-center text-sm font-semibold">
            <a href="#documentos" class="px-2 py-3 text-slate-700">Documentos</a>
            @if ($aceite)
                <a href="#comprovante" class="px-2 py-3 text-emerald-700">Comprovante</a>
            @else
                <a href="#condicoes" class="px-2 py-3 text-slate-700">Taxas</a>
                @if ($exigeAssinatura)
                    <a href="#assinatura" class="px-2 py-3 text-slate-700">Assinatura</a>
                @endif
            @endif
        </div>
    </nav>

    <main class="mx-auto max-w-lg space-y-4 px-4 py-4">
        @if (session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('aviso'))
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ session('aviso') }}</div>
        @endif

        @if ($errors->any())
            <div id="erros-pagina" class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <p class="font-semibold">Revise os campos abaixo.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($aceite)
            @php
                $resumoAceito = $aceite->resumo ?? [];
                $evidencias = $aceite->evidencias ?? [];
            @endphp
            <section id="comprovante" class="scroll-mt-16 overflow-hidden rounded-2xl border border-emerald-200 bg-white shadow-sm">
                <div class="bg-emerald-600 px-4 py-4 text-white">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-100">Aceite confirmado</p>
                    <h2 class="mt-1 text-lg font-bold">Código de confirmação</h2>
                    <p id="hash-confirmacao" class="mt-3 break-all font-mono text-sm leading-6">{{ $aceite->hash_confirmacao }}</p>
                    <button type="button" data-copiar="#hash-confirmacao" class="no-print mt-3 rounded-full bg-white px-4 py-2 text-sm font-bold text-emerald-700">Copiar código</button>
                </div>
                <div class="space-y-4 px-4 py-4 text-sm">
                    <p class="leading-6 text-slate-600">{{ $aceite->declaracao }}</p>
                    <dl class="grid grid-cols-1 gap-3">
                        <div class="rounded-xl bg-slate-50 px-3 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Responsável</dt>
                            <dd class="mt-1 font-semibold">{{ $aceite->nome_responsavel }}</dd>
                            <dd class="text-slate-500">{{ $aceite->condicaoLabel() }} · CPF {{ \App\Support\DocumentoBrasil::formatarCpf($aceite->cpf_responsavel) }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-3 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Data e hora</dt>
                            <dd class="mt-1">Aparelho: {{ \Carbon\Carbon::parse($aceite->data_hora_cliente)->format('d/m/Y H:i:s') }}</dd>
                            <dd>Servidor: {{ $aceite->aceite_em?->timezone(config('app.timezone'))->format('d/m/Y H:i:s') }}</dd>
                            <dd class="text-slate-500">Fuso do aparelho: {{ $aceite->fuso_horario }}</dd>
                        </div>
                        <div class="rounded-xl bg-slate-50 px-3 py-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-400">Localização e rede</dt>
                            <dd class="mt-1">{{ $aceite->latitude }}, {{ $aceite->longitude }}</dd>
                            @if ($aceite->precisao_metros)
                                <dd class="text-slate-500">Precisão de {{ $aceite->precisao_metros }} m</dd>
                            @endif
                            <dd class="mt-1">IP {{ $aceite->ip ?: '—' }}</dd>
                            <a class="mt-2 inline-block font-semibold text-blue-700" target="_blank" rel="noopener" href="https://www.google.com/maps?q={{ $aceite->latitude }},{{ $aceite->longitude }}">Ver no mapa</a>
                        </div>
                    </dl>
                    <img src="{{ route('documentos.public.assinatura', $estabelecimento->documento_token_publico) }}" alt="Assinatura registrada" class="max-h-40 w-full rounded-xl border border-slate-200 bg-white object-contain">
                    <details class="rounded-xl border border-slate-200 px-3 py-3">
                        <summary class="cursor-pointer font-semibold">Taxas aceitas e dados do aparelho</summary>
                        <div class="mt-3 space-y-2">
                            @foreach ($resumoAceito['taxas'] ?? [] as $linha)
                                <p>
                                    <span class="font-semibold">{{ $linha['modalidade'] }}:</span>
                                    @if ($linha['taxa'])
                                        {{ $fmtTaxa($linha['taxa']) }}
                                        <span class="text-slate-500">({{ implode(', ', $linha['bandeiras']) }})</span>
                                    @else
                                        @foreach ($linha['detalhes'] as $detalhe)
                                            {{ $fmtTaxa($detalhe['taxa']) }} {{ implode(', ', $detalhe['bandeiras']) }}@if (! $loop->last); @endif
                                        @endforeach
                                    @endif
                                </p>
                            @endforeach
                            <p class="break-all text-slate-500">{{ $aceite->user_agent }}</p>
                            <p class="text-slate-500">
                                Tela {{ $evidencias['tela'] ?? '—' }}
                                · idioma {{ $evidencias['idioma'] ?? '—' }}
                                · toque {{ ! empty($evidencias['toque']) ? 'sim' : 'não' }}
                                @if (! empty($evidencias['conexao']))
                                    · rede {{ $evidencias['conexao'] }}
                                @endif
                            </p>
                        </div>
                    </details>
                    <button type="button" onclick="window.print()" class="no-print w-full rounded-xl border border-slate-300 py-3 font-semibold">Salvar comprovante</button>
                </div>
            </section>
        @endif

        <section id="documentos" class="scroll-mt-16 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">Documentos</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        @if ($estabelecimento->pessoa_tipo === 'juridica')
                            Pessoa jurídica envia também o contrato social ou o CCMEI, se for MEI.
                        @else
                            Pessoa física não envia contrato social.
                        @endif
                    </p>
                </div>
                <p class="text-sm font-bold text-blue-700">{{ $enviados }}/{{ count($documentosExigidos) }}</p>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-blue-600" style="width: {{ $percentual }}%"></div>
            </div>

            <div class="mt-4 space-y-3">
                @foreach ($documentosExigidos as $doc)
                    @php
                        $enviado = $documentosEnviados->get($doc['tipo']);
                        $extensao = strtolower(pathinfo((string) ($enviado->arquivo_nome ?? $enviado->arquivo_path ?? ''), PATHINFO_EXTENSION));
                        $preview = $enviado && in_array($extensao, ['jpg', 'jpeg', 'png', 'webp'], true);
                        $destaque = ! $enviado && $proximoTipo === $doc['tipo'];
                    @endphp
                    <article class="rounded-2xl border p-3 {{ $enviado ? 'border-emerald-200 bg-emerald-50/50' : ($destaque ? 'border-blue-300 bg-blue-50/40' : 'border-slate-200') }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="font-bold">{{ $doc['titulo'] }}</h3>
                                <p class="mt-1 text-sm leading-5 text-slate-500">{{ $doc['ajuda'] }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold {{ $enviado ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-600' }}">
                                {{ $enviado ? 'Enviado' : 'Pendente' }}
                            </span>
                        </div>

                        @if ($enviado)
                            @if ($preview)
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($enviado->arquivo_path) }}" alt="" class="mt-3 max-h-40 w-full rounded-xl object-cover">
                            @else
                                <p class="mt-3 truncate text-sm text-slate-600">{{ $enviado->arquivo_nome }}</p>
                            @endif
                        @endif

                        <form method="POST" action="{{ route('documentos.public.store', $estabelecimento->documento_token_publico) }}" enctype="multipart/form-data" data-doc-form data-camera="{{ $doc['camera'] }}" class="no-print mt-3">
                            @csrf
                            <input type="hidden" name="tipo_documento" value="{{ $doc['tipo'] }}">
                            <input data-arquivo-input type="file" name="documento" class="sr-only" accept="image/*,.pdf,.heic,.heif">
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" data-escolher="camera" class="min-h-12 rounded-xl bg-blue-600 px-3 font-bold text-white">{{ $enviado ? 'Nova foto' : 'Tirar foto' }}</button>
                                <button type="button" data-escolher="arquivo" class="min-h-12 rounded-xl border border-slate-300 bg-white px-3 font-bold text-slate-700">Arquivo</button>
                            </div>
                            <p data-envio-status class="mt-2 text-center text-xs text-slate-400">PDF, JPG, PNG ou HEIC até 25 MB.</p>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>

        @unless ($aceite)
            <section id="condicoes" class="scroll-mt-16 space-y-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-lg font-bold">Dados da contratação</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Estabelecimento</dt><dd class="text-right font-semibold">{{ $resumo['estabelecimento'] }}</dd></div>
                        @if ($resumo['razao_social'] && $resumo['razao_social'] !== $resumo['estabelecimento'])
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Razão social</dt><dd class="text-right font-semibold">{{ $resumo['razao_social'] }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">CPF/CNPJ</dt><dd class="text-right font-semibold">{{ $resumo['documento'] }}</dd></div>
                        @if ($resumo['responsavel'])
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Responsável</dt><dd class="text-right font-semibold">{{ $resumo['responsavel'] }}</dd></div>
                        @endif
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Plano</dt><dd class="text-right font-semibold">{{ $resumo['plano_nome'] ?: 'Não vinculado' }}</dd></div>
                        @if ($resumo['segmento'])
                            <div class="flex justify-between gap-3"><dt class="text-slate-500">Atividade</dt><dd class="text-right font-semibold">{{ $resumo['segmento'] }}</dd></div>
                        @endif
                        @if ($resumo['endereco'])
                            <div><dt class="text-slate-500">Endereço</dt><dd class="mt-1 font-semibold">{{ $resumo['endereco'] }}</dd></div>
                        @endif
                    </dl>
                    <div class="mt-4 rounded-xl bg-slate-50 px-3 py-3 text-sm">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Empresa parceira</p>
                        <p class="mt-1 font-semibold">{{ $resumo['empresa']['razao_social'] ?: $resumo['empresa']['nome'] }}</p>
                        @if ($resumo['empresa']['documento'])
                            <p class="text-slate-500">{{ $resumo['empresa']['documento'] }}</p>
                        @endif
                        @if ($resumo['empresa']['telefone'] || $resumo['empresa']['email'])
                            <p class="mt-1 text-slate-600">{{ collect([$resumo['empresa']['telefone'], $resumo['empresa']['email']])->filter()->join(' · ') }}</p>
                        @endif
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <h2 class="text-lg font-bold">Taxas e prazos</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ $resumo['texto_taxas'] }}</p>
                    @if ($taxasOk)
                        <div class="mt-4 space-y-2">
                            @foreach ($resumo['taxas'] as $linha)
                                <article class="rounded-xl border border-slate-200 px-3 py-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <h3 class="font-semibold">{{ $linha['modalidade'] }}</h3>
                                        @if ($linha['taxa'])
                                            <p class="text-lg font-bold text-blue-700">{{ $fmtTaxa($linha['taxa']) }}</p>
                                        @endif
                                    </div>
                                    @if ($linha['taxa'])
                                        <p class="mt-1 text-sm text-slate-500">{{ implode(', ', $linha['bandeiras']) }}</p>
                                    @else
                                        <ul class="mt-2 space-y-1 text-sm">
                                            @foreach ($linha['detalhes'] as $detalhe)
                                                <li class="flex justify-between gap-3">
                                                    <span>{{ implode(', ', $detalhe['bandeiras']) }}</span>
                                                    <span class="font-bold text-blue-700">{{ $fmtTaxa($detalhe['taxa']) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    <p class="mt-2 text-xs text-slate-400">{{ $linha['prazo'] }}</p>
                                </article>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-4 rounded-xl bg-amber-50 px-3 py-3 text-sm text-amber-800">
                            @if (! $resumo['plano_nome'])
                                Este estabelecimento ainda não tem um plano vinculado. A assinatura abre quando o plano e as taxas estiverem definidos.
                            @else
                                O plano {{ $resumo['plano_nome'] }} ainda não tem taxas cadastradas. A assinatura abre quando elas forem lançadas.
                            @endif
                        </p>
                    @endif
                </div>

                <div class="rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-sm">
                    <h2 class="text-lg font-bold text-amber-950">Contestações e chargeback</h2>
                    <p class="mt-2 text-sm leading-6 text-amber-950">{{ $resumo['texto_chargeback'] }}</p>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4 text-sm leading-6 text-slate-600 shadow-sm">
                    <h2 class="text-lg font-bold text-slate-900">Uso da maquininha</h2>
                    <p class="mt-2">{{ $resumo['texto_cadastro'] }}</p>
                    <p class="mt-3">{{ $resumo['texto_conferencia'] }}</p>
                </div>
            </section>

            @unless ($exigeAssinatura)
                <p class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm leading-6 text-slate-600 shadow-sm">Este marketplace não pede assinatura. O cadastro segue para a PagBank sem aceite nesta página.</p>
            @endunless
            @if ($exigeAssinatura)
            <section id="assinatura" class="scroll-mt-16 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <h2 class="text-lg font-bold">Assinatura do aceite</h2>
                @if (! $podeAssinar)
                    <p class="mt-3 rounded-xl bg-amber-50 px-3 py-3 text-sm leading-6 text-amber-900">
                        @if (! $taxasOk)
                            A assinatura fica disponível quando o plano tiver taxas cadastradas.
                        @else
                            Envie os documentos pendentes para liberar a assinatura:
                            {{ $faltando->pluck('titulo')->join(', ') }}.
                        @endif
                    </p>
                    <a href="#documentos" class="no-print mt-3 inline-flex min-h-12 items-center font-bold text-blue-700">Voltar aos documentos</a>
                @else
                    <p class="mt-2 text-sm leading-6 text-slate-500">Ao confirmar, registramos a assinatura, o IP, a data e a hora do aparelho e do servidor, o fuso, a localização e os dados deste celular ou computador. O código de confirmação amarra esses dados às taxas e aos arquivos enviados.</p>

                    <form id="form-aceite" method="POST" action="{{ route('documentos.public.aceite', $estabelecimento->documento_token_publico) }}" class="mt-4 space-y-4">
                        @csrf
                        <label class="block">
                            <span class="text-sm font-bold">Nome de quem assina</span>
                            <input name="nome_responsavel" value="{{ old('nome_responsavel', $resumo['responsavel']) }}" required autocomplete="name" class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3">
                        </label>
                        <label class="block">
                            <span class="text-sm font-bold">CPF de quem assina</span>
                            <input name="cpf_responsavel" inputmode="numeric" autocomplete="off" value="{{ old('cpf_responsavel', $resumo['cpf_sugerido']) }}" required placeholder="000.000.000-00" class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3">
                        </label>

                        @php $condicaoAtual = old('condicao_representacao', $estabelecimento->pessoa_tipo === 'juridica' ? 'representante' : 'titular'); @endphp
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-bold">Condição</legend>
                            @foreach (\App\Support\AceiteContratacao::CONDICOES as $valor => $rotulo)
                                <label class="flex min-h-12 items-center gap-3 rounded-xl border px-3 py-3 {{ $condicaoAtual === $valor ? 'border-blue-400 bg-blue-50' : 'border-slate-200' }}">
                                    <input type="radio" name="condicao_representacao" value="{{ $valor }}" class="h-5 w-5" @checked($condicaoAtual === $valor) required>
                                    <span class="font-semibold">{{ $rotulo }}</span>
                                </label>
                            @endforeach
                        </fieldset>

                        <div class="rounded-xl bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-700">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Texto que será registrado</p>
                            <p id="texto-declaracao" class="mt-2" data-modelo="{{ $declaracaoModelo }}">{{ $declaracaoModelo }}</p>
                        </div>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-300 px-3 py-3">
                            <input type="checkbox" name="concordo" value="1" class="mt-1 h-5 w-5" @checked(old('concordo')) required>
                            <span class="text-sm leading-5">Li e concordo com as taxas, os prazos e as condições desta contratação, incluindo as regras de chargeback. Confirmo que os dados e os documentos são verdadeiros.</span>
                        </label>

                        <div class="rounded-xl border border-slate-200 px-3 py-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-bold">Localização</p>
                                <button type="button" id="pedir-localizacao" class="rounded-full bg-slate-900 px-3 py-2 text-sm font-bold text-white">Permitir</button>
                            </div>
                            <p id="geo-status" class="mt-2 text-sm text-slate-500">Toque em permitir para registrar onde este aceite foi assinado.</p>
                        </div>

                        <div class="rounded-xl bg-slate-50 px-3 py-3 text-sm">
                            <p class="font-bold">Data e hora deste aparelho</p>
                            <p id="relogio-local" class="mt-1 text-base font-semibold"></p>
                            <p class="text-slate-500">Fuso: <span id="fuso-visivel"></span></p>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-bold">Assinatura</p>
                                <button type="button" id="limpar-assinatura" class="text-sm font-semibold text-blue-700">Limpar</button>
                            </div>
                            <canvas id="quadro-assinatura" class="mt-2 h-44 w-full rounded-xl border border-slate-300 bg-white" aria-label="Quadro para assinar com o dedo"></canvas>
                            <p class="mt-1 text-xs text-slate-400">Assine com o dedo dentro do quadro.</p>
                        </div>

                        <input type="hidden" name="assinatura" id="assinatura">
                        <input type="hidden" name="latitude" id="latitude">
                        <input type="hidden" name="longitude" id="longitude">
                        <input type="hidden" name="precisao_metros" id="precisao_metros">
                        <input type="hidden" name="altitude" id="altitude">
                        <input type="hidden" name="data_hora_cliente" id="data_hora_cliente">
                        <input type="hidden" name="fuso_horario" id="fuso_horario">
                        <input type="hidden" name="idioma" id="idioma">
                        <input type="hidden" name="tela" id="tela">
                        <input type="hidden" name="pixel_ratio" id="pixel_ratio">
                        <input type="hidden" name="conexao" id="conexao">
                        <input type="hidden" name="toque" id="toque">
                        <input type="hidden" name="plataforma" id="plataforma">

                        @if ($errors->any())
                            <div id="erros-aceite" class="rounded-xl border border-red-200 bg-red-50 px-3 py-3 text-sm text-red-700">
                                <p class="font-semibold">O aceite não foi registrado.</p>
                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <p id="erro-aceite" class="hidden rounded-xl bg-red-50 px-3 py-3 text-sm text-red-700"></p>
                        <button type="submit" id="confirmar-aceite" class="min-h-12 w-full rounded-xl bg-blue-600 font-bold text-white">Assinar e confirmar aceite</button>
                    </form>
                @endif
            </section>
            @endif
        @endunless
    </main>

    <script>
        document.querySelectorAll('[data-doc-form]').forEach((form) => {
            const input = form.querySelector('[data-arquivo-input]');
            form.querySelector('[data-escolher="camera"]')?.addEventListener('click', () => {
                input.accept = 'image/*';
                input.setAttribute('capture', form.dataset.camera || 'environment');
                input.click();
            });
            form.querySelector('[data-escolher="arquivo"]')?.addEventListener('click', () => {
                input.accept = '.pdf,.jpg,.jpeg,.png,.webp,.heic,.heif';
                input.removeAttribute('capture');
                input.click();
            });
            input?.addEventListener('change', () => {
                if (input.files?.length) {
                    form.querySelectorAll('button').forEach((botao) => { botao.disabled = true; });
                    const status = form.querySelector('[data-envio-status]');
                    if (status) status.textContent = 'Enviando...';
                    form.submit();
                }
            });
        });

        document.querySelectorAll('[data-copiar]').forEach((botao) => {
            botao.addEventListener('click', async () => {
                const texto = document.querySelector(botao.dataset.copiar)?.textContent?.trim();
                if (!texto) return;
                try {
                    await navigator.clipboard.writeText(texto);
                } catch (e) {
                    const area = document.createElement('textarea');
                    area.value = texto;
                    document.body.appendChild(area);
                    area.select();
                    document.execCommand('copy');
                    area.remove();
                }
                const original = botao.textContent;
                botao.textContent = 'Copiado';
                setTimeout(() => { botao.textContent = original; }, 1600);
            });
        });

        const formAceite = document.getElementById('form-aceite');
        if (formAceite) {
            const condicoes = @json(\App\Support\AceiteContratacao::CONDICOES);
            const texto = document.getElementById('texto-declaracao');
            const modelo = texto.dataset.modelo;
            const nome = formAceite.querySelector('[name="nome_responsavel"]');
            const cpf = formAceite.querySelector('[name="cpf_responsavel"]');
            const erro = document.getElementById('erro-aceite');
            const canvas = document.getElementById('quadro-assinatura');
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            let desenhando = false;
            let desenhou = false;

            const atualizarDeclaracao = () => {
                const marcada = formAceite.querySelector('[name="condicao_representacao"]:checked');
                texto.textContent = modelo
                    .replace('__NOME__', nome.value.trim() || 'seu nome')
                    .replace('__CPF__', cpf.value.trim() || 'seu CPF')
                    .replace('__CONDICAO__', condicoes[marcada?.value] || 'sua condição');
            };

            const mascararCpf = () => {
                let digitos = cpf.value.replace(/\D/g, '').slice(0, 11);
                digitos = digitos
                    .replace(/(\d{3})(\d)/, '$1.$2')
                    .replace(/(\d{3})(\d)/, '$1.$2')
                    .replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                cpf.value = digitos;
                atualizarDeclaracao();
            };

            nome.addEventListener('input', atualizarDeclaracao);
            cpf.addEventListener('input', mascararCpf);
            formAceite.querySelectorAll('[name="condicao_representacao"]').forEach((radio) => {
                radio.addEventListener('change', atualizarDeclaracao);
            });
            mascararCpf();

            const chaveAssinatura = 'aceite-assinatura:' + formAceite.action;
            let ratio = 1;
            let pronto = false;

            const estiloTraco = () => {
                ctx.lineWidth = 2.6;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#0f172a';
            };

            const prepararQuadro = (limpar) => {
                const largura = canvas.clientWidth;
                const altura = canvas.clientHeight;
                if (largura < 2 || altura < 2) return false;
                const pai = canvas.parentElement ? canvas.parentElement.clientWidth : 0;
                if (!limpar && pai > 200 && Math.abs(largura - pai) > 16) return false;
                ratio = Math.min(Math.max(window.devicePixelRatio || 1, 1), 2);
                const bitmapLargura = Math.floor(largura * ratio);
                const bitmapAltura = Math.floor(altura * ratio);
                if (!limpar && pronto && canvas.width === bitmapLargura && canvas.height === bitmapAltura) return true;
                canvas.width = bitmapLargura;
                canvas.height = bitmapAltura;
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, largura, altura);
                estiloTraco();
                if (limpar) desenhou = false;
                pronto = true;
                return true;
            };

            const restaurarAssinatura = () => {
                let salva = '';
                try { salva = sessionStorage.getItem(chaveAssinatura) || ''; } catch (e) { return; }
                if (!salva.startsWith('data:image/png')) return;
                const imagem = new Image();
                imagem.onload = () => {
                    if (!pronto && !prepararQuadro(false)) return;
                    ctx.setTransform(1, 0, 0, 1, 0, 0);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(imagem, 0, 0, canvas.width, canvas.height);
                    ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                    estiloTraco();
                    desenhou = true;
                };
                imagem.src = salva;
            };

            let restaurou = false;
            const observarQuadro = () => {
                if (desenhou) return;
                if (!prepararQuadro(false) || restaurou) return;
                restaurou = true;
                restaurarAssinatura();
            };
            observarQuadro();
            if (window.ResizeObserver) new ResizeObserver(observarQuadro).observe(canvas);
            setTimeout(observarQuadro, 400);

            const ponto = (evento) => {
                const caixa = canvas.getBoundingClientRect();
                return { x: evento.clientX - caixa.left, y: evento.clientY - caixa.top };
            };
            const comecarTraco = (evento) => {
                if (!pronto) observarQuadro();
                if (evento.cancelable) evento.preventDefault();
                try { canvas.setPointerCapture(evento.pointerId); } catch (e) {}
                desenhando = true;
                const p = ponto(evento);
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                ctx.lineTo(p.x + 0.4, p.y + 0.4);
                ctx.stroke();
                desenhou = true;
            };
            const continuarTraco = (evento) => {
                if (!desenhando) return;
                if (evento.cancelable) evento.preventDefault();
                const p = ponto(evento);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                desenhou = true;
            };
            canvas.addEventListener('pointerdown', comecarTraco, { passive: false });
            canvas.addEventListener('pointermove', continuarTraco, { passive: false });
            const soltar = () => { desenhando = false; };
            canvas.addEventListener('pointerup', soltar);
            canvas.addEventListener('pointercancel', soltar);
            document.getElementById('limpar-assinatura').addEventListener('click', () => {
                try { sessionStorage.removeItem(chaveAssinatura); } catch (e) {}
                desenhou = false;
                prepararQuadro(true);
            });

            const relogio = document.getElementById('relogio-local');
            const fusoVisivel = document.getElementById('fuso-visivel');
            const fuso = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            fusoVisivel.textContent = fuso;
            const tick = () => { relogio.textContent = new Date().toLocaleString('pt-BR'); };
            tick();
            setInterval(tick, 1000);

            const geoStatus = document.getElementById('geo-status');
            const pedirLocal = () => {
                if (!navigator.geolocation) {
                    geoStatus.textContent = 'Este aparelho não informa localização. Use um celular ou um navegador que permita a localização.';
                    return;
                }
                geoStatus.textContent = 'Obtendo localização...';
                navigator.geolocation.getCurrentPosition((posicao) => {
                    document.getElementById('latitude').value = posicao.coords.latitude.toFixed(7);
                    document.getElementById('longitude').value = posicao.coords.longitude.toFixed(7);
                    const precisao = Number(posicao.coords.accuracy);
                    if (Number.isFinite(precisao) && precisao >= 0 && precisao <= 100000) {
                        document.getElementById('precisao_metros').value = precisao.toFixed(2);
                    }
                    const altitude = Number(posicao.coords.altitude);
                    if (Number.isFinite(altitude) && altitude >= -500 && altitude <= 10000) {
                        document.getElementById('altitude').value = altitude.toFixed(2);
                    }
                    const metros = Number.isFinite(precisao) ? Math.round(precisao) : null;
                    const coords = `${posicao.coords.latitude.toFixed(5)}, ${posicao.coords.longitude.toFixed(5)}`;
                    geoStatus.textContent = metros === null
                        ? `Localização obtida (${coords}).`
                        : `Localização obtida (${coords}), precisão de ${metros} m.`;
                }, (falha) => {
                    const mensagens = {
                        1: 'A localização foi negada. Ative a permissão do navegador e toque em permitir de novo.',
                        2: 'Não foi possível obter a localização. Tente de novo em um lugar com sinal.',
                        3: 'A localização demorou demais. Toque em permitir de novo.',
                    };
                    geoStatus.textContent = mensagens[falha.code] || 'Não foi possível obter a localização.';
                }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
            };
            document.getElementById('pedir-localizacao').addEventListener('click', pedirLocal);
            pedirLocal();

            const mostrarErro = (mensagem) => {
                erro.textContent = mensagem;
                erro.classList.remove('hidden');
            };

            const temTinta = () => {
                try {
                    const pixels = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
                    for (let i = 0; i < pixels.length; i += 8) {
                        if (pixels[i] < 210 || pixels[i + 1] < 210 || pixels[i + 2] < 210) return true;
                    }
                } catch (e) {
                    return desenhou;
                }
                return false;
            };

            formAceite.addEventListener('submit', (evento) => {
                erro.classList.add('hidden');
                if (!desenhou || !temTinta()) {
                    evento.preventDefault();
                    mostrarErro('Assine no quadro antes de confirmar.');
                    return;
                }
                if (!document.getElementById('latitude').value || !document.getElementById('longitude').value) {
                    evento.preventDefault();
                    mostrarErro('Autorize a localização do aparelho para registrar o aceite.');
                    pedirLocal();
                    return;
                }

                const agora = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                const deslocamento = -agora.getTimezoneOffset();
                const sinal = deslocamento >= 0 ? '+' : '-';
                const horas = pad(Math.floor(Math.abs(deslocamento) / 60));
                const minutos = pad(Math.abs(deslocamento) % 60);
                document.getElementById('data_hora_cliente').value = `${agora.getFullYear()}-${pad(agora.getMonth() + 1)}-${pad(agora.getDate())}T${pad(agora.getHours())}:${pad(agora.getMinutes())}:${pad(agora.getSeconds())}${sinal}${horas}:${minutos}`;
                document.getElementById('fuso_horario').value = fuso;
                document.getElementById('idioma').value = navigator.language || '';
                document.getElementById('tela').value = `${Math.round(screen.width)}x${Math.round(screen.height)}`;
                const densidade = Number(window.devicePixelRatio);
                document.getElementById('pixel_ratio').value = Number.isFinite(densidade) ? String(Math.min(10, Math.max(0, densidade))) : '';
                document.getElementById('conexao').value = navigator.connection?.effectiveType || '';
                document.getElementById('toque').value = navigator.maxTouchPoints > 0 ? '1' : '0';
                document.getElementById('plataforma').value = navigator.platform || '';

                let imagem;
                try {
                    imagem = canvas.toDataURL('image/png');
                } catch (e) {
                    evento.preventDefault();
                    mostrarErro('Não foi possível ler a assinatura. Limpe o quadro e assine de novo.');
                    return;
                }
                if (!imagem.startsWith('data:image/png') || imagem.length < 250) {
                    evento.preventDefault();
                    mostrarErro('A assinatura não ficou registrada. Assine de novo dentro do quadro.');
                    return;
                }
                document.getElementById('assinatura').value = imagem;
                try { sessionStorage.setItem(chaveAssinatura, imagem); } catch (e) {}

                const botao = document.getElementById('confirmar-aceite');
                botao.disabled = true;
                botao.textContent = 'Registrando aceite...';
            });

            window.addEventListener('pageshow', () => {
                const botao = document.getElementById('confirmar-aceite');
                if (!botao) return;
                botao.disabled = false;
                botao.textContent = 'Assinar e confirmar aceite';
                restaurarAssinatura();
            });
        } else {
            try {
                Object.keys(sessionStorage).forEach((chave) => {
                    if (chave.startsWith('aceite-assinatura:')) sessionStorage.removeItem(chave);
                });
            } catch (e) {}
        }

        @if ($errors->has('documento') || $errors->has('tipo_documento'))
            document.getElementById('documentos')?.scrollIntoView({ block: 'start' });
        @elseif ($errors->any())
            (document.getElementById('erros-aceite') || document.getElementById('erros-pagina'))?.scrollIntoView({ block: 'center' });
        @endif
    </script>
</body>
</html>
