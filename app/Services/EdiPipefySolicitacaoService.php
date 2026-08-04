<?php

namespace App\Services;

use App\Jobs\AbrirChamadoEdiPipefyJob;
use App\Models\EdiPipefySolicitacao;
use App\Models\EdiPipefySolicitacaoItem;
use App\Models\Estabelecimento;
use App\Support\PlatformSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class EdiPipefySolicitacaoService
{
    /**
     * Estabelecimentos com Safepay ID desde a data de corte, ainda não enviados
     * com sucesso em um chamado Pipefy anterior.
     *
     * @return Collection<int, Estabelecimento>
     */
    public function estabelecimentosPendentes(): Collection
    {
        $desde = (string) config('pagbank.pipefy_edi.desde', '2026-06-01');

        $jaEnviados = EdiPipefySolicitacaoItem::query()
            ->whereHas('solicitacao', fn ($q) => $q->where('status', 'concluido'))
            ->pluck('token_pagseguro')
            ->unique()
            ->filter()
            ->all();

        return Estabelecimento::withoutGlobalScopes()
            ->whereNotNull('token_pagseguro')
            ->where('token_pagseguro', '!=', '')
            ->whereDate('created_at', '>=', $desde)
            ->when($jaEnviados !== [], fn ($q) => $q->whereNotIn('token_pagseguro', $jaEnviados))
            ->orderBy('created_at')
            ->get(['id', 'token_pagseguro', 'nome_fantasia', 'razao_social', 'nome_completo', 'created_at']);
    }

    public function previewPendentes(): array
    {
        $lista = $this->estabelecimentosPendentes();

        return [
            'desde' => config('pagbank.pipefy_edi.desde'),
            'total' => $lista->count(),
            'ids' => $lista->pluck('token_pagseguro')->values()->all(),
            'estabelecimentos' => $lista,
        ];
    }

    public function garantirEmailDevolutiva(DirectAdminService $da): array
    {
        $email = (string) config('pagbank.pipefy_edi.email', 'edi@express.app.br');
        $user = Str::before($email, '@');
        $dominio = (string) config('directadmin.dominio', 'express.app.br');

        if ($da->emailExistePlataforma($user)) {
            return [
                'criado' => false,
                'email' => "{$user}@{$dominio}",
                'senha' => null,
                'mensagem' => 'Caixa já existia no DirectAdmin.',
            ];
        }

        $senha = Str::password(16, true, true, false);
        if (! $da->criarEmailPlataforma($user, $senha, 500)) {
            throw new RuntimeException("Falha ao criar {$user}@{$dominio} no DirectAdmin.");
        }

        return [
            'criado' => true,
            'email' => "{$user}@{$dominio}",
            'senha' => $senha,
            'mensagem' => 'Caixa criada com sucesso. Guarde a senha — ela não será exibida novamente.',
        ];
    }

    public function iniciar(?int $solicitadoPorId = null, bool $forcarTodos = false): EdiPipefySolicitacao
    {
        if (! PlatformSettings::ediConfigurado()) {
            throw new RuntimeException('Credenciais EDI (USER/TOKEN) não configuradas em Admin → PagBank.');
        }

        if (! PlatformSettings::automacaoConfigurado()) {
            throw new RuntimeException('API de automação não configurada (AUTOMACAO_API_URL / AUTOMACAO_API_KEY).');
        }

        $emAndamento = EdiPipefySolicitacao::query()
            ->whereIn('status', ['pendente', 'em_andamento'])
            ->exists();

        if ($emAndamento) {
            throw new RuntimeException('Já existe um chamado Pipefy EDI em andamento.');
        }

        $estabelecimentos = $forcarTodos
            ? $this->todosNoPeriodo()
            : $this->estabelecimentosPendentes();

        if ($estabelecimentos->isEmpty()) {
            throw new RuntimeException('Nenhum Safepay ID pendente para enviar no período configurado.');
        }

        $idOrigem = (string) PlatformSettings::ediUser();
        $email = (string) config('pagbank.pipefy_edi.email', 'edi@express.app.br');
        $descricao = $this->montarDescricao($estabelecimentos->pluck('token_pagseguro')->all(), $idOrigem, $email);

        $solicitacao = DB::transaction(function () use ($estabelecimentos, $idOrigem, $email, $descricao, $solicitadoPorId) {
            $solicitacao = EdiPipefySolicitacao::query()->create([
                'status' => 'pendente',
                'tipo' => 'replicacao_token',
                'email_devolutiva' => $email,
                'id_origem' => $idOrigem,
                'total_ids' => $estabelecimentos->count(),
                'descricao' => $descricao,
                'solicitado_por_id' => $solicitadoPorId,
                'disparado_em' => now(),
            ]);

            foreach ($estabelecimentos as $estab) {
                EdiPipefySolicitacaoItem::query()->create([
                    'solicitacao_id' => $solicitacao->id,
                    'estabelecimento_id' => $estab->id,
                    'token_pagseguro' => trim((string) $estab->token_pagseguro),
                ]);
            }

            return $solicitacao;
        });

        AbrirChamadoEdiPipefyJob::dispatch($solicitacao->id)->onQueue('automacao');

        Log::info('EdiPipefySolicitacao: enfileirada', [
            'solicitacao_id' => $solicitacao->id,
            'total_ids' => $solicitacao->total_ids,
        ]);

        return $solicitacao->fresh('itens');
    }

    /**
     * @return Collection<int, Estabelecimento>
     */
    public function todosNoPeriodo(): Collection
    {
        $desde = (string) config('pagbank.pipefy_edi.desde', '2026-06-01');

        return Estabelecimento::withoutGlobalScopes()
            ->whereNotNull('token_pagseguro')
            ->where('token_pagseguro', '!=', '')
            ->whereDate('created_at', '>=', $desde)
            ->orderBy('created_at')
            ->get(['id', 'token_pagseguro', 'nome_fantasia', 'razao_social', 'nome_completo', 'created_at']);
    }

    /**
     * @param  list<string>  $ids
     */
    public function montarDescricao(array $ids, string $idOrigem, string $email): string
    {
        $cfg = config('pagbank.pipefy_edi');
        $cnpj = preg_replace('/\D/', '', (string) ($cfg['cnpj'] ?? '')) ?: '';
        $cnpjFmt = strlen($cnpj) === 14
            ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj)
            : $cnpj;

        $lista = collect($ids)
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->implode("\n");

        return implode("\n", array_filter([
            'Solicitação de replicação/ativação do fluxo EDI via API (modelo 1xN).',
            '',
            'Contratante: '.($cfg['razao_social'] ?? 'Expresspay Pagamentos Ltda'),
            $cnpjFmt ? 'CNPJ: '.$cnpjFmt : null,
            filled($cfg['representante'] ?? null) ? 'Representante: '.$cfg['representante'] : null,
            '',
            'ID Origem (USER): '.$idOrigem,
            'E-mail para devolutiva: '.$email,
            '',
            'Quantidade de IDs: '.count($ids),
            'IDs PagSeguro (Safepay) para ativação:',
            $lista,
        ]));
    }

    public function dispararAutomacao(EdiPipefySolicitacao $solicitacao): string
    {
        $apiUrl = PlatformSettings::automacaoApiUrl();
        $apiKey = PlatformSettings::automacaoApiKey();

        $payload = [
            'solicitacao_id' => $solicitacao->id,
            'page_url' => config('pagbank.pipefy_edi.page_url'),
            'email' => $solicitacao->email_devolutiva,
            'tipo_solicitacao' => config('pagbank.pipefy_edi.tipo_solicitacao'),
            'token' => PlatformSettings::ediToken(),
            'id_origem' => $solicitacao->id_origem,
            'descricao' => $solicitacao->descricao,
            'razao_social' => config('pagbank.pipefy_edi.razao_social'),
            'cnpj' => config('pagbank.pipefy_edi.cnpj'),
            'telefone' => config('pagbank.pipefy_edi.telefone') ?: null,
            'headless' => (bool) config('automacao.headless', true),
        ];

        $response = Http::timeout(30)
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->post("{$apiUrl}/pipefy-edi", $payload);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao iniciar automação Pipefy EDI: '.$response->status().' — '.$response->body()
            );
        }

        $jobId = (string) $response->json('job_id');
        if ($jobId === '') {
            throw new RuntimeException('API de automação não retornou job_id.');
        }

        return $jobId;
    }

    public function consultarStatusAutomacao(string $jobId): array
    {
        $apiUrl = PlatformSettings::automacaoApiUrl();
        $apiKey = PlatformSettings::automacaoApiKey();

        $response = Http::timeout(15)
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->get("{$apiUrl}/status/{$jobId}");

        if (! $response->successful()) {
            throw new RuntimeException(
                'Falha ao consultar status Pipefy EDI: '.$response->status().' — '.$response->body()
            );
        }

        return $response->json() ?? [];
    }
}
