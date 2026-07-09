<?php

namespace App\Support;

class InstituicaoFinanceira
{
    private const INSTITUICOES = [
        'VISA',
        'MASTERCARD',
        'ELO',
        'AMEX',
        'HIPERCARD',
        'DINERS',
        'CABAL',
        'BACEN',
        'BANRICOMPRAS',
    ];

    /**
     * @return array{slug: string, cor: string, nome: string, tipo: string}
     */
    public static function meta(?string $codigo): array
    {
        $codigo = strtoupper(trim((string) $codigo));

        return match ($codigo) {
            'VISA' => ['slug' => 'visa', 'cor' => '1A1F71', 'nome' => 'Visa', 'tipo' => 'simpleicons'],
            'MASTERCARD' => ['slug' => 'mastercard', 'cor' => 'EB001B', 'nome' => 'Mastercard', 'tipo' => 'simpleicons'],
            'AMEX', 'AMERICAN EXPRESS' => ['slug' => 'americanexpress', 'cor' => '2E77BC', 'nome' => 'American Express', 'tipo' => 'simpleicons'],
            'ELO' => ['slug' => 'elo', 'cor' => '00A4E0', 'nome' => 'Elo', 'tipo' => 'cdn'],
            'HIPERCARD' => ['slug' => 'hipercard', 'cor' => '822124', 'nome' => 'Hipercard', 'tipo' => 'cdn'],
            'DINERS', 'DINERS CLUB' => ['slug' => 'dinersclub', 'cor' => '004A97', 'nome' => 'Diners Club', 'tipo' => 'simpleicons'],
            'CABAL' => ['slug' => 'cabal', 'cor' => '005EB8', 'nome' => 'Cabal', 'tipo' => 'cdn'],
            'BACEN' => ['slug' => 'pix', 'cor' => '32BCAD', 'nome' => 'PIX', 'tipo' => 'simpleicons'],
            'BANRICOMPRAS' => ['slug' => 'banricompras', 'cor' => '005EB8', 'nome' => 'Banricompras', 'tipo' => 'fallback'],
            default => ['slug' => 'creditcard', 'cor' => '64748b', 'nome' => $codigo ?: 'Outra', 'tipo' => 'fontawesome'],
        };
    }

    public static function iconUrl(?string $codigo): ?string
    {
        $meta = self::meta($codigo);

        if ($meta['tipo'] === 'fontawesome' || $meta['tipo'] === 'fallback') {
            return null;
        }

        $arquivoLocal = public_path('images/bandeiras/'.$meta['slug'].'.svg');
        if (self::svgLocalValido($arquivoLocal)) {
            return asset('images/bandeiras/'.$meta['slug'].'.svg');
        }

        if ($meta['tipo'] === 'simpleicons') {
            return 'https://cdn.jsdelivr.net/npm/simple-icons@11.14.0/icons/'.$meta['slug'].'.svg';
        }

        return null;
    }

    private static function svgLocalValido(string $caminho): bool
    {
        if (! is_file($caminho) || filesize($caminho) < 80) {
            return false;
        }

        $conteudo = file_get_contents($caminho, false, null, 0, 200);

        return is_string($conteudo) && str_contains($conteudo, '<svg');
    }

    public static function nome(?string $codigo): string
    {
        return self::meta($codigo)['nome'];
    }

    /**
     * @return list<string>
     */
    public static function codigos(): array
    {
        return self::INSTITUICOES;
    }
}
