<?php

namespace App\Support;

class ConciliacaoDimensao
{
    public static function chaveConfronto(
        string $idCliente,
        ?string $meio,
        ?string $parcelamento,
        ?string $bandeira,
        ?string $escrow,
        ?string $mcc,
        ?string $solucao,
    ): string {
        $partes = [
            self::normalizarTexto($idCliente),
            self::normalizarTexto($meio),
            self::normalizarParcelamento($parcelamento),
            self::normalizarBandeira($bandeira),
            self::normalizarTexto($escrow),
            self::normalizarTexto($mcc),
            self::normalizarTexto($solucao),
        ];

        return hash('sha256', implode('|', $partes));
    }

    public static function parcelamentoDoEdi(?string $quantidadeParcela): string
    {
        $parcelas = max((int) preg_replace('/\D/', '', (string) $quantidadeParcela), 1);

        return match (true) {
            $parcelas <= 1 => 'a vista',
            $parcelas <= 6 => '2 a 6',
            $parcelas <= 12 => '7 a 12',
            $parcelas <= 18 => '13 a 18',
            default => '13 a 18',
        };
    }

    public static function bandeiraDoEdi(?string $instituicao, ?string $tipoTransacao, ?string $arranjoUr): string
    {
        $tipo = strtolower(trim((string) $tipoTransacao));

        if ($tipo === 'pix') {
            return 'falha';
        }

        $inst = strtolower(trim((string) $instituicao));

        if ($inst !== '') {
            return match (true) {
                str_contains($inst, 'visa') => 'visa',
                str_contains($inst, 'master') => 'master',
                str_contains($inst, 'elo') => 'elo',
                str_contains($inst, 'amex'), str_contains($inst, 'american') => 'american express',
                str_contains($inst, 'banri') => 'banricompras',
                str_contains($inst, 'cabal') => 'cabal',
                str_contains($inst, 'hiper') => 'hipercard',
                default => $inst,
            };
        }

        $arranjo = strtoupper(trim((string) $arranjoUr));

        return match (true) {
            str_contains($arranjo, 'VISA') => 'visa',
            str_contains($arranjo, 'MASTERCARD'), str_contains($arranjo, 'MASTER') => 'master',
            str_contains($arranjo, 'ELO') => 'elo',
            str_contains($arranjo, 'AMEX') => 'american express',
            default => 'demais bandeiras',
        };
    }

    public static function solucaoDoEdi(?string $meioCaptura, ?string $canalEntrada, ?string $leitor): string
    {
        $valor = strtolower(trim((string) ($meioCaptura ?: $canalEntrada ?: $leitor)));

        return match (true) {
            str_contains($valor, 'tap') => 'tap on',
            str_contains($valor, 'web'), $valor === 'we' => 'web',
            default => 'mobile',
        };
    }

    public static function escrowDoEdi(?string $pagamentoPrazo): string
    {
        $valor = trim((string) $pagamentoPrazo);

        return $valor !== '' ? $valor : '0';
    }

    public static function mccDoEstabelecimento(?string $segmento): string
    {
        return trim((string) $segmento);
    }

    private static function normalizarParcelamento(?string $valor): string
    {
        $valor = strtolower(trim((string) $valor));

        return match ($valor) {
            'a vista', 'à vista', 'avista' => 'a vista',
            '2 a 6', '2-6' => '2 a 6',
            '7 a 12', '7-12' => '7 a 12',
            '13 a 18', '13-18' => '13 a 18',
            default => $valor,
        };
    }

    private static function normalizarBandeira(?string $valor): string
    {
        return strtolower(trim((string) $valor));
    }

    private static function normalizarTexto(?string $valor): string
    {
        return strtolower(trim((string) $valor));
    }
}
