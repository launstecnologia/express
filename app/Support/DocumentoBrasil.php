<?php

namespace App\Support;

class DocumentoBrasil
{
    public static function apenasDigitos(?string $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor);
    }

    public static function cpfValido(?string $cpf): bool
    {
        $cpf = self::apenasDigitos($cpf);

        if (strlen($cpf) !== 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * ($t + 1 - $i);
            }
            $rem = (10 * $sum) % 11 % 10;
            if ((int) $cpf[$t] !== $rem) {
                return false;
            }
        }

        return true;
    }

    public static function formatarCpf(?string $cpf): string
    {
        $n = self::apenasDigitos($cpf);

        if (strlen($n) !== 11) {
            return (string) $cpf;
        }

        return substr($n, 0, 3).'.'
            .substr($n, 3, 3).'.'
            .substr($n, 6, 3).'-'
            .substr($n, 9, 2);
    }

    public static function cnpjValido(?string $cnpj): bool
    {
        $cnpj = self::apenasDigitos($cnpj);

        if (strlen($cnpj) !== 14 || preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }

        $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        for ($t = 0; $t < 2; $t++) {
            $sum = 0;
            $pesos = $t === 0 ? $pesos1 : $pesos2;
            $limite = $t === 0 ? 12 : 13;

            for ($i = 0; $i < $limite; $i++) {
                $sum += (int) $cnpj[$i] * $pesos[$i];
            }

            $rem = $sum % 11;
            $digito = $rem < 2 ? 0 : 11 - $rem;

            if ((int) $cnpj[12 + $t] !== $digito) {
                return false;
            }
        }

        return true;
    }

    public static function formatarCnpj(?string $cnpj): string
    {
        $n = self::apenasDigitos($cnpj);

        if (strlen($n) !== 14) {
            return (string) $cnpj;
        }

        return substr($n, 0, 2).'.'
            .substr($n, 2, 3).'.'
            .substr($n, 5, 3).'/'
            .substr($n, 8, 4).'-'
            .substr($n, 12, 2);
    }

    public static function formatarCpfOuCnpj(?string $valor): string
    {
        $n = self::apenasDigitos($valor);

        return match (strlen($n)) {
            11 => self::formatarCpf($n),
            14 => self::formatarCnpj($n),
            default => (string) $valor,
        };
    }

    public static function celularValido(?string $celular): bool
    {
        $n = self::apenasDigitos($celular);

        return strlen($n) === 11 && $n[2] === '9';
    }

    public static function formatarCelular(?string $celular): string
    {
        $n = self::apenasDigitos($celular);

        if (! self::celularValido($n)) {
            return (string) $celular;
        }

        return '('.substr($n, 0, 2).') '.substr($n, 2, 5).'-'.substr($n, 7);
    }
}
