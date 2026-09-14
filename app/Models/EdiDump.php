<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdiDump extends Model
{
    protected $table = 'edi_dumps';

    protected $fillable = [
        'competencia',
        'status',
        'total_dias',
        'dias_ok',
        'dias_nao_validados',
        'dias_erro',
        'total_paginas',
        'total_itens_api',
        'total_linhas',
        'iniciado_por_id',
        'iniciado_por_nome',
        'erro',
        'iniciado_em',
        'finalizado_em',
    ];

    protected function casts(): array
    {
        return [
            'competencia' => 'date',
            'iniciado_em' => 'datetime',
            'finalizado_em' => 'datetime',
        ];
    }

    public function dias(): HasMany
    {
        return $this->hasMany(EdiDumpDia::class, 'dump_id');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(EdiDumpLinha::class, 'dump_id');
    }

    public function emAndamento(): bool
    {
        return in_array($this->status, ['pendente', 'processando'], true);
    }
}
