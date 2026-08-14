<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\EstabelecimentoEtapaListagem;
use App\Support\SimpleXlsxWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstabelecimentoTransacaoRelatorioService
{
    public function consultar(int $marketplaceId, string $de, string $ate): Collection
    {
        return DB::table('estabelecimentos as e')
            ->leftJoin('edi_movimentos as em', function ($join) use ($de, $ate) {
                $join->on('em.estabelecimento_id', '=', 'e.id')
                    ->whereBetween('em.data_inicial_transacao', [$de, $ate]);
            })
            ->where('e.marketplace_id', $marketplaceId)
            ->groupBy(
                'e.id',
                'e.nome_fantasia',
                'e.razao_social',
                'e.nome_completo',
                'e.cnpj',
                'e.cpf',
                'e.status',
                'e.ativo',
                'e.token_pagseguro',
                'e.pagbank_edi_ativo',
                'e.plano_id',
                'e.revenda_id',
                'e.created_at',
            )
            ->orderByDesc(DB::raw('COALESCE(SUM(em.valor_total_transacao), 0)'))
            ->orderBy('e.id')
            ->get([
                'e.id',
                'e.nome_fantasia',
                'e.razao_social',
                'e.nome_completo',
                'e.cnpj',
                'e.cpf',
                'e.status',
                'e.ativo',
                'e.token_pagseguro',
                'e.pagbank_edi_ativo',
                'e.plano_id',
                'e.revenda_id',
                'e.created_at',
                DB::raw('COUNT(em.id) as qtd_transacoes'),
                DB::raw('COALESCE(SUM(em.valor_total_transacao), 0) as tpv'),
                DB::raw('MIN(em.data_inicial_transacao) as primeira_venda'),
                DB::raw('MAX(em.data_inicial_transacao) as ultima_venda'),
                DB::raw("SUM(CASE WHEN COALESCE(em.num_logico, '') <> '' OR COALESCE(em.numero_serie_leitor, '') <> '' THEN 1 ELSE 0 END) as qtd_terminal"),
            ]);
    }

    public function filtrar(Collection $rows, ?string $filtro): Collection
    {
        return match ($filtro) {
            'com' => $rows->filter(fn ($r) => (int) $r->qtd_transacoes > 0)->values(),
            'sem' => $rows->filter(fn ($r) => (int) $r->qtd_transacoes === 0)->values(),
            default => $rows,
        };
    }

    public function resumo(Collection $rows): array
    {
        $total = $rows->count();
        $comToken = $rows->filter(fn ($r) => filled($r->token_pagseguro))->count();

        return [
            'total' => $total,
            'com_token' => $comToken,
            'sem_token' => $total - $comToken,
            'edi_ativo' => $rows->filter(fn ($r) => (int) $r->pagbank_edi_ativo === 1)->count(),
            'com_transacao' => $rows->filter(fn ($r) => (int) $r->qtd_transacoes > 0)->count(),
            'sem_transacao' => $rows->filter(fn ($r) => (int) $r->qtd_transacoes === 0)->count(),
            'sem_transacao_com_token' => $rows->filter(
                fn ($r) => (int) $r->qtd_transacoes === 0 && filled($r->token_pagseguro)
            )->count(),
            'qtd_transacoes' => (int) $rows->sum('qtd_transacoes'),
            'qtd_terminal' => (int) $rows->sum('qtd_terminal'),
            'tpv' => (float) $rows->sum('tpv'),
        ];
    }

    public function gerarXlsx(Usuario $marketplace, Collection $rows, string $de, string $ate): string
    {
        $cabecalhos = [
            'ID',
            'Nome',
            'Documento',
            'Cadastrado em',
            'Status',
            'Ativo',
            'Safepay ID',
            'EDI ativo',
            'Plano ID',
            'Revenda ID',
            'Qtd transações',
            'Qtd terminal',
            'TPV',
            'Primeira venda',
            'Última venda',
        ];

        $linhas = $rows->map(function ($r) {
            return [
                (int) $r->id,
                $this->nome($r),
                $r->cnpj ?: $r->cpf ?: '',
                $this->dataHora($r->created_at),
                EstabelecimentoEtapaListagem::normalizarStatus($r->status),
                ((int) $r->ativo === 1) ? 'sim' : 'não',
                (string) ($r->token_pagseguro ?: ''),
                ((int) $r->pagbank_edi_ativo === 1) ? 'sim' : 'não',
                $r->plano_id !== null ? (int) $r->plano_id : '',
                $r->revenda_id !== null ? (int) $r->revenda_id : '',
                (int) $r->qtd_transacoes,
                (int) $r->qtd_terminal,
                round((float) $r->tpv, 2),
                $this->data($r->primeira_venda),
                $this->data($r->ultima_venda),
            ];
        })->all();

        $nomeAba = 'MKT '.$marketplace->id;

        return SimpleXlsxWriter::binary($cabecalhos, $linhas, mb_substr($nomeAba, 0, 31));
    }

    public function nomeArquivo(Usuario $marketplace, string $de, string $ate): string
    {
        return 'mkt-'.$marketplace->id.'-transacoes-'.$de.'_'.$ate.'.xlsx';
    }

    public function nome(object $r): string
    {
        return $r->nome_fantasia ?: $r->razao_social ?: $r->nome_completo ?: '—';
    }

    public function data(?string $valor): string
    {
        if (! $valor) {
            return '';
        }

        return now()->parse($valor)->format('d/m/Y');
    }

    public function dataHora(?string $valor): string
    {
        if (! $valor) {
            return '';
        }

        return now()->parse($valor)->format('d/m/Y H:i');
    }
}
