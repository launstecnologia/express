<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Nome do marketplace no Excel legado (coluna marketplace das linhas REP) →
 * nome do marketplace cadastrado na plataforma.
 *
 * No Excel as revendas referenciam o marketplace por um nome curto/comercial
 * que difere do nome cadastrado (ex.: "CONECTPAG" vs "CONECT ELETRONICOS E
 * EQUIPAMENTOS"). Este alias destrava o vínculo da revenda.
 */
class LegacyMarketplaceAlias
{
    /** @var array<string, string> chave normalizada => nome do marketplace na plataforma */
    private const ALIAS_PARA_NOME = [
        'CONECTPAG' => 'CONECT ELETRONICOS E EQUIPAMENTOS',
        'MULT PAY OK' => 'MULTPAY SERVICOS',
        'MULTPAY' => 'MULTPAY SERVICOS',
        'MULDIAL PAY' => 'MUNDIAL PAY',
        'MUNDIAL PAY' => 'MUNDIAL PAY',
        'GRUPO AVA' => 'ZUNO PAGAMENTOS',
        'PAGUE FACIL' => 'PAGUE FACIL SOLUCOES EM PAGAMENTO',
        'SOLUCAOPAY' => 'SOLUÇÃOPAY',
        'SOLUY‡AOPAY' => 'SOLUÇÃOPAY',
        'RM SOLUCOES' => 'RM SOLUÇÕES',
        'RM SOLUY‡Y•ES' => 'RM SOLUÇÕES',
    ];

    public static function nomePlataforma(?string $nome): ?string
    {
        $nome = trim((string) $nome);
        if ($nome === '') {
            return null;
        }

        foreach (self::chaves($nome) as $chave) {
            if (isset(self::ALIAS_PARA_NOME[$chave])) {
                return self::ALIAS_PARA_NOME[$chave];
            }

            $compacta = preg_replace('/[^A-Z0-9]/', '', $chave) ?? '';
            foreach (self::ALIAS_PARA_NOME as $alias => $destino) {
                $aliasCompacta = preg_replace('/[^A-Z0-9]/', '', $alias) ?? '';
                if ($compacta !== '' && $compacta === $aliasCompacta) {
                    return $destino;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function chaves(?string $texto): array
    {
        $texto = trim((string) $texto);
        if ($texto === '') {
            return [];
        }

        $ascii = Str::ascii($texto);

        return array_values(array_unique(array_filter([
            mb_strtoupper($texto),
            mb_strtoupper($ascii),
        ])));
    }
}
