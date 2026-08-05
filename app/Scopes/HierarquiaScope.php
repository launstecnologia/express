<?php

namespace App\Scopes;

use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Support\UsuarioComercial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class HierarquiaScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return;
        }

        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        if (! $usuario instanceof Usuario || $usuario->tipo === 'admin') {
            return;
        }

        match ($usuario->tipo) {
            'master' => $builder->where($model->getTable().'.master_id', $usuario->id),
            'marketplace' => $this->aplicarCarteiraMarketplace($builder, $model, $usuario),
            'revenda' => $builder->where($model->getTable().'.revenda_id', $usuario->id),
            default => null,
        };
    }

    private function aplicarCarteiraMarketplace(Builder $builder, Model $model, Usuario $marketplace): void
    {
        $table = $model->getTable();
        $revendaIds = UsuarioComercial::revendasDo($marketplace)->pluck('id')->all();

        $builder->where(function (Builder $q) use ($table, $marketplace, $revendaIds) {
            $q->where($table.'.marketplace_id', $marketplace->id);
            if ($revendaIds !== []) {
                $q->orWhereIn($table.'.revenda_id', $revendaIds);
            }
        });
    }
}
