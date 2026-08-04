<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdiPipefySolicitacao extends Model
{
    protected $table = 'edi_pipefy_solicitacoes';

    protected $fillable = [
        'status',
        'tipo',
        'email_devolutiva',
        'id_origem',
        'total_ids',
        'automacao_job_id',
        'pipefy_card_id',
        'descricao',
        'erro',
        'resultado',
        'screenshots',
        'solicitado_por_id',
        'disparado_em',
        'concluido_em',
    ];

    protected function casts(): array
    {
        return [
            'resultado' => 'array',
            'screenshots' => 'array',
            'disparado_em' => 'datetime',
            'concluido_em' => 'datetime',
        ];
    }

    public function itens(): HasMany
    {
        return $this->hasMany(EdiPipefySolicitacaoItem::class, 'solicitacao_id');
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'solicitado_por_id');
    }

    public function statusBadge(): array
    {
        return match ($this->status) {
            'concluido' => ['bg-emerald-100 text-emerald-800', 'Concluído'],
            'em_andamento' => ['bg-blue-100 text-blue-800', 'Em andamento'],
            'erro' => ['bg-red-100 text-red-800', 'Erro'],
            default => ['bg-gray-100 text-gray-700', 'Pendente'],
        };
    }
}
