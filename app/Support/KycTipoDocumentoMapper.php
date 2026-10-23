<?php

namespace App\Support;

class KycTipoDocumentoMapper
{
    /**
     * Tipos exibidos na aba Documentos do estabelecimento.
     *
     * @return array<int, string>
     */
    public static function tiposEstabelecimento(?string $pessoaTipo = null): array
    {
        $tipos = [
            'RG/CNH - Frente',
            'RG/CNH - Verso',
        ];

        if ($pessoaTipo === 'juridica') {
            $tipos[] = 'Contrato Social (Se for MEI - CCMEI)';
        }

        $tipos[] = 'Comprovante de Endereço';
        $tipos[] = 'Comprovante da Atividade';
        $tipos[] = 'Selfie Segurando Documento';

        return $tipos;
    }

    public static function tipoKyc(?string $tipoDocumento): ?string
    {
        if ($tipoDocumento === null || $tipoDocumento === '') {
            return null;
        }

        $texto = mb_strtolower(trim($tipoDocumento));

        if (str_contains($texto, 'selfie')) {
            return 'selfie_documento';
        }

        if (str_contains($texto, 'endere')) {
            return 'comprovante_endereco';
        }

        if (str_contains($texto, 'contrato') || str_contains($texto, 'ccmei')) {
            return 'contrato_social';
        }

        if (str_contains($texto, 'cnpj') && ! str_contains($texto, 'contrato')) {
            return 'cartao_cnpj';
        }

        if (str_contains($texto, 'verso')) {
            return str_contains($texto, 'cnh') ? 'cnh_verso' : 'rg_verso';
        }

        if (str_contains($texto, 'frente')) {
            return str_contains($texto, 'cnh') ? 'cnh_frente' : 'rg_frente';
        }

        return null;
    }

    public static function labelEstabelecimento(string $tipoKyc): ?string
    {
        foreach (self::tiposEstabelecimento('juridica') as $label) {
            if (self::tipoKyc($label) === $tipoKyc) {
                return $label;
            }
        }

        return null;
    }
}
