<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Services\AceiteContratacaoService;
use App\Services\KycDocumentoSyncService;
use App\Services\LiberacaoEstabelecimentoService;
use App\Services\LogService;
use App\Support\AceiteContratacao;
use App\Support\DocumentoBrasil;
use App\Support\MarketplaceCadastro;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EnvioDocumentoController extends Controller
{
    public function create(string $token)
    {
        $estabelecimento = $this->estabelecimento($token);
        $relacoes = ['plano.taxas', 'documentos', 'marketplace', 'revenda'];
        if (Schema::hasTable('estabelecimento_aceites')) {
            $relacoes[] = 'aceite';
        }
        $estabelecimento->load($relacoes);

        $resumo = AceiteContratacao::resumo($estabelecimento);
        $documentosExigidos = AceiteContratacao::documentosExigidos($estabelecimento->pessoa_tipo);
        $documentosEnviados = $estabelecimento->documentos
            ->sortByDesc('id')
            ->unique('tipo_documento')
            ->keyBy('tipo_documento');

        return view('publico.envio-documento', compact(
            'estabelecimento',
            'resumo',
            'documentosExigidos',
            'documentosEnviados',
        ));
    }

    public function store(Request $request, string $token, LogService $log, KycDocumentoSyncService $kycSync)
    {
        $estabelecimento = $this->estabelecimento($token);
        $tipos = array_column(AceiteContratacao::documentosExigidos($estabelecimento->pessoa_tipo), 'tipo');

        $dados = $request->validate([
            'tipo_documento' => ['required', 'string', Rule::in($tipos)],
            'documento' => ['required', 'file', 'max:25600', function (string $attribute, mixed $value, \Closure $fail) {
                $this->validarArquivo($value, $fail);
            }],
        ], [
            'tipo_documento.required' => 'Selecione o tipo de documento.',
            'tipo_documento.in' => 'Este documento não faz parte da lista deste estabelecimento.',
            'documento.required' => 'Selecione uma foto ou um PDF.',
            'documento.max' => 'O arquivo pode ter no máximo 25 MB.',
        ]);

        $arquivo = $request->file('documento');
        $path = $arquivo->store("estabelecimentos/{$estabelecimento->id}/documentos", 'public');
        $anteriores = $estabelecimento->documentos()
            ->where('tipo_documento', $dados['tipo_documento'])
            ->orderByDesc('id')
            ->get();
        $principal = $anteriores->first();

        if ($principal) {
            $pathAntigo = $principal->arquivo_path;
            $principal->update([
                'arquivo_path' => $path,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
            ]);

            if ($pathAntigo && $pathAntigo !== $path) {
                Storage::disk('public')->delete($pathAntigo);
            }

            $documento = $principal->fresh();

            foreach ($anteriores->skip(1) as $extra) {
                $kycSync->removerPorEstabelecimentoDocumento($extra);
                Storage::disk('public')->delete($extra->arquivo_path);
                $extra->delete();
            }
        } else {
            $documento = $estabelecimento->documentos()->create([
                'tipo_documento' => $dados['tipo_documento'],
                'arquivo_path' => $path,
                'arquivo_nome' => $arquivo->getClientOriginalName(),
            ]);
        }

        $log->registrar('Estabelecimento', $estabelecimento->id, 'documento_inserido', 'Documento enviado pelo link público', null, [
            'documento_id' => $documento->id,
            'tipo_documento' => $documento->tipo_documento,
        ]);

        $documento->setRelation('estabelecimento', $estabelecimento);
        $kycSync->sincronizar($documento);

        return redirect()
            ->to(route('documentos.public.create', $token).'#documentos')
            ->with('status', 'Documento enviado.');
    }

    public function aceite(Request $request, string $token, AceiteContratacaoService $service)
    {
        $estabelecimento = $this->estabelecimento($token);
        $request->merge($this->evidenciasSeguras($request));

        $validator = Validator::make($request->all(), [
            'nome_responsavel' => ['required', 'string', 'min:3', 'max:150'],
            'cpf_responsavel' => ['required', 'string', 'max:14', function (string $attribute, mixed $value, \Closure $fail) {
                if (! DocumentoBrasil::cpfValido((string) $value)) {
                    $fail('Informe um CPF válido de quem está assinando.');
                }
            }],
            'condicao_representacao' => ['required', Rule::in(array_keys(AceiteContratacao::CONDICOES))],
            'concordo' => ['accepted'],
            'assinatura' => ['required', 'string', 'max:2000000'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'precisao_metros' => ['nullable', 'numeric', 'between:0,100000'],
            'altitude' => ['nullable', 'numeric', 'between:-500,10000'],
            'data_hora_cliente' => ['required', 'string', 'max:40', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+\-]\d{2}:\d{2})$/'],
            'fuso_horario' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_+\-\/]+$/'],
            'idioma' => ['nullable', 'string', 'max:20'],
            'tela' => ['nullable', 'string', 'max:30', 'regex:/^\d{2,5}x\d{2,5}$/'],
            'pixel_ratio' => ['nullable', 'numeric', 'between:0,10'],
            'conexao' => ['nullable', 'string', 'max:20'],
            'toque' => ['nullable', 'boolean'],
            'plataforma' => ['nullable', 'string', 'max:120'],
        ], [
            'nome_responsavel.required' => 'Informe o nome de quem está assinando.',
            'nome_responsavel.min' => 'Informe o nome completo de quem está assinando.',
            'condicao_representacao.required' => 'Informe se é titular ou representante autorizado.',
            'concordo.accepted' => 'Marque que leu e concorda com as taxas e as condições, inclusive chargeback.',
            'assinatura.required' => 'Assine no quadro antes de confirmar.',
            'assinatura.max' => 'A assinatura ficou grande demais. Limpe o quadro e assine de novo.',
            'latitude.required' => 'Autorize a localização do aparelho para registrar o aceite.',
            'longitude.required' => 'Autorize a localização do aparelho para registrar o aceite.',
            'data_hora_cliente.required' => 'Não foi possível ler a data e a hora deste aparelho.',
            'data_hora_cliente.regex' => 'A data e a hora deste aparelho vieram em um formato inválido.',
            'fuso_horario.required' => 'Não foi possível ler o fuso horário deste aparelho.',
        ]);

        if ($validator->fails()) {
            return $this->voltarAssinatura($token, $request, $validator);
        }

        $dados = $validator->validated();
        $assinatura = AceiteContratacao::validarAssinatura($dados['assinatura']);

        if (isset($assinatura['erro'])) {
            return $this->voltarAssinatura($token, $request, ['assinatura' => $assinatura['erro']]);
        }

        $userAgent = mb_substr((string) $request->userAgent(), 0, 1000);

        try {
            $service->registrar($estabelecimento, [
                'nome_responsavel' => $dados['nome_responsavel'],
                'cpf_responsavel' => $dados['cpf_responsavel'],
                'condicao_representacao' => $dados['condicao_representacao'],
                'latitude' => $dados['latitude'],
                'longitude' => $dados['longitude'],
                'precisao_metros' => $dados['precisao_metros'] ?? null,
                'data_hora_cliente' => $dados['data_hora_cliente'],
                'fuso_horario' => $dados['fuso_horario'],
                'idioma' => $dados['idioma'] ?? null,
                'tela' => $dados['tela'] ?? null,
                'ip' => $request->ip(),
                'user_agent' => $userAgent,
                'evidencias' => [
                    'idioma' => $dados['idioma'] ?? null,
                    'tela' => $dados['tela'] ?? null,
                    'pixel_ratio' => isset($dados['pixel_ratio']) ? number_format((float) $dados['pixel_ratio'], 2, '.', '') : null,
                    'conexao' => $dados['conexao'] ?? null,
                    'toque' => $request->boolean('toque'),
                    'plataforma' => $dados['plataforma'] ?? null,
                    'altitude' => $dados['altitude'] ?? null,
                    'ip' => $request->ip(),
                    'ip_remoto' => $request->server('REMOTE_ADDR'),
                    'user_agent' => $userAgent,
                    'sec_ch_ua_mobile' => $request->header('sec-ch-ua-mobile'),
                    'sec_ch_ua_platform' => $request->header('sec-ch-ua-platform'),
                    'accept_language' => mb_substr((string) $request->header('accept-language'), 0, 120),
                ],
            ], $assinatura['bin']);
        } catch (ValidationException $e) {
            return $this->voltarAssinatura($token, $request, $e->errors());
        } catch (\Throwable $e) {
            report($e);

            return $this->voltarAssinatura($token, $request, [
                'assinatura' => 'Não foi possível registrar o aceite. Tente assinar novamente.',
            ]);
        }

        $extra = '';
        $aviso = null;

        if (MarketplaceCadastro::exigeAssinatura($estabelecimento)) {
            [$extra, $aviso] = app(LiberacaoEstabelecimentoService::class)->liberar($estabelecimento->fresh());
        }

        $redirect = redirect()
            ->to(route('documentos.public.create', $token).'#comprovante')
            ->with('status', 'Aceite registrado. O estabelecimento foi aprovado e segue para a PagBank.'.$extra);

        return $aviso ? $redirect->with('aviso', $aviso) : $redirect;
    }

    public function assinatura(string $token)
    {
        $estabelecimento = $this->estabelecimento($token);
        $aceite = Schema::hasTable('estabelecimento_aceites') ? $estabelecimento->aceite : null;

        abort_unless($aceite && Storage::disk('local')->exists($aceite->assinatura_path), 404);

        return Storage::disk('local')->response($aceite->assinatura_path, 'assinatura.png', [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    private function voltarAssinatura(string $token, Request $request, mixed $erros)
    {
        return redirect()
            ->to(route('documentos.public.create', $token).'#assinatura')
            ->withErrors($erros)
            ->withInput($request->except(['assinatura', '_token']));
    }

    /**
     * @return array<string, mixed>
     */
    private function evidenciasSeguras(Request $request): array
    {
        $numero = function (mixed $valor): ?float {
            if ($valor === null || $valor === '' || ! is_numeric($valor)) {
                return null;
            }

            return (float) $valor;
        };

        $altitude = $numero($request->input('altitude'));
        if ($altitude === null || $altitude < -500 || $altitude > 10000) {
            $altitude = null;
        }

        $precisao = $numero($request->input('precisao_metros'));
        if ($precisao === null || $precisao < 0 || $precisao > 100000) {
            $precisao = null;
        }

        $pixel = $numero($request->input('pixel_ratio'));
        if ($pixel !== null) {
            $pixel = min(10, max(0, $pixel));
        }

        $tela = (string) $request->input('tela', '');
        if (! preg_match('/^\d{2,5}x\d{2,5}$/', $tela)) {
            $tela = null;
        }

        $fuso = preg_replace('/[^A-Za-z0-9_+\-\/]/', '', (string) $request->input('fuso_horario', '')) ?? '';
        $fuso = mb_substr($fuso, 0, 80);

        $data = (string) $request->input('data_hora_cliente', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+\-]\d{2}:\d{2})$/', $data)) {
            $data = now()->format('Y-m-d\TH:i:sP');
        }

        $texto = function (mixed $valor, int $limite): ?string {
            $limpo = trim((string) $valor);
            if ($limpo === '') {
                return null;
            }

            return mb_substr($limpo, 0, $limite);
        };

        return [
            'altitude' => $altitude,
            'precisao_metros' => $precisao,
            'pixel_ratio' => $pixel,
            'tela' => $tela,
            'idioma' => $texto($request->input('idioma'), 20),
            'conexao' => $texto($request->input('conexao'), 20),
            'plataforma' => $texto($request->input('plataforma'), 120),
            'fuso_horario' => $fuso !== '' ? $fuso : 'UTC',
            'data_hora_cliente' => $data,
            'toque' => $request->boolean('toque') ? '1' : '0',
        ];
    }

    private function estabelecimento(string $token): Estabelecimento
    {
        return Estabelecimento::withoutGlobalScopes()
            ->where('documento_token_publico', $token)
            ->firstOrFail();
    }

    private function validarArquivo(mixed $value, \Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Selecione uma foto ou um PDF.');

            return;
        }

        $extensao = strtolower($value->getClientOriginalExtension() ?: $value->extension());
        $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

        if (! in_array($extensao, $permitidas, true)) {
            $fail('Envie um PDF ou uma foto (JPG, PNG ou HEIC) de até 25 MB.');

            return;
        }

        $mime = (string) $value->getMimeType();
        $mimeOk = str_starts_with($mime, 'image/') || $mime === 'application/pdf';
        $heicGenerico = $mime === 'application/octet-stream' && in_array($extensao, ['heic', 'heif'], true);

        if (! $mimeOk && ! $heicGenerico) {
            $fail('O arquivo precisa ser um PDF ou uma imagem.');
        }
    }
}
