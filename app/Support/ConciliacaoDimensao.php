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
        ?string $solucao,
    ): string {
        $partes = [
            self::idClienteNormalizado($idCliente),
            self::meioNormalizado($meio),
            self::normalizarParcelamento($parcelamento),
            self::bandeiraNormalizada($bandeira),
            self::escrowNormalizado($escrow),
            self::solucaoNormalizada($solucao),
        ];

        return hash('sha256', implode('|', $partes));
    }

    public static function chaveConfrontoDaLinha(
        string $idCliente,
        ?string $meio,
        ?string $parcelamento,
        ?string $bandeira,
        ?string $escrow,
        ?string $solucao,
    ): string {
        return self::chaveConfronto($idCliente, $meio, $parcelamento, $bandeira, $escrow, $solucao);
    }

    public static function meioNormalizado(?string $meio): string
    {
        $valor = strtolower(trim((string) $meio));

        return match (true) {
            $valor === 'pix' => 'pix',
            str_contains($valor, 'debit') => 'debito',
            str_contains($valor, 'cred') => 'credito',
            in_array($valor, ['debito', 'credito'], true) => $valor,
            default => $valor,
        };
    }

    public static function meioDoEdi(
        ?string $tipoTransacao,
        ?string $meioPagamento,
        ?string $arranjoUr,
        ?string $quantidadeParcela = null,
    ): string {
        $tipoBruto = strtolower(trim((string) $tipoTransacao));
        $tipoParaResolver = in_array($tipoBruto, ['debito', 'credito', 'pix', 'outros', 'parcelado'], true)
            ? null
            : $tipoTransacao;

        $categoria = EdiTransacaoCategoria::resolver(
            $tipoParaResolver,
            $meioPagamento,
            $arranjoUr,
            $quantidadeParcela,
        );

        $meio = match ($categoria) {
            'debito', 'credito', 'pix' => $categoria,
            'parcelado' => 'credito',
            default => 'outros',
        };

        return self::meioNormalizado($meio);
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
            return self::bandeiraNormalizada($inst);
        }

        $arranjo = strtoupper(trim((string) $arranjoUr));

        return match (true) {
            str_contains($arranjo, 'VISA') => 'visa',
            str_contains($arranjo, 'MASTERCARD'), str_contains($arranjo, 'MASTER') => 'master',
            str_contains($arranjo, 'ELO') => 'elo',
            str_contains($arranjo, 'AMEX') => 'american express',
            str_contains($arranjo, 'PIX') => 'falha',
            default => 'demais bandeiras',
        };
    }

    public static function bandeiraNormalizada(?string $bandeira): string
    {
        $valor = strtolower(trim((string) $bandeira));

        if ($valor === '') {
            return 'demais bandeiras';
        }

        return match (true) {
            $valor === 'pix', $valor === 'falha' => 'falha',
            str_contains($valor, 'visa') => 'visa',
            str_contains($valor, 'master') => 'master',
            str_contains($valor, 'elo') => 'elo',
            str_contains($valor, 'amex'), str_contains($valor, 'american') => 'american express',
            str_contains($valor, 'banri') => 'banricompras',
            str_contains($valor, 'cabal') => 'cabal',
            str_contains($valor, 'hiper') => 'hipercard',
            default => $valor,
        };
    }

    public static function solucaoDoEdi(?string $meioCaptura, ?string $canalEntrada, ?string $leitor): string
    {
        $canal = strtoupper(trim((string) $canalEntrada));

        if ($canal !== '') {
            $solucao = match ($canal) {
                'TP' => 'tap on',
                'W' => 'web',
                'ME', 'AP', 'MD', 'MP', 'MT', 'N', 'WT', 'TF', 'QR', 'LK' => 'mobile',
                default => null,
            };

            if ($solucao !== null) {
                return $solucao;
            }
        }

        $partes = array_filter([
            trim((string) $meioCaptura),
            trim((string) $canalEntrada),
            trim((string) $leitor),
        ], fn (string $valor) => $valor !== '');

        return self::solucaoNormalizada(implode(' ', $partes));
    }

    public static function solucaoNormalizada(?string $solucao): string
    {
        $valor = strtolower(trim((string) $solucao));

        return match (true) {
            $valor === '' => 'mobile',
            str_contains($valor, 'tap'), str_contains($valor, ' tp'), $valor === 'tp' => 'tap on',
            str_contains($valor, 'web'), $valor === 'we', $valor === 'w' => 'web',
            str_contains($valor, 'link'),
            str_contains($valor, 'mobile'),
            str_contains($valor, 'maquininha'),
            str_contains($valor, 'machine'),
            preg_match('/\bme\b/', $valor) === 1 => 'mobile',
            in_array($valor, ['01', '1', 'me'], true) => 'mobile',
            default => $valor,
        };
    }

    public static function escrowDoEdi(?string $pagamentoPrazo): string
    {
        return self::escrowNormalizado($pagamentoPrazo);
    }

    public static function escrowNormalizado(?string $escrow): string
    {
        $valor = trim((string) $escrow);

        if ($valor === '') {
            return '0';
        }

        return ltrim($valor, '0') === '' ? '0' : ltrim($valor, '0');
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

    public static function idClienteNormalizado(?string $id): string
    {
        $id = trim((string) $id);

        if ($id === '') {
            return '';
        }

        if (ctype_digit($id)) {
            return ltrim($id, '0') ?: '0';
        }

        return strtolower($id);
    }

    private static function normalizarTexto(?string $valor): string
    {
        return strtolower(trim((string) $valor));
    }
}
