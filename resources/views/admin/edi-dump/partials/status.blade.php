@php
    $statusClass = match ($status) {
        'concluido', 'ok' => 'bg-emerald-100 text-emerald-800',
        'processando', 'pendente' => 'bg-amber-100 text-amber-800',
        'nao_validado', 'vazio' => 'bg-slate-100 text-slate-700',
        default => 'bg-red-100 text-red-800',
    };
    $statusLabel = match ($status) {
        'pendente' => 'Pendente',
        'processando' => 'Rodando',
        'concluido' => 'Concluído',
        'ok' => 'Ok',
        'vazio' => 'Vazio',
        'nao_validado' => 'Não validado',
        'erro' => 'Erro',
        default => $status,
    };
@endphp
<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $statusClass }}">{{ $statusLabel }}</span>
