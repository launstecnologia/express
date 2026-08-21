<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'app_name',
        'meta_description',
        'meta_keywords',
        'meta_robots',
        'theme_color',
        'logo_path',
        'logo_white_path',
        'favicon_path',
        'razao_social',
        'nome_fantasia',
        'cnpj',
        'inscricao_estadual',
        'email',
        'telefone',
        'celular',
        'site_url',
        'cep',
        'endereco',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'responsavel_nome',
        'responsavel_cpf',
        'observacoes_relatorio',
        'mail_mailer',
        'mail_host',
        'mail_port',
        'mail_encryption',
        'mail_username',
        'mail_password',
        'mail_from_address',
        'mail_from_name',
        'mail_reset_ativo',
        'mail_reset_expira_minutos',
        'mail_reset_assunto',
        'mail_reset_corpo',
        'kyc_ativo',
        'openai_api_key',
        'openai_modelo',
        'brasilapi_url',
        'ppid_api_url',
        'ppid_email',
        'ppid_senha',
        'ppid_limite_mensal',
        'pagbank_ambiente',
        'pagbank_token',
        'pagbank_client_id',
        'pagbank_client_secret',
        'pagbank_edi_token_sandbox',
        'pagbank_edi_token_producao',
        'pagbank_edi_user_sandbox',
        'pagbank_edi_user_producao',
        'automacao_fv_usuario',
        'automacao_fv_senha',
    ];

    protected function casts(): array
    {
        return [
            'mail_reset_ativo' => 'boolean',
            'mail_password' => 'encrypted',
            'kyc_ativo' => 'boolean',
            'openai_api_key' => 'encrypted',
            'ppid_senha' => 'encrypted',
            'pagbank_token' => 'encrypted',
            'pagbank_client_secret' => 'encrypted',
            'pagbank_edi_token_sandbox' => 'encrypted',
            'pagbank_edi_token_producao' => 'encrypted',
            'automacao_fv_senha' => 'encrypted',
        ];
    }
}
