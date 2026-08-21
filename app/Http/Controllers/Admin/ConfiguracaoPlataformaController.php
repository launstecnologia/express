<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\PlatformSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ConfiguracaoPlataformaController extends Controller
{
    public function edit(Request $request)
    {
        $this->authorizeAdmin($request);

        return view('admin.configuracoes.edit', [
            'config' => PlatformSettings::get(),
            'logoUrl' => PlatformSettings::logoUrl('default'),
            'logoWhiteUrl' => PlatformSettings::logoUrl('white'),
            'faviconUrl' => PlatformSettings::logoUrl('favicon'),
            'ppidConfigurado' => PlatformSettings::ppidConfigurado(),
            'pagbankConfigurado' => PlatformSettings::pagbankConfigurado(),
        ]);
    }

    public function update(Request $request)
    {
        $this->authorizeAdmin($request);

        $dados = $request->validate([
            'app_name' => ['required', 'string', 'max:120'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'string', 'max:500'],
            'meta_robots' => ['required', 'string', 'max:80'],
            'theme_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'logo_white' => ['nullable', 'image', 'max:4096'],
            'favicon' => ['nullable', 'image', 'max:2048'],
            'remover_logo' => ['boolean'],
            'remover_logo_white' => ['boolean'],
            'remover_favicon' => ['boolean'],
            'razao_social' => ['nullable', 'string', 'max:200'],
            'nome_fantasia' => ['nullable', 'string', 'max:200'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'inscricao_estadual' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'celular' => ['nullable', 'string', 'max:20'],
            'site_url' => ['nullable', 'url', 'max:255'],
            'cep' => ['nullable', 'string', 'max:10'],
            'endereco' => ['nullable', 'string', 'max:200'],
            'numero' => ['nullable', 'string', 'max:20'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'max:2'],
            'responsavel_nome' => ['nullable', 'string', 'max:200'],
            'responsavel_cpf' => ['nullable', 'string', 'max:14'],
            'observacoes_relatorio' => ['nullable', 'string', 'max:2000'],
            'mail_mailer' => ['required', 'in:smtp,log,array'],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail_encryption' => ['nullable', 'string', 'max:10'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:500'],
            'mail_from_address' => ['nullable', 'email', 'max:150'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
            'mail_reset_ativo' => ['nullable', 'boolean'],
            'mail_reset_expira_minutos' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'mail_reset_assunto' => ['nullable', 'string', 'max:200'],
            'mail_reset_corpo' => ['nullable', 'string', 'max:5000'],
            'kyc_ativo' => ['boolean'],
            'ppid_api_url' => ['nullable', 'url', 'max:255'],
            'ppid_email' => ['nullable', 'email', 'max:150'],
            'ppid_senha' => ['nullable', 'string', 'max:500'],
            'ppid_limite_mensal' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'brasilapi_url' => ['nullable', 'url', 'max:255'],
            'pagbank_ambiente' => ['required', 'in:sandbox,producao'],
            'pagbank_token' => ['nullable', 'string', 'max:500'],
            'pagbank_client_id' => ['nullable', 'string', 'max:255'],
            'pagbank_client_secret' => ['nullable', 'string', 'max:500'],
            'pagbank_edi_token_sandbox' => ['nullable', 'string', 'max:500'],
            'pagbank_edi_token_producao' => ['nullable', 'string', 'max:500'],
            'pagbank_edi_user_sandbox' => ['nullable', 'string', 'max:100'],
            'pagbank_edi_user_producao' => ['nullable', 'string', 'max:100'],
            'automacao_fv_usuario' => ['nullable', 'string', 'max:120'],
            'automacao_fv_senha' => ['nullable', 'string', 'max:500'],
        ]);

        $config = PlatformSetting::query()->firstOrCreate(
            [],
            [
                'app_name' => config('app.name', 'Express Payments'),
                'meta_robots' => 'noindex, nofollow',
                'theme_color' => '#2563eb',
            ]
        );

        foreach (['logo' => 'logo_path', 'logo_white' => 'logo_white_path', 'favicon' => 'favicon_path'] as $input => $column) {
            $removerKey = match ($input) {
                'logo' => 'remover_logo',
                'logo_white' => 'remover_logo_white',
                default => 'remover_favicon',
            };

            if ($request->boolean($removerKey) && $config->{$column}) {
                Storage::disk('public')->delete($config->{$column});
                $dados[$column] = null;
            }

            if ($request->hasFile($input)) {
                if ($config->{$column}) {
                    Storage::disk('public')->delete($config->{$column});
                }
                $dados[$column] = $request->file($input)->store('platform', 'public');
            }
        }

        unset(
            $dados['logo'],
            $dados['logo_white'],
            $dados['favicon'],
            $dados['remover_logo'],
            $dados['remover_logo_white'],
            $dados['remover_favicon'],
        );

        $dados['mail_reset_ativo'] = $request->has('mail_reset_ativo')
            ? $request->boolean('mail_reset_ativo')
            : ($config->mail_reset_ativo ?? true);
        $dados['kyc_ativo'] = $request->boolean('kyc_ativo');

        if (! $request->filled('mail_reset_expira_minutos')) {
            unset($dados['mail_reset_expira_minutos']);
        }

        if (! $request->filled('mail_reset_assunto')) {
            unset($dados['mail_reset_assunto']);
        }

        if (! $request->filled('mail_reset_corpo')) {
            unset($dados['mail_reset_corpo']);
        }

        if (! $request->filled('ppid_senha')) {
            unset($dados['ppid_senha']);
        }

        if (! $request->filled('pagbank_token')) {
            unset($dados['pagbank_token']);
        }

        if (! $request->filled('pagbank_client_secret')) {
            unset($dados['pagbank_client_secret']);
        }

        if (! $request->filled('pagbank_edi_token_sandbox')) {
            unset($dados['pagbank_edi_token_sandbox']);
        }

        if (! $request->filled('pagbank_edi_token_producao')) {
            unset($dados['pagbank_edi_token_producao']);
        }

        if (! $request->filled('pagbank_edi_user_sandbox')) {
            unset($dados['pagbank_edi_user_sandbox']);
        }

        if (! $request->filled('pagbank_edi_user_producao')) {
            unset($dados['pagbank_edi_user_producao']);
        }

        if (! $request->filled('automacao_fv_senha')) {
            unset($dados['automacao_fv_senha']);
        }

        if (! $request->filled('mail_password')) {
            unset($dados['mail_password']);
        }

        if (($dados['mail_encryption'] ?? '') === '') {
            $dados['mail_encryption'] = null;
        }

        $config->update($dados);
        PlatformSettings::forget();
        \App\Support\PlatformMail::apply();

        return redirect()
            ->route('admin.configuracoes.edit', ['aba' => $request->input('_aba')])
            ->with('status', 'Configurações da plataforma salvas com sucesso.');
    }

    public function testarEmail(Request $request)
    {
        $this->authorizeAdmin($request);

        $dados = $request->validate([
            'destinatario' => ['required', 'email', 'max:150'],
        ]);

        try {
            \App\Support\PlatformMail::apply();

            \Illuminate\Support\Facades\Mail::raw(
                "Este é um e-mail de teste enviado pela plataforma Express Payments.\n\n".
                "Se você recebeu esta mensagem, o servidor SMTP está configurado corretamente.\n\n".
                "Enviado em: " . now()->format('d/m/Y H:i:s'),
                function ($message) use ($dados) {
                    $message->to($dados['destinatario'])
                        ->subject('Teste de E-mail — ' . \App\Support\PlatformSettings::appName());
                }
            );

            return response()->json([
                'ok'       => true,
                'mensagem' => "E-mail de teste enviado para {$dados['destinatario']} com sucesso.",
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok'  => false,
                'erro' => $e->getMessage(),
            ], 422);
        }
    }

    public function buscarCredenciaisPagBank(Request $request)
    {
        $this->authorizeAdmin($request);

        $request->validate([
            'token'    => ['required', 'string'],
            'ambiente' => ['required', 'in:sandbox,producao'],
        ]);

        $baseUrl = $request->input('ambiente') === 'producao'
            ? 'https://api.pagseguro.com'
            : 'https://sandbox.api.pagseguro.com';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $request->input('token'),
                'Content-Type'  => 'application/json',
            ])->post("{$baseUrl}/oauth2/application", [
                'name'         => PlatformSettings::appName(),
                'description'  => 'Aplicação Connect da plataforma',
                'redirect_uris' => [config('app.url') . '/pagbank/callback'],
                'scope'        => ['payments.read', 'payments.create', 'accounts.read', 'accounts.create'],
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return response()->json([
                    'ok'            => true,
                    'client_id'     => $data['client_id'] ?? $data['id'] ?? null,
                    'client_secret' => $data['client_secret'] ?? null,
                ]);
            }

            return response()->json([
                'ok'    => false,
                'erro'  => $response->json('error_messages.0.description')
                    ?? $response->json('error_messages.0')
                    ?? $response->json('message')
                    ?? "Erro HTTP {$response->status()}",
            ], 422);

        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'erro' => $e->getMessage()], 500);
        }
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && $request->user()->tipo === 'admin', 403);
    }
}
