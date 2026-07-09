<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conciliacao extends Model
{
    protected $fillable = [
        'referencia_mes',
        'data_referencia',
        'parceiro',
        'arquivo_nome',
        'arquivo_path',
        'total_linhas',
        'total_clientes',
        'total_tpv',
        'total_comissao',
        'total_chargeback',
        'status',
        'confrontado_em',
        'linhas_ok',
        'linhas_divergentes',
        'linhas_sem_estabelecimento',
        'linhas_sem_edi',
        'importado_por_id',
    ];

    protected function casts(): array
    {
        return [
            'referencia_mes' => 'date',
            'data_referencia' => 'date',
            'total_tpv' => 'decimal:2',
            'total_comissao' => 'decimal:4',
            'total_chargeback' => 'decimal:4',
            'confrontado_em' => 'datetime',
        ];
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(ConciliacaoLinha::class);
    }

    public function importadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'importado_por_id');
    }

    public function referenciaFormatada(): string
    {
        return $this->referencia_mes?->translatedFormat('F/Y') ?? '—';
    }
}
