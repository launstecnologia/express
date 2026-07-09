<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConciliacaoLinha extends Model
{
    protected $fillable = [
        'conciliacao_id',
        'chave',
        'link',
        'id_cliente',
        'meio_pagamento',
        'parcelamento_agrupado',
        'bandeira',
        'escrow',
        'mcc',
        'solucao',
        'tpv',
        'ms_comissao',
        'ms_chargeback',
        'rebate_real',
        'rebate_contrato',
        'check1_sem_antec',
        'estabelecimento_id',
        'sem_estabelecimento',
        'chave_confronto',
        'edi_tpv',
        'edi_comissao',
        'edi_qtd',
        'diff_tpv',
        'diff_comissao',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tpv' => 'decimal:2',
            'ms_comissao' => 'decimal:4',
            'ms_chargeback' => 'decimal:4',
            'rebate_real' => 'decimal:6',
            'rebate_contrato' => 'decimal:6',
            'check1_sem_antec' => 'boolean',
            'sem_estabelecimento' => 'boolean',
            'edi_tpv' => 'decimal:2',
            'edi_comissao' => 'decimal:4',
            'diff_tpv' => 'decimal:2',
            'diff_comissao' => 'decimal:4',
        ];
    }

    public function conciliacao(): BelongsTo
    {
        return $this->belongsTo(Conciliacao::class);
    }

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'ok' => 'OK',
            'divergente' => 'Divergente',
            'sem_estabelecimento' => 'Sem estabelecimento',
            'sem_edi' => 'Sem EDI',
            default => 'Pendente',
        };
    }
}
