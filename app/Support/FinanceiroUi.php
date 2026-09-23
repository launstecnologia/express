<?php

namespace App\Support;

class FinanceiroUi
{
    /**
     * Valores, faturamento, comissões/repasses e transações ficam ocultos
     * enquanto este flag estiver desligado.
     */
    public static function visivel(): bool
    {
        return (bool) config('app.financeiro_visivel', false);
    }

    /**
     * Card, coluna e totais de comissão no Dashboard e no Faturamento.
     * Admin, marketplace e revenda não veem esses valores nessas telas;
     * a apuração continua na página Comissão.
     */
    public static function comissaoNosTotaisVisivel(): bool
    {
        if (! self::visivel()) {
            return false;
        }

        return ! in_array(UsuarioComercial::tipo(), ['admin', 'marketplace', 'revenda'], true);
    }
}
