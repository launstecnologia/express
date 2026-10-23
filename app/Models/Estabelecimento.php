<?php

namespace App\Models;

use App\Scopes\ExcluirInativoSistemaScope;
use App\Scopes\HierarquiaScope;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Estabelecimento extends Model
{
    protected $fillable = [
        'pessoa_tipo',
        'cnpj',
        'razao_social',
        'inscricao_estadual',
        'data_abertura',
        'cpf',
        'nome_completo',
        'data_nascimento',
        'nome_fantasia',
        'segmento',
        'faturamento_mensal',
        'rep_nome',
        'rep_cpf',
        'rep_data_nascimento',
        'cep',
        'endereco',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'uf',
        'telefone',
        'celular',
        'email',
        'token_pagseguro',
        'pagbank_account_id',
        'pagbank_access_token',
        'pagbank_refresh_token',
        'pagbank_token_expira',
        'pagbank_cadastrado_em',
        'pagbank_edi_ativo',
        'pagbank_status_manual',
        'ip_cadastro',
        'fv_job_id',
        'fv_status',
        'fv_senha_6',
        'fv_erro',
        'fv_iniciado_em',
        'fv_concluido_em',
        'fv_proposta_status',
        'fv_proposta_erro',
        'fv_proposta_concluido_em',
        'webmail_email',
        'webmail_senha',
        'plano_id',
        'subdominio',
        'documento_token_publico',
        'cadastrado_por_id',
        'cadastrado_por_nivel',
        'master_id',
        'marketplace_id',
        'revenda_id',
        'status',
        'risco',
        'anotacoes_interno',
        'anotacoes',
        'ativo',
        'legacy_import_id',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'pagbank_edi_ativo' => 'boolean',
            'pagbank_access_token' => 'encrypted',
            'pagbank_refresh_token' => 'encrypted',
            'pagbank_token_expira' => 'datetime',
            'pagbank_cadastrado_em' => 'datetime',
            'fv_iniciado_em' => 'datetime',
            'fv_concluido_em' => 'datetime',
            'fv_proposta_concluido_em' => 'datetime',
            // webmail_senha usa accessor próprio para tratamento seguro de descriptografia
            'data_abertura' => 'date',
            'data_nascimento' => 'date',
            'rep_data_nascimento' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new HierarquiaScope);
        static::addGlobalScope(new ExcluirInativoSistemaScope);
    }

    /**
     * Accessor seguro para webmail_senha.
     * Retorna null se o valor estiver vazio ou corrompido (e.g. salvo antes de encrypted cast).
     */
    public function getWebmailSenhaAttribute(): ?string
    {
        $raw = $this->attributes['webmail_senha'] ?? null;
        if (blank($raw)) {
            return null;
        }
        try {
            return Crypt::decryptString($raw);
        } catch (DecryptException) {
            return null;
        }
    }

    public function setWebmailSenhaAttribute(?string $value): void
    {
        $this->attributes['webmail_senha'] = filled($value) ? Crypt::encryptString($value) : null;
    }

    public function plano()
    {
        return $this->belongsTo(Plano::class);
    }

    public function cadastradoPor()
    {
        return $this->belongsTo(Usuario::class, 'cadastrado_por_id');
    }

    public function master()
    {
        return $this->belongsTo(Usuario::class, 'master_id');
    }

    public function marketplace()
    {
        return $this->belongsTo(Usuario::class, 'marketplace_id');
    }

    public function revenda()
    {
        return $this->belongsTo(Usuario::class, 'revenda_id');
    }

    public function royalties()
    {
        return $this->hasMany(EstabelecimentoRoyalty::class);
    }

    public function movimentos()
    {
        return $this->hasMany(EdiMovimento::class);
    }

    public function emails()
    {
        return $this->hasMany(EstabelecimentoEmail::class);
    }

    public function documentos()
    {
        return $this->hasMany(EstabelecimentoDocumento::class);
    }

    public function aceite()
    {
        return $this->hasOne(EstabelecimentoAceite::class);
    }

    public function kycAnalise()
    {
        return $this->hasOne(KycAnalise::class);
    }

    public function pagbankLogs()
    {
        return $this->hasMany(PagbankLog::class);
    }
}
