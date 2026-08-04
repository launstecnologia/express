<?php

namespace App\Console\Commands;

use App\Models\Conciliacao;
use App\Support\ConciliacaoDimensao;
use App\Support\ComissaoAdminSql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConciliacaoDiagnosticarCommand extends Command
{
    protected $signature = 'conciliacao:diagnosticar {conciliacao : ID da conciliação}';

    protected $description = 'Diagnostica o confronto PagSeguro × EDI (contagens, amostras de chave e sobreposição)';

    public function handle(): int
    {
        $conciliacao = Conciliacao::query()->find($this->argument('conciliacao'));

        if (! $conciliacao) {
            $this->error('Conciliação não encontrada.');

            return self::FAILURE;
        }

        $inicio = $conciliacao->referencia_mes->copy()->startOfMonth()->toDateString();
        $fim = $conciliacao->referencia_mes->copy()->endOfMonth()->toDateString();

        $this->info("Conciliação #{$conciliacao->id} — referência {$conciliacao->referencia_mes->format('m/Y')}");
        $this->line("Período EDI: {$inicio} a {$fim}");
        $this->newLine();

        $ediTotal = DB::table('edi_movimentos')
            ->whereBetween('data_inicial_transacao', [$inicio, $fim])
            ->count();

        $ediComEstab = DB::table('edi_movimentos')
            ->whereBetween('data_inicial_transacao', [$inicio, $fim])
            ->whereNotNull('estabelecimento_id')
            ->count();

        $this->table(['Métrica', 'Valor'], [
            ['Linhas PagSeguro', (string) $conciliacao->total_linhas],
            ['Linhas com estabelecimento', (string) $conciliacao->linhas()->where('sem_estabelecimento', false)->count()],
            ['Status OK', (string) $conciliacao->linhas_ok],
            ['Status divergente', (string) $conciliacao->linhas_divergentes],
            ['Status sem EDI', (string) $conciliacao->linhas_sem_edi],
            ['Movimentos EDI no mês (total)', (string) $ediTotal],
            ['Movimentos EDI com estabelecimento_id', (string) $ediComEstab],
        ]);

        if ($ediComEstab === 0) {
            $this->warn('Nenhum movimento EDI com estabelecimento_id neste mês. Sincronize o EDI antes de reconfrontar.');

            return self::SUCCESS;
        }

        $chavesPag = $conciliacao->linhas()
            ->where('sem_estabelecimento', false)
            ->limit(5000)
            ->get()
            ->map(fn ($linha) => ConciliacaoDimensao::chaveConfrontoDaLinha(
                (string) $linha->id_cliente,
                $linha->meio_pagamento,
                $linha->parcelamento_agrupado,
                $linha->bandeira,
                $linha->escrow,
                $linha->solucao,
            ))
            ->unique()
            ->flip();

        $chavesEdi = [];
        $amostrasEdi = [];

        $query = DB::table('edi_movimentos as em')
            ->leftJoin('estabelecimentos as e', 'e.id', '=', 'em.estabelecimento_id')
            ->leftJoin('plano_taxas as pt', function ($join) {
                ComissaoAdminSql::joinPlanoTaxa($join);
            });

        ComissaoAdminSql::joinRoyaltyAdmin($query)
            ->whereBetween('em.data_inicial_transacao', [$inicio, $fim])
            ->whereNotNull('em.estabelecimento_id')
            ->select([
                'em.tipo_transacao',
                'em.meio_pagamento',
                'em.arranjo_ur',
                'em.quantidade_parcela',
                'em.instituicao_financeira',
                'em.meio_captura',
                'em.canal_entrada',
                'em.leitor',
                'em.pagamento_prazo',
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
            ]);

        foreach ($query->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            $chave = ConciliacaoDimensao::chaveConfrontoDaLinha(
                $idCliente,
                ConciliacaoDimensao::meioDoEdi(
                    $mov->tipo_transacao,
                    $mov->meio_pagamento,
                    $mov->arranjo_ur,
                    $mov->quantidade_parcela,
                ),
                ConciliacaoDimensao::parcelamentoDoEdi($mov->quantidade_parcela),
                ConciliacaoDimensao::bandeiraDoEdi($mov->instituicao_financeira, $mov->tipo_transacao, $mov->arranjo_ur),
                ConciliacaoDimensao::escrowDoEdi($mov->pagamento_prazo),
                ConciliacaoDimensao::solucaoDoEdi($mov->meio_captura, $mov->canal_entrada, $mov->leitor),
            );

            $chavesEdi[$chave] = true;

            if (count($amostrasEdi) < 5) {
                $amostrasEdi[] = [
                    $idCliente,
                    ConciliacaoDimensao::meioDoEdi($mov->tipo_transacao, $mov->meio_pagamento, $mov->arranjo_ur, $mov->quantidade_parcela),
                    ConciliacaoDimensao::solucaoDoEdi($mov->meio_captura, $mov->canal_entrada, $mov->leitor),
                    substr($chave, 0, 12).'…',
                ];
            }
        }

        $sobreposicao = count(array_intersect_key($chavesEdi, $chavesPag->all()));

        $this->newLine();
        $this->info("Chaves únicas PagSeguro: {$chavesPag->count()}");
        $this->info('Chaves únicas EDI: '.count($chavesEdi));
        $this->info("Chaves em comum: {$sobreposicao}");

        if ($sobreposicao === 0 && $chavesPag->isNotEmpty() && $chavesEdi !== []) {
            $this->warn('Nenhuma chave em comum — verifique dimensões (solução, meio, id_cliente).');
        }

        $amostrasPag = $conciliacao->linhas()
            ->where('sem_estabelecimento', false)
            ->limit(5)
            ->get(['id_cliente', 'meio_pagamento', 'solucao'])
            ->map(fn ($l) => [
                $l->id_cliente,
                ConciliacaoDimensao::meioNormalizado($l->meio_pagamento),
                ConciliacaoDimensao::solucaoNormalizada($l->solucao),
            ])
            ->all();

        $this->newLine();
        $this->comment('Amostra PagSeguro (id_cliente, meio, solução):');
        $this->table(['ID cliente', 'Meio', 'Solução'], $amostrasPag);

        $this->comment('Amostra EDI (id_cliente, meio, solução):');
        $this->table(['ID cliente', 'Meio', 'Solução', 'Chave'], $amostrasEdi);

        return self::SUCCESS;
    }
}
