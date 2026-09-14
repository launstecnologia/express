<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdiDumpDia extends Model
{
    protected $table = 'edi_dump_dias';

    protected $fillable = [
        'dump_id',
        'data',
        'status',
        'paginas',
        'total_itens_api',
        'linhas',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
        ];
    }

    public function dump(): BelongsTo
    {
        return $this->belongsTo(EdiDump::class, 'dump_id');
    }

    public function linhas(): HasMany
    {
        return $this->hasMany(EdiDumpLinha::class, 'dia_id');
    }
}
