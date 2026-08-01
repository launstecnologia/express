<?php

namespace App\Services;

use App\Models\SubUsuario;
use App\Models\Usuario;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * Orquestra o carregamento do dashboard com cache unificado e aquecimento
 * em background (dashboard:warm-cache).
 */
class DashboardService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private DashboardResumoService $resumoService,
        private DashboardApuracaoService $apuracaoService,
    ) {}

    /**
     * Cards superiores — resposta rápida (estabelecimentos + faturamento EDI do mês).
     *
     * @return array{totalEstabelecimentos: int, faturamentoMes: float}
     */
    public function resumoRapido(?Authenticatable $usuario): array
    {
        return Cache::remember(
            $this->cacheKey('rapido', null, $usuario),
            self::CACHE_TTL_SECONDS,
            fn () => $this->resumoService->calcularRapido($usuario),
        );
    }

    /**
     * Comissão do mês — consulta pesada, carregada após o shell da página.
     *
     * @return array{royaltiesMes: float}
     */
    public function comissaoMes(?Authenticatable $usuario): array
    {
        return Cache::remember(
            $this->cacheKey('comissao', null, $usuario),
            self::CACHE_TTL_SECONDS,
            fn () => ['royaltiesMes' => $this->resumoService->calcularComissaoMes($usuario)],
        );
    }

    /**
     * Apuração por plano + gráficos — consulta pesada, carregada após o shell.
     *
     * @return array{
     *     periodo: int,
     *     planosResumo: list<array<string, mixed>>,
     *     resumoPlanos: array<string, float>,
     *     transacoesStatus: array<string, mixed>,
     *     faturamentoBandeiras: list<array<string, mixed>>
     * }
     */
    public function apuracao(int $periodo, ?Authenticatable $usuario): array
    {
        $periodo = $this->apuracaoService->periodoValido($periodo);

        $dados = Cache::remember(
            $this->cacheKey('apuracao', $periodo, $usuario),
            self::CACHE_TTL_SECONDS,
            fn () => $this->apuracaoService->calcular($periodo, $usuario),
        );

        return [
            'periodo' => $periodo,
            'planosResumo' => $dados['planos'],
            'resumoPlanos' => $dados['resumo'],
            'transacoesStatus' => $dados['transacoes_status'],
            'faturamentoBandeiras' => $dados['faturamento_bandeiras'],
        ];
    }

    /** Aquece todos os fragmentos do dashboard para um usuário admin. */
    public function aquecerCacheAdmin(Usuario $admin): void
    {
        $this->resumoRapido($admin);
        $this->comissaoMes($admin);

        foreach ([7, 30, 90] as $dias) {
            $this->apuracao($dias, $admin);
        }
    }

    private function cacheKey(string $fragmento, ?int $periodo, ?Authenticatable $usuario): string
    {
        if ($usuario instanceof SubUsuario) {
            $usuario = $usuario->dono;
        }

        $tipo = $usuario instanceof Usuario ? $usuario->tipo : 'guest';
        $id = $usuario?->id ?? 0;
        $mes = now()->format('Y-m');
        $periodoSuffix = $periodo !== null ? ".p{$periodo}" : '';

        return "dashboard.{$fragmento}.v3.{$tipo}.{$id}.{$mes}{$periodoSuffix}";
    }
}
