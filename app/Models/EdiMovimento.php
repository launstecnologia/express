<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EdiMovimento extends Model
{
    protected $table = 'edi_movimentos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'data_inicial_transacao' => 'date',
            'data_venda_ajuste' => 'date',
            'data_prevista_pagamento' => 'date',
            'valor_total_transacao' => 'decimal:2',
            'valor_parcela' => 'decimal:2',
            'valor_original_transacao' => 'decimal:2',
            'valor_liquido_transacao' => 'decimal:2',
            'taxa_intermediacao' => 'decimal:2',
            'tarifa_intermediacao' => 'decimal:2',
            'comissao_percentual' => 'decimal:2',
            'comissao_valor' => 'decimal:4',
            'processado' => 'boolean',
            'data_importacao' => 'datetime',
        ];
    }

    public function estabelecimento()
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function royalties()
    {
        return $this->hasMany(TransacaoRoyalty::class);
    }
}
