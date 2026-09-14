<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EdiDumpLinha extends Model
{
    protected $table = 'edi_dump_linhas';

    protected $fillable = [
        'dump_id',
        'dia_id',
        'data_referencia',
        'pagina',
        'estabelecimento',
        'estabelecimento_id',
        'movimento_api_codigo',
        'data_inicial_transacao',
        'tipo_transacao',
        'status_pagamento',
        'valor_total_transacao',
        'valor_liquido_transacao',
        'nsu',
    ];

    protected function casts(): array
    {
        return [
            'data_referencia' => 'date',
            'data_inicial_transacao' => 'date',
            'valor_total_transacao' => 'decimal:2',
            'valor_liquido_transacao' => 'decimal:2',
        ];
    }

    public function dump(): BelongsTo
    {
        return $this->belongsTo(EdiDump::class, 'dump_id');
    }

    public function dia(): BelongsTo
    {
        return $this->belongsTo(EdiDumpDia::class, 'dia_id');
    }
}
