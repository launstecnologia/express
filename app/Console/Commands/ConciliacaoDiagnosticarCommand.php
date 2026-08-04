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

        $idsPag = $conciliacao->linhas()
            ->where('sem_estabelecimento', false)
            ->distinct()
            ->pluck('id_cliente')
            ->map(fn ($id) => ConciliacaoDimensao::idClienteNormalizado((string) $id))
            ->flip();

        $chavesPag = [];

        foreach ($conciliacao->linhas()->where('sem_estabelecimento', false)->cursor() as $linha) {
            $chavesPag[ConciliacaoDimensao::chaveConfrontoDaLinha(
                (string) $linha->id_cliente,
                $linha->meio_pagamento,
                $linha->parcelamento_agrupado,
                $linha->bandeira,
                $linha->escrow,
                $linha->solucao,
            )] = true;
        }

        $chavesEdi = [];
        $idsEdi = [];
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
                'em.plano',
                DB::raw('COALESCE(e.token_pagseguro, em.estabelecimento, em.id_cliente) as id_cliente'),
            ]);

        foreach ($query->orderBy('em.id')->cursor() as $mov) {
            $idCliente = trim((string) $mov->id_cliente);

            if ($idCliente === '') {
                continue;
            }

            $idNorm = ConciliacaoDimensao::idClienteNormalizado($idCliente);
            $idsEdi[$idNorm] = true;

            $escrow = ConciliacaoDimensao::escrowDoEdi($mov->pagamento_prazo, $mov->plano);
            $meio = ConciliacaoDimensao::meioDoEdi(
                $mov->tipo_transacao,
                $mov->meio_pagamento,
                $mov->arranjo_ur,
                $mov->quantidade_parcela,
            );
            $solucao = ConciliacaoDimensao::solucaoDoEdi($mov->meio_captura, $mov->canal_entrada, $mov->leitor);

            $chave = ConciliacaoDimensao::chaveConfrontoDaLinha(
                $idCliente,
                $meio,
                ConciliacaoDimensao::parcelamentoDoEdi($mov->quantidade_parcela),
                ConciliacaoDimensao::bandeiraDoEdi($mov->instituicao_financeira, $mov->tipo_transacao, $mov->arranjo_ur),
                $escrow,
                $solucao,
            );

            $chavesEdi[$chave] = true;

            if (count($amostrasEdi) < 5) {
                $amostrasEdi[] = [
                    $idCliente,
                    $meio,
                    $escrow,
                    $solucao,
                    substr($chave, 0, 12).'…',
                ];
            }
        }

        $idsEmComum = count(array_intersect_key($idsEdi, $idsPag->all()));
        $sobreposicao = count(array_intersect_key($chavesEdi, $chavesPag));

        $this->newLine();
        $this->info('IDs cliente distintos PagSeguro: '.$idsPag->count());
        $this->info('IDs cliente distintos EDI: '.count($idsEdi));
        $this->info("IDs cliente em comum: {$idsEmComum}");
        $this->info('Chaves únicas PagSeguro: '.count($chavesPag));
        $this->info('Chaves únicas EDI: '.count($chavesEdi));
        $this->info("Chaves em comum: {$sobreposicao}");

        if ($idsEmComum === 0) {
            $this->warn('Nenhum id_cliente em comum — tokens da planilha não batem com o EDI vinculado.');
        } elseif ($sobreposicao === 0) {
            $this->warn('IDs coincidem, mas nenhuma chave completa — verifique escrow, bandeira ou parcelamento.');
        }

        $amostrasPag = $conciliacao->linhas()
            ->where('sem_estabelecimento', false)
            ->limit(5)
            ->get(['id_cliente', 'meio_pagamento', 'escrow', 'solucao'])
            ->map(fn ($l) => [
                $l->id_cliente,
                ConciliacaoDimensao::meioNormalizado($l->meio_pagamento),
                ConciliacaoDimensao::escrowNormalizado($l->escrow),
                ConciliacaoDimensao::solucaoNormalizada($l->solucao),
            ])
            ->all();

        $this->newLine();
        $this->comment('Amostra PagSeguro (id_cliente, meio, escrow, solução):');
        $this->table(['ID cliente', 'Meio', 'Escrow', 'Solução'], $amostrasPag);

        $this->comment('Amostra EDI (id_cliente, meio, escrow, solução):');
        $this->table(['ID cliente', 'Meio', 'Escrow', 'Solução', 'Chave'], $amostrasEdi);

        return self::SUCCESS;
    }
}
