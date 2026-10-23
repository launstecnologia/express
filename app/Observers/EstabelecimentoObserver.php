<?php

namespace App\Observers;

use App\Jobs\CriarEmailEstabelecimentoJob;
use App\Models\Estabelecimento;
use App\Services\KycInicializacaoService;
use App\Support\EstabelecimentoEtapaListagem;
use Illuminate\Support\Str;

class EstabelecimentoObserver
{
    public function created(Estabelecimento $estabelecimento): void
    {
        if (blank($estabelecimento->documento_token_publico)) {
            $estabelecimento->forceFill([
                'documento_token_publico' => (string) Str::uuid(),
            ])->saveQuietly();
        }

        app(KycInicializacaoService::class)->iniciar($estabelecimento);
    }

    public function updated(Estabelecimento $estabelecimento): void
    {
        if (! $estabelecimento->wasChanged('status')) {
            return;
        }

        if ($estabelecimento->status !== EstabelecimentoEtapaListagem::APROVADO) {
            return;
        }

        if (! config('directadmin.criar_email_ao_habilitar', true)) {
            return;
        }

        if ($estabelecimento->emails()->where('criado_automaticamente', true)->exists()) {
            return;
        }

        CriarEmailEstabelecimentoJob::dispatch($estabelecimento);
    }
}
