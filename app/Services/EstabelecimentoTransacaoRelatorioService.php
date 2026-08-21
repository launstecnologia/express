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
        $historico = DB::table('edi_movimentos')
            ->select([
                'estabelecimento_id',
                DB::raw('COUNT(*) as qtd_historico'),
                DB::raw('COALESCE(SUM(valor_total_transacao), 0) as tpv_historico'),
                DB::raw('MAX(data_inicial_transacao) as ultima_historico'),
            ])
            ->whereNotNull('estabelecimento_id')
            ->groupBy('estabelecimento_id');

        $porToken = DB::table('edi_movimentos')
            ->select([
                'estabelecimento as token',
                DB::raw('COUNT(*) as qtd_token'),
                DB::raw('COALESCE(SUM(valor_total_transacao), 0) as tpv_token'),
                DB::raw('MAX(data_inicial_transacao) as ultima_token'),
            ])
            ->whereNotNull('estabelecimento')
            ->where('estabelecimento', '!=', '')
            ->groupBy('estabelecimento');

        return DB::table('estabelecimentos as e')
            ->leftJoin('edi_movimentos as em', function ($join) use ($de, $ate) {
                $join->on('em.estabelecimento_id', '=', 'e.id')
                    ->whereBetween('em.data_inicial_transacao', [$de, $ate]);
            })
            ->leftJoinSub($historico, 'hist', 'hist.estabelecimento_id', '=', 'e.id')
            ->leftJoinSub($porToken, 'tok', 'tok.token', '=', 'e.token_pagseguro')
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
                'hist.qtd_historico',
                'hist.tpv_historico',
                'hist.ultima_historico',
                'tok.qtd_token',
                'tok.tpv_token',
                'tok.ultima_token',
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
                DB::raw('COALESCE(hist.qtd_historico, 0) as qtd_historico'),
                DB::raw('COALESCE(hist.tpv_historico, 0) as tpv_historico'),
                'hist.ultima_historico',
                DB::raw('COALESCE(tok.qtd_token, 0) as qtd_token'),
                DB::raw('COALESCE(tok.tpv_token, 0) as tpv_token'),
                'tok.ultima_token',
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
            'com_historico' => $rows->filter(fn ($r) => (int) ($r->qtd_historico ?? 0) > 0)->count(),
            'com_token_edi' => $rows->filter(fn ($r) => (int) ($r->qtd_token ?? 0) > 0)->count(),
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
            'Qtd transações período',
            'Qtd terminal período',
            'TPV período',
            'Primeira venda período',
            'Última venda período',
            'Tx histórico (pelo ID)',
            'TPV histórico (pelo ID)',
            'Última venda histórico',
            'Tx no banco pelo Safepay ID',
            'TPV pelo Safepay ID',
            'Última venda pelo Safepay ID',
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
                (int) ($r->qtd_historico ?? 0),
                round((float) ($r->tpv_historico ?? 0), 2),
                $this->data($r->ultima_historico ?? null),
                (int) ($r->qtd_token ?? 0),
                round((float) ($r->tpv_token ?? 0), 2),
                $this->data($r->ultima_token ?? null),
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
