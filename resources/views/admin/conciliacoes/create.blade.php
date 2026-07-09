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

        <form method="POST" action="{{ route('admin.conciliacoes.store') }}" enctype="multipart/form-data" class="mt-6 space-y-4">
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

            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                Importar
            </button>
        </form>
    </div>
</div>
@endsection
