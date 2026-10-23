<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\EstabelecimentoAceite;
use App\Models\EstabelecimentoDocumento;
use App\Support\AceiteContratacao;
use App\Support\DocumentoBrasil;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AceiteContratacaoService
{
    public function __construct(private LogService $log) {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public function registrar(Estabelecimento $estabelecimento, array $dados, string $assinaturaBin): EstabelecimentoAceite
    {
        $resumo = AceiteContratacao::resumo($estabelecimento);
        $this->garantirPodeAssinar($estabelecimento, $resumo);

        $documentos = $this->snapshotDocumentos($estabelecimento);
        $condicao = AceiteContratacao::CONDICOES[$dados['condicao_representacao']];
        $cpf = DocumentoBrasil::apenasDigitos((string) $dados['cpf_responsavel']);
        $nome = preg_replace('/\s+/', ' ', trim((string) $dados['nome_responsavel'])) ?? '';
        $declaracao = AceiteContratacao::declaracao(
            (string) $resumo['estabelecimento'],
            (string) $resumo['documento'],
            $resumo['plano_nome'],
            $nome,
            DocumentoBrasil::formatarCpf($cpf),
            $condicao,
        );

        $aceiteEm = now();
        $assinaturaSha = hash('sha256', $assinaturaBin);
        $precisao = $dados['precisao_metros'] !== null && $dados['precisao_metros'] !== ''
            ? number_format((float) $dados['precisao_metros'], 2, '.', '')
            : null;

        $payload = [
            'versao' => AceiteContratacao::VERSAO,
            'estabelecimento_id' => (int) $estabelecimento->id,
            'documento' => $resumo['documento_digitos'],
            'plano_id' => $resumo['plano_id'] ? (int) $resumo['plano_id'] : null,
            'resumo' => $resumo,
            'declaracao' => $declaracao,
            'nome_responsavel' => $nome,
            'cpf_responsavel' => $cpf,
            'condicao_representacao' => $dados['condicao_representacao'],
            'documentos' => $documentos,
            'assinatura_sha256' => $assinaturaSha,
            'ip' => $dados['ip'],
            'user_agent' => $dados['user_agent'],
            'data_hora_cliente' => $dados['data_hora_cliente'],
            'data_hora_servidor' => $aceiteEm->toIso8601String(),
            'fuso_horario' => $dados['fuso_horario'],
            'latitude' => number_format((float) $dados['latitude'], 7, '.', ''),
            'longitude' => number_format((float) $dados['longitude'], 7, '.', ''),
            'precisao_metros' => $precisao,
            'idioma' => $dados['idioma'] ?: null,
            'tela' => $dados['tela'] ?: null,
        ];

        $ordenado = AceiteContratacao::ordenar($payload);
        $hash = AceiteContratacao::hashDoCanonico($ordenado);
        $caminho = "estabelecimentos/{$estabelecimento->id}/aceite/assinatura.png";

        try {
            $aceite = DB::transaction(function () use (
                $estabelecimento,
                $dados,
                $assinaturaBin,
                $resumo,
                $nome,
                $cpf,
                $declaracao,
                $assinaturaSha,
                $precisao,
                $ordenado,
                $hash,
                $caminho,
                $aceiteEm,
            ) {
                Estabelecimento::withoutGlobalScopes()
                    ->whereKey($estabelecimento->id)
                    ->lockForUpdate()
                    ->first();

                if (EstabelecimentoAceite::query()->where('estabelecimento_id', $estabelecimento->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages([
                        'aceite' => 'Este estabelecimento já registrou o aceite.',
                    ]);
                }

                if (! Storage::disk('local')->put($caminho, $assinaturaBin)) {
                    throw ValidationException::withMessages([
                        'assinatura' => 'Não foi possível guardar a assinatura. Tente novamente.',
                    ]);
                }

                return EstabelecimentoAceite::create([
                    'estabelecimento_id' => $estabelecimento->id,
                    'plano_id' => $resumo['plano_id'],
                    'plano_nome' => $resumo['plano_nome'],
                    'nome_responsavel' => $nome,
                    'cpf_responsavel' => $cpf,
                    'condicao_representacao' => $dados['condicao_representacao'],
                    'declaracao' => $declaracao,
                    'resumo' => $resumo,
                    'evidencias' => $dados['evidencias'],
                    'assinatura_path' => $caminho,
                    'assinatura_sha256' => $assinaturaSha,
                    'ip' => $dados['ip'],
                    'user_agent' => $dados['user_agent'],
                    'fuso_horario' => $dados['fuso_horario'],
                    'data_hora_cliente' => $dados['data_hora_cliente'],
                    'latitude' => $dados['latitude'],
                    'longitude' => $dados['longitude'],
                    'precisao_metros' => $precisao,
                    'hash_confirmacao' => $hash,
                    'payload_hash' => $ordenado,
                    'aceite_em' => $aceiteEm,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            if (! EstabelecimentoAceite::query()->where('estabelecimento_id', $estabelecimento->id)->exists()) {
                Storage::disk('local')->delete($caminho);
            }

            throw ValidationException::withMessages([
                'aceite' => 'Este estabelecimento já registrou o aceite.',
            ]);
        } catch (ValidationException $e) {
            if (isset($e->errors()['assinatura'])) {
                Storage::disk('local')->delete($caminho);
            }

            throw $e;
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($caminho);

            throw $e;
        }

        $this->log->registrar('Estabelecimento', $estabelecimento->id, 'aceite_registrado', 'Aceite das taxas assinado pelo link público', null, [
            'aceite_id' => $aceite->id,
            'hash_confirmacao' => $aceite->hash_confirmacao,
            'ip' => $aceite->ip,
        ]);

        return $aceite;
    }

    /**
     * @param  array<string, mixed>  $resumo
     */
    private function garantirPodeAssinar(Estabelecimento $estabelecimento, array $resumo): void
    {
        if ($estabelecimento->aceite()->exists()) {
            throw ValidationException::withMessages([
                'aceite' => 'Este estabelecimento já registrou o aceite.',
            ]);
        }

        if (! $estabelecimento->plano_id) {
            throw ValidationException::withMessages([
                'taxas' => 'Este estabelecimento ainda não tem um plano vinculado. A assinatura fica disponível depois que o plano for definido.',
            ]);
        }

        if ($resumo['taxas'] === []) {
            throw ValidationException::withMessages([
                'taxas' => 'O plano deste estabelecimento ainda não tem taxas cadastradas. A assinatura fica disponível depois que as taxas forem lançadas.',
            ]);
        }

        $enviados = $estabelecimento->documentos()
            ->get()
            ->sortByDesc('id')
            ->unique('tipo_documento')
            ->keyBy('tipo_documento');

        $faltando = collect(AceiteContratacao::documentosExigidos($estabelecimento->pessoa_tipo))
            ->reject(fn (array $doc) => $enviados->has($doc['tipo']))
            ->pluck('titulo');

        if ($faltando->isNotEmpty()) {
            throw ValidationException::withMessages([
                'documentos' => 'Falta enviar: '.$faltando->join(', ').'.',
            ]);
        }
    }

    /**
     * @return array<int, array{id: int, tipo: string, arquivo: ?string, sha256: string}>
     */
    private function snapshotDocumentos(Estabelecimento $estabelecimento): array
    {
        $exigidos = collect(AceiteContratacao::documentosExigidos($estabelecimento->pessoa_tipo))
            ->pluck('tipo');

        $documentos = $estabelecimento->documentos()
            ->get()
            ->sortByDesc('id')
            ->unique('tipo_documento')
            ->filter(fn (EstabelecimentoDocumento $doc) => $exigidos->contains($doc->tipo_documento))
            ->sortBy('tipo_documento')
            ->values();

        $snapshot = [];

        foreach ($documentos as $documento) {
            $absoluto = Storage::disk('public')->path($documento->arquivo_path);

            if (! is_file($absoluto)) {
                throw ValidationException::withMessages([
                    'documentos' => 'O arquivo de "'.$documento->tipo_documento.'" não está disponível. Envie novamente.',
                ]);
            }

            $snapshot[] = [
                'arquivo' => $documento->arquivo_nome,
                'id' => (int) $documento->id,
                'sha256' => hash_file('sha256', $absoluto),
                'tipo' => $documento->tipo_documento,
            ];
        }

        return $snapshot;
    }
}
