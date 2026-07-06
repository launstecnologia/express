<?php

namespace App\Services;

use App\Models\SubUsuario;
use App\Models\Usuario;

class SubUsuarioPrincipalService
{
    /**
     * Garante um usuário operacional com o mesmo e-mail da conta comercial (dono).
     * Usado no cadastro de master/marketplace/revenda e para contas já existentes.
     */
    public function garantirParaDono(Usuario $dono, ?string $senhaPlana = null): SubUsuario
    {
        abort_if($dono->tipo === 'admin', 422, 'Administradores não possuem usuário operacional vinculado.');

        $email = strtolower(trim((string) $dono->email));

        $existente = SubUsuario::query()
            ->where('dono_id', $dono->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($existente) {
            return $existente;
        }

        return SubUsuario::create([
            'dono_id' => $dono->id,
            'dono_tipo' => $dono->tipo,
            'nome' => $dono->nomeExibicao(),
            'email' => $email,
            'password' => $senhaPlana ?? '123456',
            'must_change_password' => (bool) $dono->must_change_password,
            'ativo' => (bool) $dono->ativo,
        ]);
    }

    public function donoTemPrincipal(Usuario $dono): bool
    {
        $email = strtolower(trim((string) $dono->email));

        if ($email === '') {
            return false;
        }

        return SubUsuario::query()
            ->where('dono_id', $dono->id)
            ->whereRaw('LOWER(email) = ?', [$email])
            ->exists();
    }
}
