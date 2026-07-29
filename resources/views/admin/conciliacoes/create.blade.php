@extends('layouts.app')

@section('title', 'Importar conciliação')

@section('content')
<div class="max-w-2xl">
    <a href="{{ route('admin.conciliacoes.index') }}" class="mb-4 inline-flex items-center gap-2 text-sm font-semibold text-gray-500 hover:text-gray-800">
        <i class="fa-solid fa-arrow-left"></i> Voltar
    </a>

    <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-bold text-gray-800">Importar relatório PagSeguro</h2>
        <p class="mt-2 text-sm text-gray-500">
            Envie o arquivo mensal da PagSeguro <strong>sem alterar</strong> — use o XLSX original com a aba <strong>Validação V2</strong>.
            O sistema lê automaticamente as colunas (id_cliente, TPV, comissão, bandeira, etc.) e vincula ao estabelecimento pelo <code class="text-xs">token_pagseguro</code>.
        </p>

        <form id="form-conciliacao" method="POST" action="{{ route('admin.conciliacoes.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
            @csrf

            <label class="block space-y-2">
                <span class="text-sm font-semibold text-gray-700">Arquivo XLSX</span>
                <input type="file" name="arquivo" accept=".xlsx" required class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm">
                @error('arquivo')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </label>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="confrontar" value="1" checked class="rounded">
                Confrontar automaticamente com EDI após importar
            </label>

            <p class="text-xs text-gray-500">
                Arquivos grandes (milhares de linhas) podem levar alguns minutos. Não feche a aba enquanto processa.
            </p>

            <button id="btn-importar" type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-wait disabled:opacity-70">
                Importar
            </button>

            <div id="importando" class="hidden rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                <i class="fa-solid fa-spinner fa-spin"></i>
                Importando e confrontando… isso pode demorar. Aguarde.
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('form-conciliacao')?.addEventListener('submit', function () {
    const btn = document.getElementById('btn-importar');
    const aviso = document.getElementById('importando');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Processando…';
    }
    aviso?.classList.remove('hidden');
});
</script>
@endsection
