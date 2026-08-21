<?php

namespace App\Support;

use App\Models\EdiMovimento;
use Illuminate\Support\Carbon;

class EdiMovimentoDetalhe
{
    /** @var array<string, string> */
    private const ROTULOS = [
        'id' => 'ID interno',
        'estabelecimento_id' => 'ID estabelecimento',
        'id_cliente' => 'ID cliente',
        'movimento_api_codigo' => 'Código movimento API',
        'tipo_registro' => 'Tipo registro',
        'estabelecimento' => 'Código estabelecimento (EDI)',
        'data_inicial_transacao' => 'Data inicial transação',
        'hora_inicial_transacao' => 'Hora inicial transação',
        'data_venda_ajuste' => 'Data venda/ajuste',
        'hora_venda_ajuste' => 'Hora venda/ajuste',
        'data_prevista_pagamento' => 'Data prevista pagamento',
        'tipo_evento' => 'Tipo evento',
        'tipo_transacao' => 'Tipo transação',
        'codigo_transacao' => 'Código transação',
        'codigo_venda' => 'Código venda',
        'valor_total_transacao' => 'Valor total transação',
        'valor_parcela' => 'Valor parcela',
        'valor_original_transacao' => 'Valor original transação',
        'valor_liquido_transacao' => 'Valor líquido transação',
        'taxa_intermediacao' => 'Taxa intermediação',
        'tarifa_intermediacao' => 'Tarifa intermediação',
        'comissao_percentual' => 'Comissão % (plano do EC)',
        'comissao_valor' => 'Comissão da transação',
        'pagamento_prazo' => 'Pagamento prazo',
        'plano' => 'Plano',
        'parcela' => 'Parcela',
        'quantidade_parcela' => 'Quantidade parcelas',
        'status_pagamento' => 'Status pagamento',
        'meio_pagamento' => 'Meio pagamento',
        'arranjo_ur' => 'Arranjo UR',
        'instituicao_financeira' => 'Instituição financeira',
        'canal_entrada' => 'Canal entrada',
        'leitor' => 'Leitor',
        'meio_captura' => 'Meio captura',
        'num_logico' => 'Número lógico',
        'nsu' => 'NSU',
        'cartao_bin' => 'Cartão BIN',
        'cartao_holder' => 'Cartão holder',
        'codigo_autorizacao' => 'Código autorização',
        'codigo_cv' => 'Código CV',
        'numero_serie_leitor' => 'Número série leitor',
        'tx_id' => 'TX ID (PIX)',
        'processado' => 'Processado',
        'data_importacao' => 'Data importação',
        'created_at' => 'Criado em',
        'updated_at' => 'Atualizado em',
    ];

    /** @var array<int, string> */
    private const CAMPOS_MONETARIOS = [
        'valor_total_transacao',
        'valor_parcela',
        'valor_original_transacao',
        'valor_liquido_transacao',
        'taxa_intermediacao',
        'tarifa_intermediacao',
        'comissao_valor',
    ];

    /** @var array<int, string> */
    private const CAMPOS_DATA = [
        'data_inicial_transacao',
        'data_venda_ajuste',
        'data_prevista_pagamento',
    ];

    /**
     * @return list<array{campo: string, rotulo: string, valor: string}>
     */
    public static function campos(EdiMovimento $movimento): array
    {
        $atributos = $movimento->getAttributes();
        $campos = [];

        foreach (array_keys(self::ROTULOS) as $campo) {
            if (! array_key_exists($campo, $atributos)) {
                continue;
            }

            $campos[] = [
                'campo' => $campo,
                'rotulo' => self::ROTULOS[$campo],
                'valor' => self::formatar($campo, $atributos[$campo]),
            ];
        }

        return $campos;
    }

    private static function formatar(string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        if ($campo === 'processado') {
            return filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? 'Sim' : 'Não';
        }

        if (in_array($campo, self::CAMPOS_MONETARIOS, true)) {
            return 'R$ '.number_format((float) $valor, 2, ',', '.');
        }

        if (in_array($campo, self::CAMPOS_DATA, true)) {
            return Carbon::parse($valor)->format('d/m/Y');
        }

        if (in_array($campo, ['data_importacao', 'created_at', 'updated_at'], true)) {
            return Carbon::parse($valor)->format('d/m/Y H:i:s');
        }

        if (in_array($campo, ['hora_inicial_transacao', 'hora_venda_ajuste'], true)) {
            return substr((string) $valor, 0, 8);
        }

        return (string) $valor;
    }
}
