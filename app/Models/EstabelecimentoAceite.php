<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstabelecimentoAceite extends Model
{
    protected $table = 'estabelecimento_aceites';

    protected $fillable = [
        'estabelecimento_id',
        'plano_id',
        'plano_nome',
        'nome_responsavel',
        'cpf_responsavel',
        'condicao_representacao',
        'declaracao',
        'resumo',
        'evidencias',
        'assinatura_path',
        'assinatura_sha256',
        'ip',
        'user_agent',
        'fuso_horario',
        'data_hora_cliente',
        'latitude',
        'longitude',
        'precisao_metros',
        'hash_confirmacao',
        'payload_hash',
        'aceite_em',
    ];

    protected function casts(): array
    {
        return [
            'resumo' => 'array',
            'evidencias' => 'array',
            'payload_hash' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'precisao_metros' => 'decimal:2',
            'aceite_em' => 'datetime',
        ];
    }

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function condicaoLabel(): string
    {
        return \App\Support\AceiteContratacao::CONDICOES[$this->condicao_representacao]
            ?? $this->condicao_representacao;
    }
}
