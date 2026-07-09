<?php

namespace App\Services;

use App\Models\EdiMovimento;
use App\Models\Estabelecimento;
use App\Models\EstabelecimentoRoyalty;
use App\Models\PlanoTaxa;
use App\Models\TransacaoRoyalty;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RoyaltyCalculadorService
{
    public function validarRepasse(float $percentualConfigurado, float $percentualRecebido): void
    {
        if ($percentualConfigurado > $percentualRecebido) {
            throw ValidationException::withMessages([
                'percentual' => 'Percentual configurado não pode ser maior do que o percentual recebido.',
            ]);
        }
    }

    public function percentualRecebidoUsuario(PlanoTaxa $taxa, Usuario $usuario): float
    {
        if ($usuario->tipo === 'admin') {
            return (float) $taxa->taxa_percentual;
        }

        $usuario->loadMissing('hierarquia.pai.usuario');
        $pai = $usuario->hierarquia?->pai?->usuario;

        if (! $pai) {
            return (float) $taxa->taxa_percentual;
        }

        $percentualPai = $taxa->royalties()->where('usuario_id', $pai->id)->value('percentual');

        return $percentualPai !== null ? (float) $percentualPai : (float) $taxa->taxa_percentual;
    }

    public function fixarCadeia(Estabelecimento $estabelecimento): void
    {
        $cadeia = $this->cadeiaDoEstabelecimento($estabelecimento);

        foreach ($estabelecimento->plano?->taxas ?? [] as $taxa) {
            $recebidoAnterior = (float) $taxa->taxa_percentual;
            $ordem = 1;

            foreach ($cadeia as $usuario) {
                $repassa = (float) ($taxa->royalties->firstWhere('usuario_id', $usuario->id)?->percentual ?? 0);
                $royalty = max($recebidoAnterior - $repassa, 0);

                EstabelecimentoRoyalty::updateOrCreate(
                    [
                        'estabelecimento_id' => $estabelecimento->id,
                        'plano_taxa_id' => $taxa->id,
                        'usuario_id' => $usuario->id,
                    ],
                    [
                        'nivel' => $usuario->tipo,
                        'percentual_recebe' => $recebidoAnterior,
                        'percentual_repassa' => $repassa,
                        'percentual_royalty' => $royalty,
                        'ordem' => $ordem++,
                    ]
                );

                $recebidoAnterior = $repassa;
            }
        }
    }

    public function calcularPendentes(int $lote = 500, ?string $data = null): int
    {
        $query = EdiMovimento::withoutGlobalScopes()
            ->where('processado', false)
            ->whereNotNull('estabelecimento_id');

        if ($data) {
            $query->whereDate('data_inicial_transacao', $data);
        }

        $movimentos = $query->limit($lote)->get();

        if ($movimentos->isEmpty()) {
            return 0;
        }

        $estabelecimentos = Estabelecimento::withoutGlobalScopes()
            ->whereIn('id', $movimentos->pluck('estabelecimento_id')->unique())
            ->get(['id', 'plano_id'])
            ->keyBy('id');

        $royaltiesPorEstabTaxa = EstabelecimentoRoyalty::query()
            ->whereIn('estabelecimento_id', $estabelecimentos->keys())
            ->orderBy('ordem')
            ->get()
            ->groupBy(fn (EstabelecimentoRoyalty $royalty) => $royalty->estabelecimento_id.'_'.$royalty->plano_taxa_id);

        $taxasPorPlano = PlanoTaxa::query()
            ->whereIn('plano_id', $estabelecimentos->pluck('plano_id')->filter()->unique())
            ->where('ativo', true)
            ->get()
            ->groupBy('plano_id');

        $usuarioIds = $royaltiesPorEstabTaxa
            ->flatten()
            ->pluck('usuario_id')
            ->unique();

        $usuarios = Usuario::query()
            ->whereIn('id', $usuarioIds)
            ->get(['id', 'tipo', 'percentual_retencao_pai'])
            ->keyBy('id');

        $processadosIds = [];

        foreach ($movimentos as $movimento) {
            $planoTaxa = $this->resolverPlanoTaxa($movimento, $estabelecimentos, $taxasPorPlano);

            if (! $planoTaxa) {
                $processadosIds[] = $movimento->id;

                continue;
            }

            $royalties = $royaltiesPorEstabTaxa->get($movimento->estabelecimento_id.'_'.$planoTaxa->id, collect());

            $lancamentos = $this->distribuirComissoes(
                (float) $movimento->valor_total_transacao,
                $royalties,
                $usuarios,
            );

            foreach ($lancamentos as $usuarioId => $dados) {
                TransacaoRoyalty::updateOrCreate(
                    [
                        'edi_movimento_id' => $movimento->id,
                        'usuario_id' => $usuarioId,
                    ],
                    [
                        'nivel' => $dados['nivel'],
                        'percentual_royalty' => $dados['percentual_efetivo'],
                        'valor_royalty' => $dados['valor'],
                    ]
                );
            }

            $processadosIds[] = $movimento->id;
        }

        if ($processadosIds !== []) {
            EdiMovimento::withoutGlobalScopes()
                ->whereIn('id', $processadosIds)
                ->update(['processado' => true]);
        }

        return $movimentos->count();
    }

    /**
     * Distribui comissões brutas aplicando retenção do pai sobre a comissão do filho.
     *
     * Ex.: comissão bruta revenda R$ 100, retenção marketplace 20% → revenda R$ 80, marketplace +R$ 20.
     *
     * @return array<int, array{nivel: string, valor: float, percentual_efetivo: float}>
     */
    public function distribuirComissoes(float $valorTransacao, Collection $royalties, ?Collection $usuarios = null): array
    {
        if ($valorTransacao <= 0 || $royalties->isEmpty()) {
            return [];
        }

        $usuarios ??= Usuario::query()
            ->whereIn('id', $royalties->pluck('usuario_id'))
            ->get()
            ->keyBy('id');

        /** @var array<int, array{nivel: string, valor: float, percentual_bruto: float}> $lancamentos */
        $lancamentos = [];

        foreach ($royalties as $royalty) {
            $usuario = $usuarios->get($royalty->usuario_id);

            if (! $usuario) {
                continue;
            }

            $bruto = round($valorTransacao * ((float) $royalty->percentual_royalty) / 100, 2);

            if ($bruto <= 0) {
                continue;
            }

            $retencao = $this->calcularRetencaoPai($usuario, $bruto);

            $this->acumularLancamento(
                $lancamentos,
                (int) $usuario->id,
                $usuario->tipo,
                $bruto - $retencao['valor'],
                (float) $royalty->percentual_royalty
            );

            if ($retencao['pai'] && $retencao['valor'] > 0) {
                $this->acumularLancamento(
                    $lancamentos,
                    (int) $retencao['pai']->id,
                    $retencao['pai']->tipo,
                    $retencao['valor'],
                    0
                );
            }
        }

        return collect($lancamentos)
            ->map(function (array $dados) use ($valorTransacao) {
                $dados['percentual_efetivo'] = $valorTransacao > 0
                    ? round(($dados['valor'] / $valorTransacao) * 100, 4)
                    : 0;

                return $dados;
            })
            ->all();
    }

    /**
     * @return array{pai: ?Usuario, valor: float}
     */
    public function calcularRetencaoPai(Usuario $usuario, float $comissaoBruta): array
    {
        $percentual = (float) ($usuario->percentual_retencao_pai ?? 0);

        if ($percentual <= 0 || $comissaoBruta <= 0) {
            return ['pai' => null, 'valor' => 0.0];
        }

        $pai = $usuario->paiHierarquico();

        if (! $pai) {
            return ['pai' => null, 'valor' => 0.0];
        }

        return [
            'pai' => $pai,
            'valor' => round($comissaoBruta * $percentual / 100, 2),
        ];
    }

    /**
     * @param  array<int, array{nivel: string, valor: float, percentual_bruto: float}>  $lancamentos
     */
    private function acumularLancamento(array &$lancamentos, int $usuarioId, string $nivel, float $valor, float $percentualBruto): void
    {
        if ($valor <= 0) {
            return;
        }

        if (! isset($lancamentos[$usuarioId])) {
            $lancamentos[$usuarioId] = [
                'nivel' => $nivel,
                'valor' => 0,
                'percentual_bruto' => $percentualBruto,
            ];
        }

        $lancamentos[$usuarioId]['valor'] = round($lancamentos[$usuarioId]['valor'] + $valor, 2);
    }

    private function cadeiaDoEstabelecimento(Estabelecimento $estabelecimento): Collection
    {
        $ids = array_filter([
            $estabelecimento->master_id,
            $estabelecimento->marketplace_id,
            $estabelecimento->revenda_id,
        ]);

        return Usuario::whereIn('id', $ids)
            ->orderByRaw("FIELD(tipo, 'master', 'marketplace', 'revenda')")
            ->get();
    }

    public function planoTaxaDoMovimento(EdiMovimento $movimento): ?PlanoTaxa
    {
        $estabelecimento = Estabelecimento::withoutGlobalScopes()->find($movimento->estabelecimento_id);

        if (! $estabelecimento?->plano_id) {
            return null;
        }

        return $this->resolverPlanoTaxa(
            $movimento,
            collect([$estabelecimento->id => $estabelecimento])->keyBy('id'),
            PlanoTaxa::query()
                ->with('royalties')
                ->where('plano_id', $estabelecimento->plano_id)
                ->where('ativo', true)
                ->get()
                ->groupBy('plano_id'),
        );
    }

    /**
     * @param  Collection<int, Estabelecimento>  $estabelecimentos
     * @param  Collection<int|string, Collection<int, PlanoTaxa>>  $taxasPorPlano
     */
    private function resolverPlanoTaxa(
        EdiMovimento $movimento,
        Collection $estabelecimentos,
        Collection $taxasPorPlano,
    ): ?PlanoTaxa {
        $estabelecimento = $estabelecimentos->get($movimento->estabelecimento_id);

        if (! $estabelecimento?->plano_id) {
            return null;
        }

        $taxas = $taxasPorPlano->get($estabelecimento->plano_id, collect());
        $parcelas = (int) ($movimento->quantidade_parcela ?: 1);

        $taxa = $taxas->first(fn (PlanoTaxa $item) => $item->arranjo_ur === $movimento->arranjo_ur
            && (int) $item->parcelas === $parcelas);

        if ($taxa) {
            return $taxa;
        }

        return $taxas->first(fn (PlanoTaxa $item) => $item->instituicao === $movimento->instituicao_financeira
            && $item->tipo_transacao === $movimento->tipo_transacao
            && (int) $item->parcelas === $parcelas);
    }
}
