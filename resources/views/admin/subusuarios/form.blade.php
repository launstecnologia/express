@extends('layouts.app')

@php
    $tipoLabel = [
        'master' => 'Master',
        'marketplace' => 'Marketplace',
        'revenda' => 'Revenda',
    ];
    $inputClass = 'w-full rounded border border-slate-300 bg-white px-3 py-2 text-xs text-slate-700 shadow-sm placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    $selectClass = 'w-full rounded border border-slate-300 bg-white px-3 pr-8 text-xs text-slate-700 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500';
    $labelClass = 'space-y-1';
    $labelTextClass = 'text-[11px] font-semibold text-slate-800';
    $sectionTitleClass = 'border-b border-slate-200 px-3 py-4 text-base font-semibold text-slate-600';
@endphp

@section('title', 'Novo Usuário do '.$tipoLabel[$dono->tipo])

@section('content')
<form method="POST" action="{{ route('usuarios.subusuarios.store', $dono) }}" class="overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm">
    @csrf

    <div class="flex min-h-24 items-start justify-between px-3 py-5">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Vinculado a</p>
            <h2 class="mt-2 text-xl font-bold uppercase text-slate-800">{{ $dono->nomeExibicao() }}</h2>
            <p class="mt-1 text-sm text-slate-400">{{ $tipoLabel[$dono->tipo] }} proprietário deste acesso operacional.</p>
        </div>
        <span class="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{{ strtoupper($dono->tipo) }}</span>
    </div>

    @if ($errors->any())
        <div class="mx-3 mb-4 rounded border border-red-200 bg-red-50 p-3 text-xs text-red-700">
            <p class="font-semibold">Revise os campos antes de salvar.</p>
            <ul class="mt-2 list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <h2 class="{{ $sectionTitleClass }}">Dados de acesso</h2>
    <div class="grid gap-x-5 gap-y-3 px-3 py-4 md:grid-cols-12">
        <label class="{{ $labelClass }} md:col-span-5">
            <span class="{{ $labelTextClass }}">Nome</span>
            <input name="nome" value="{{ old('nome') }}" placeholder="Nome completo" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-4">
            <span class="{{ $labelTextClass }}">E-mail</span>
            <input name="email" type="email" value="{{ old('email', $dono->email) }}" placeholder="{{ $dono->email }}" class="{{ $inputClass }}">
            <p class="text-[11px] text-slate-500">Pode ser o mesmo e-mail da conta comercial ({{ $dono->email }}).</p>
        </label>
        <label class="{{ $labelClass }} md:col-span-3">
            <span class="{{ $labelTextClass }}">Senha</span>
            <input name="password" type="password" class="{{ $inputClass }}">
        </label>
        <label class="{{ $labelClass }} md:col-span-6">
            <span class="{{ $labelTextClass }}">Perfil de permissão</span>
            <select name="perfil_id" class="{{ $selectClass }}">
                <option value="">Sem perfil específico</option>
                @foreach ($perfis as $perfil)
                    <option value="{{ $perfil->id }}" @selected((int) old('perfil_id') === $perfil->id)>{{ $perfil->nome }}</option>
                @endforeach
            </select>
        </label>
        <label class="flex items-end gap-2 pb-2 text-xs font-semibold text-slate-700 md:col-span-3">
            <input type="hidden" name="ativo" value="0">
            <input type="checkbox" name="ativo" value="1" class="h-4 w-4 rounded accent-blue-600" @checked(old('ativo', true))>
            Ativo
        </label>
    </div>

    <div class="flex justify-end gap-3 px-3 pb-3 pt-4">
        <a href="{{ route('usuarios.show', $dono) }}" class="rounded border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-600 shadow-sm hover:bg-slate-50">Cancelar</a>
        <button class="rounded bg-blue-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-blue-700">Registrar</button>
    </div>
</form>
@endsection
