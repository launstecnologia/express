<?php

namespace App\Rules;

use App\Models\SubUsuario;
use App\Models\Usuario;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EmailUnicoAutenticacao implements ValidationRule
{
    public function __construct(
        private ?int $ignoreUsuarioId = null,
        private ?int $ignoreSubUsuarioId = null,
        /** Permite reutilizar o e-mail do dono ao cadastrar SubUsuario operacional */
        private ?int $permitirEmailDonoId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        if ($email === '') {
            return;
        }

        $usuarioComEmail = Usuario::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->when($this->ignoreUsuarioId, fn ($query) => $query->whereKeyNot($this->ignoreUsuarioId))
            ->first();

        if ($usuarioComEmail) {
            $reutilizaDono = $this->permitirEmailDonoId
                && (int) $usuarioComEmail->id === (int) $this->permitirEmailDonoId;

            if (! $reutilizaDono) {
                $fail('Este e-mail já está cadastrado no sistema.');

                return;
            }
        }

        $subUsuarioConflito = SubUsuario::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->when($this->ignoreSubUsuarioId, fn ($query) => $query->whereKeyNot($this->ignoreSubUsuarioId))
            ->when(
                $this->permitirEmailDonoId,
                fn ($query) => $query->where('dono_id', '!=', $this->permitirEmailDonoId)
            )
            ->exists();

        if ($subUsuarioConflito) {
            $fail('Este e-mail já está cadastrado no sistema.');

            return;
        }

        if ($this->permitirEmailDonoId && ! $this->ignoreSubUsuarioId) {
            $jaExisteNoDono = SubUsuario::query()
                ->where('dono_id', $this->permitirEmailDonoId)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($jaExisteNoDono) {
                $fail('Já existe um usuário operacional com este e-mail para esta conta.');
            }
        }
    }
}
