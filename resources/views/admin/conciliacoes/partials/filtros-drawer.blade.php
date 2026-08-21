<div x-show="filtrosAberto" x-cloak class="fixed inset-0 z-50" @keydown.escape.window="filtrosAberto = false">
    <div class="absolute inset-0 bg-gray-900/50" @click="filtrosAberto = false"></div>
    <aside
        class="absolute right-0 top-0 flex h-full w-full max-w-md flex-col bg-white shadow-2xl"
        x-show="filtrosAberto"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        @click.stop
    >
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
            <div>
                <h3 class="text-lg font-semibold text-gray-800">Filtros</h3>
                <p class="text-xs text-gray-500">Atualiza os cards e a tabela desta conciliação</p>
            </div>
            <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100" @click="filtrosAberto = false">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="GET" action="{{ route('admin.conciliacoes.show', $conciliacao) }}" class="flex min-h-0 flex-1 flex-col">
            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                <div>
                    <label for="filtro-nome" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Nome do estabelecimento</label>
                    <input
                        id="filtro-nome"
                        name="nome"
                        type="search"
                        value="{{ $filtros['nome'] ?? '' }}"
                        placeholder="Nome fantasia, razão social..."
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                </div>

                <div>
                    <label for="filtro-estabelecimento-id" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">ID do estabelecimento</label>
                    <input
                        id="filtro-estabelecimento-id"
                        name="estabelecimento_id"
                        type="search"
                        value="{{ $filtros['estabelecimento_id'] ?? $filtros['id_cliente'] ?? '' }}"
                        placeholder="Token PagSeguro ou código interno"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 font-mono text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                    <p class="mt-1 text-xs text-gray-400">ID cliente da planilha, token PagSeguro ou código do cadastro.</p>
                </div>

                <div>
                    <label for="filtro-marketplace" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Marketplace</label>
                    <select id="filtro-marketplace" name="marketplace_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos</option>
                        @foreach ($marketplaces as $marketplace)
                            <option value="{{ $marketplace['id'] }}" @selected((string) ($filtros['marketplace_id'] ?? '') === (string) $marketplace['id'])>{{ $marketplace['nome'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="filtro-revenda" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Revenda</label>
                    <select id="filtro-revenda" name="revenda_id" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Todas</option>
                        @foreach ($revendas as $revenda)
                            <option value="{{ $revenda['id'] }}" @selected((string) ($filtros['revenda_id'] ?? '') === (string) $revenda['id'])>{{ $revenda['nome'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="filtro-status" class="mb-1 block text-xs font-semibold uppercase tracking-wide text-gray-500">Status</label>
                    <select id="filtro-status" name="status" class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2.5 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos</option>
                        @foreach (['ok' => 'OK', 'divergente' => 'Divergente', 'sem_estabelecimento' => 'Sem estabelecimento', 'sem_edi' => 'Só na planilha', 'so_edi' => 'Só no EDI', 'pendente' => 'Pendente'] as $valor => $rotulo)
                            <option value="{{ $valor }}" @selected(($filtros['status'] ?? '') === $valor)>{{ $rotulo }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex gap-2 border-t border-gray-100 px-5 py-4">
                <a
                    href="{{ route('admin.conciliacoes.show', $conciliacao) }}"
                    class="flex-1 rounded-lg border border-gray-200 px-4 py-2.5 text-center text-sm font-semibold text-gray-600 transition hover:bg-gray-50"
                >
                    Limpar
                </a>
                <button type="submit" class="flex-1 rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700">
                    Aplicar filtros
                </button>
            </div>
        </form>
    </aside>
</div>
