<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanoTaxa extends Model
{
    protected $table = 'plano_taxas';

    protected $fillable = ['plano_id', 'instituicao', 'tipo_transacao', 'meio_pagamento_cod', 'arranjo_ur', 'parcelas', 'taxa_percentual', 'comissao_percentual', 'ativo'];

    protected function casts(): array
    {
        return [
            'taxa_percentual'     => 'decimal:2',
            'comissao_percentual' => 'decimal:2',
            'ativo'               => 'boolean',
        ];
    }

    public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

    public function royalties()
    {
        return $this->hasMany(PlanoTaxaRoyalty::class);
    }

    public function comissaoAdminPercentual(): ?float
    {
        $royaltyAdmin = $this->relationLoaded('royalties')
            ? $this->royalties->firstWhere('nivel', 'admin')?->percentual
            : $this->royalties()->where('nivel', 'admin')->value('percentual');

        if ($royaltyAdmin !== null && $royaltyAdmin !== '') {
            return (float) $royaltyAdmin;
        }

        return $this->comissao_percentual !== null
            ? (float) $this->comissao_percentual
            : null;
    }
}
