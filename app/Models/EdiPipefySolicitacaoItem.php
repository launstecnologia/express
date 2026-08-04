<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdiPipefySolicitacaoItem extends Model
{
    protected $table = 'edi_pipefy_solicitacao_itens';

    protected $fillable = [
        'solicitacao_id',
        'estabelecimento_id',
        'token_pagseguro',
    ];

    public function solicitacao(): BelongsTo
    {
        return $this->belongsTo(EdiPipefySolicitacao::class, 'solicitacao_id');
    }

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }
}
