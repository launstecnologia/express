@props([
    'name',
    'oculta' => false,
    'temArquivo' => false,
])

<div class="mt-2 space-y-1.5 rounded-lg border border-gray-100 bg-gray-50 p-2.5 dark:border-gray-700 dark:bg-gray-800/50">
    <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
        @if ($oculta)
            Atualmente sem logo
        @elseif ($temArquivo)
            Logo personalizada
        @else
            Usando logo padrão da plataforma
        @endif
    </p>
    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        <input type="radio" name="{{ $name }}" value="" class="accent-blue-600" @checked(old($name) === null || old($name) === '')>
        Manter como está
    </label>
    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        <input type="radio" name="{{ $name }}" value="padrao" class="accent-blue-600" @checked(old($name) === 'padrao')>
        Voltar à logo padrão da plataforma
    </label>
    <label class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
        <input type="radio" name="{{ $name }}" value="ocultar" class="accent-blue-600" @checked(old($name) === 'ocultar')>
        Remover logo (não exibir nenhuma)
    </label>
</div>
