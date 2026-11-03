<?php

namespace App\Support;

use App\Models\Estabelecimento;
use RuntimeException;

class EmailPagBank
{
    public static function dominio(): string
    {
        $dominio = strtolower(trim((string) config('directadmin.dominio', 'express.app.br')));
        $dominio = ltrim($dominio, '@');

        return $dominio !== '' ? $dominio : 'express.app.br';
    }

    public static function daPlataforma(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || ! str_ends_with($email, '@'.self::dominio())) {
            return null;
        }

        return $email;
    }

    public static function obrigatorio(Estabelecimento $estabelecimento): string
    {
        $email = self::daPlataforma($estabelecimento->webmail_email);

        if ($email === null) {
            throw new RuntimeException(
                'A automação só usa o e-mail da plataforma @'.self::dominio()
                .'. Crie esse e-mail antes de cadastrar no PagBank. O e-mail do cliente não pode ser usado.'
            );
        }

        return $email;
    }
}
