<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plano;
use App\Models\Segmento;
use App\Models\Usuario;
use App\Rules\EmailUnicoAutenticacao;
use App\Services\HierarquiaService;
use App\Services\MarketplaceBrandingService;
use App\Services\MarketplacePlanoService;
use App\Services\NotificacaoEmailService;
use App\Services\SubUsuarioPrincipalService;
use App\Support\NotificacaoVars;
use App\Support\UsuarioComercial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsuarioController extends Controller
{
    public function index(Request $request)
    {
        $principal = UsuarioComercial::principal();
        abort_unless($principal, 403);

        $tipo = $this->tipoFiltro();

        if (UsuarioComercial::ehMarketplace()) {
            if ($tipo !== 'revenda') {
                return redirect()->route('usuarios.show', $principal);
            }

            $query = UsuarioComercial::revendasDo($principal);
            $tipo = 'revenda';
        } else {
            abort_unless(UsuarioComercial::tipoListaPermitido($tipo), 403);

            if ($principal->tipo === 'master' && $tipo) {
                $query = $this->usuariosVisiveisAoMaster($principal, $tipo);
            } elseif (UsuarioComercial::ehAdmin() && $tipo === null) {
                $query = Usuario::query();
            } else {
                $query = Usuario::query()->where('tipo', $tipo ?: 'admin');
            }
        }

        $query->with(['hierarquia.pai.usuario', 'hierarquia.pai.pai.usuario']);

        $this->aplicarFiltrosIndex($query, $request, $tipo);

        $filtros = $request->only([
            'master_id',
            'marketplace_id',
            'revenda_id',
            'nivel',
            'email',
            'ativo',
            'pessoa_tipo',
            'segmento',
            'data_inicio',
            'data_fim',
        ]);

        return view('admin.usuarios.index', [
            'usuarios' => $query->latest()->paginate(20)->withQueryString(),
            'tipoAtual' => $tipo,
            'listaTodosUsuarios' => UsuarioComercial::ehAdmin() && $tipo === null,
            'filtros' => $filtros,
            ...$this->opcoesFiltros($tipo, $principal),
        ]);
    }

    public function create(HierarquiaService $hierarquia)
    {
        $tipo = $this->tipoFiltro();
        abort_unless(UsuarioComercial::tipoListaPermitido($tipo), 403);

        $principal = UsuarioComercial::principal();
        $paiSelecionado = filled(request('pai_id')) ? Usuario::findOrFail(request()->integer('pai_id')) : null;

        if (UsuarioComercial::ehMarketplace()) {
            abort_unless($tipo === 'revenda', 403);
            $paiSelecionado = $principal;
        } elseif ($paiSelecionado) {
            abort_unless(UsuarioComercial::podeGerenciar($paiSelecionado), 403);
        }
        $niveis = $tipo ? [$tipo] : ['admin'];

        if ($paiSelecionado) {
            $permitidos = $hierarquia->proximosNiveisPermitidos($paiSelecionado);
            $niveis = $tipo && in_array($tipo, $permitidos, true) ? [$tipo] : $permitidos;
        }

        return view('admin.usuarios.form', [
            'usuario' => new Usuario,
            'pais' => $this->paisParaTipo($tipo),
            'niveis' => $niveis,
            'tipoFixo' => $tipo && ! $paiSelecionado ? $tipo : null,
            'paiSelecionado' => $paiSelecionado,
            'segmentos' => Segmento::where('ativo', true)->orderBy('nome')->get(),
            'todosPlanos' => $tipo === 'marketplace' && UsuarioComercial::ehAdmin()
                ? Plano::where('ativo', true)->orderBy('nome')->get()
                : collect(),
        ]);
    }

    public function store(Request $request, HierarquiaService $hierarquia, MarketplacePlanoService $marketplacePlano)
    {
        $dados = $this->validar($request);
        $dados['password'] = '123456';
        $dados['must_change_password'] = true;
        $pai = filled($request->pai_id) ? Usuario::findOrFail($request->integer('pai_id')) : null;

        if (UsuarioComercial::ehMarketplace()) {
            $pai = UsuarioComercial::principal();
            abort_unless($dados['tipo'] === 'revenda' && $pai, 403);
        } elseif ($pai) {
            abort_unless(UsuarioComercial::podeGerenciar($pai), 403);
        }
        $usuario = Usuario::create($dados);
        $hierarquia->criarNo($usuario, $pai);

        if ($usuario->tipo === 'marketplace') {
            app(MarketplaceBrandingService::class)->criarPara($usuario, $request->input('marketplace_slug'));

            if (UsuarioComercial::ehAdmin()) {
                $marketplacePlano->sincronizar($usuario, $request->input('planos_habilitados', []));
            }
        }

        if (in_array($usuario->tipo, ['master', 'marketplace', 'revenda'], true)) {
            app(SubUsuarioPrincipalService::class)->garantirParaDono($usuario, $dados['password']);
        }

        app(NotificacaoEmailService::class)->enfileirar(
            'usuario.criado',
            $usuario->email,
            NotificacaoVars::usuario($usuario),
            route('login')
        );

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuário criado.');
    }

    public function show(Usuario $usuario, HierarquiaService $hierarquia, MarketplaceBrandingService $brandingService)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        $usuario->load([
            'hierarquia.pai.usuario.marketplaceBranding',
            'hierarquia.filhos.usuario',
            'subUsuarios.perfil',
            'estabelecimentos',
            'marketplaceBranding',
            'planosHabilitados',
        ]);

        $whitelabel = null;
        $urlAcessoOperacional = null;

        if ($usuario->tipo === 'marketplace') {
            $branding = $usuario->marketplaceBranding;

            if (auth()->user()?->tipo === 'admin') {
                $branding = $branding ?? $brandingService->criarPara($usuario);
                $whitelabel = [
                    'branding' => $branding,
                    'urlsAcesso' => $brandingService->urlsAcesso($branding),
                    ...$brandingService->urlsPreview($branding),
                ];
            }

            if ($branding) {
                $urlAcessoOperacional = $brandingService->urlsAcesso($branding)['atual'];
            }
        } elseif ($usuario->tipo === 'revenda') {
            $marketplace = $usuario->hierarquia?->pai?->usuario;
            $branding = $marketplace?->marketplaceBranding;

            if ($branding) {
                $urlAcessoOperacional = $brandingService->urlsAcesso($branding)['atual'];
            }
        }

        return view('admin.usuarios.show', [
            'usuario' => $usuario,
            'proximosNiveis' => $hierarquia->proximosNiveisPermitidos($usuario),
            'whitelabel' => $whitelabel,
            'urlAcessoOperacional' => $urlAcessoOperacional,
            'temSubUsuarioPrincipal' => in_array($usuario->tipo, ['master', 'marketplace', 'revenda'], true)
                && app(SubUsuarioPrincipalService::class)->donoTemPrincipal($usuario),
        ]);
    }

    public function edit(Usuario $usuario, HierarquiaService $hierarquia)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        return view('admin.usuarios.form', [
            'usuario' => $usuario->load('planosHabilitados'),
            'pais' => $this->paisParaTipo($usuario->tipo, $usuario->id),
            'niveis' => $hierarquia::ORDEM,
            'tipoFixo' => $usuario->tipo,
            'paiSelecionado' => $usuario->hierarquia?->pai?->usuario,
            'segmentos' => Segmento::where('ativo', true)->orderBy('nome')->get(),
            'todosPlanos' => $usuario->tipo === 'marketplace' && UsuarioComercial::ehAdmin()
                ? Plano::where('ativo', true)->orderBy('nome')->get()
                : collect(),
        ]);
    }

    public function resetarSenha(Usuario $usuario)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        $usuario->update([
            'password' => '123456',
            'must_change_password' => true,
        ]);

        return redirect()->route('usuarios.show', $usuario)
            ->with('status', $usuario->tipo === 'admin'
                ? 'Senha resetada para 123456. O usuário deverá criar uma nova senha no próximo acesso.'
                : 'Senha comercial resetada para 123456. O usuário deverá criar uma nova senha no próximo acesso.');
    }

    public function update(Request $request, Usuario $usuario, HierarquiaService $hierarquia, MarketplacePlanoService $marketplacePlano)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        $dados = $this->validar($request, $usuario);
        $usuario->update($dados);
        $pai = filled($request->pai_id) ? Usuario::findOrFail($request->integer('pai_id')) : null;

        if ($pai) {
            abort_unless(UsuarioComercial::podeGerenciar($pai), 403);
        }

        $hierarquia->criarNo($usuario, $pai);

        if ($usuario->tipo === 'marketplace' && UsuarioComercial::ehAdmin()) {
            $marketplacePlano->sincronizar($usuario, $request->input('planos_habilitados', []));
        }

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuário atualizado.');
    }

    private function validar(Request $request, ?Usuario $usuario = null): array
    {
        $tipoInformado = $request->input('tipo', $usuario?->tipo);
        $exigeRetencao = ($tipoInformado === 'marketplace' && UsuarioComercial::ehAdmin())
            || ($tipoInformado === 'revenda' && (UsuarioComercial::ehMarketplace() || UsuarioComercial::ehAdmin()));

        $dados = $request->validate([
            'tipo' => [
                'required',
                Rule::in(UsuarioComercial::ehMarketplace() ? ['revenda'] : HierarquiaService::ORDEM),
            ],
            'pessoa_tipo' => ['required', 'in:juridica,fisica'],
            'cnpj' => ['nullable', 'string', 'max:18'],
            'cpf' => ['nullable', 'string', 'max:14'],
            'razao_social' => ['nullable', 'string', 'max:200'],
            'inscricao_estadual' => ['nullable', 'string', 'max:30'],
            'data_abertura' => ['nullable', 'date'],
            'nome_completo' => ['nullable', 'string', 'max:200'],
            'data_nascimento' => ['nullable', 'date'],
            'nome_fantasia' => ['nullable', 'string', 'max:200'],
            'segmento' => ['nullable', 'string', 'max:200'],
            'rep_nome' => ['nullable', 'string', 'max:200'],
            'rep_cpf' => ['nullable', 'string', 'max:14'],
            'rep_data_nascimento' => ['nullable', 'date'],
            'cep' => ['nullable', 'string', 'max:9'],
            'endereco' => ['nullable', 'string', 'max:200'],
            'numero' => ['nullable', 'string', 'max:10'],
            'complemento' => ['nullable', 'string', 'max:100'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'telefone' => ['nullable', 'string', 'max:15'],
            'celular' => ['nullable', 'string', 'max:15'],
            'email' => ['required', 'email', 'max:150', new EmailUnicoAutenticacao(ignoreUsuarioId: $usuario?->id)],
            'ativo' => ['boolean'],
            'percentual_retencao_pai' => [
                Rule::requiredIf($exigeRetencao && ! $usuario),
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
            'planos_habilitados' => ['nullable', 'array'],
            'planos_habilitados.*' => ['integer', Rule::exists('planos', 'id')->where('ativo', true)],
        ]);

        if (! UsuarioComercial::podeDefinirRetencaoPai((string) $dados['tipo'])) {
            unset($dados['percentual_retencao_pai']);
        }

        return $dados;
    }

    private function paisParaTipo(?string $tipo, ?int $excluirId = null): \Illuminate\Database\Eloquent\Collection
    {
        $tipoPai = match ($tipo) {
            'marketplace' => 'master',
            'revenda'     => 'marketplace',
            default       => null,
        };

        $query = Usuario::where('ativo', true);

        if ($tipoPai) {
            $query->where('tipo', $tipoPai);
        } else {
            $query->whereIn('tipo', ['admin', 'master', 'marketplace', 'revenda']);
        }

        if ($excluirId) {
            $query->whereKeyNot($excluirId);
        }

        return $query->orderByRaw('COALESCE(nome_completo, nome_fantasia, razao_social, email)')->get();
    }

    private function tipoFiltro(): ?string
    {
        $tipo = request('tipo');

        if (! in_array($tipo, ['master', 'marketplace', 'revenda'], true)) {
            return null;
        }

        return UsuarioComercial::tipoListaPermitido($tipo) ? $tipo : null;
    }

    private function usuariosVisiveisAoMaster(Usuario $master, string $tipo)
    {
        return Usuario::query()
            ->where('tipo', $tipo)
            ->where(function ($query) use ($master) {
                $query->whereKey($master->id)
                    ->orWhereHas('hierarquia.pai.usuario', fn ($q) => $q->whereKey($master->id))
                    ->orWhereHas('hierarquia.pai.pai.usuario', fn ($q) => $q->whereKey($master->id));
            });
    }

    private function aplicarFiltrosIndex(Builder $query, Request $request, ?string $tipo): void
    {
        if ($request->filled('email')) {
            $termo = '%'.strtolower(trim($request->string('email'))).'%';
            $query->whereRaw('LOWER(email) LIKE ?', [$termo]);
        }

        if (! $tipo && UsuarioComercial::ehAdmin() && $request->filled('nivel')) {
            $nivel = $request->string('nivel');

            if (in_array($nivel, ['admin', 'master', 'marketplace', 'revenda'], true)) {
                $query->where('tipo', $nivel);
            }
        }

        $aplicaFiltroHierarquia = in_array($tipo, ['marketplace', 'revenda'], true)
            || (! $tipo && UsuarioComercial::ehAdmin());

        if ($request->filled('master_id') && $aplicaFiltroHierarquia) {
            $masterId = $request->integer('master_id');

            $query->where(function (Builder $q) use ($masterId) {
                $q->whereHas('hierarquia.pai.usuario', fn (Builder $pai) => $pai->whereKey($masterId))
                    ->orWhereHas('hierarquia.pai.pai.usuario', fn (Builder $avo) => $avo->whereKey($masterId));
            });
        }

        if ($request->filled('marketplace_id') && ($tipo === 'revenda' || (! $tipo && UsuarioComercial::ehAdmin()))) {
            $marketplaceId = $request->integer('marketplace_id');

            $query->where(function (Builder $q) use ($marketplaceId) {
                $q->where(fn (Builder $self) => $self
                    ->where('tipo', 'marketplace')
                    ->whereKey($marketplaceId))
                    ->orWhereHas('hierarquia.pai.usuario', fn (Builder $pai) => $pai
                        ->where('tipo', 'marketplace')
                        ->whereKey($marketplaceId));
            });
        }

        if ($request->filled('revenda_id') && ($tipo === 'revenda' || (! $tipo && UsuarioComercial::ehAdmin()))) {
            $query->whereKey($request->integer('revenda_id'));
        }

        if ($request->has('ativo') && $request->input('ativo') !== '') {
            $query->where('ativo', $request->boolean('ativo'));
        }

        if ($request->filled('pessoa_tipo')) {
            $query->where('pessoa_tipo', $request->string('pessoa_tipo'));
        }

        if ($request->filled('segmento')) {
            $query->where('segmento', $request->string('segmento'));
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->date('data_inicio'));
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->date('data_fim'));
        }
    }

    private function opcoesFiltros(?string $tipo, Usuario $principal): array
    {
        $segmentos = Segmento::where('ativo', true)->orderBy('nome')->get(['id', 'nome']);

        if (UsuarioComercial::ehAdmin()) {
            return [
                'masters' => $this->usuariosPorTipo('master'),
                'marketplaces' => $this->usuariosPorTipo('marketplace'),
                'revendas' => $this->usuariosPorTipo('revenda'),
                'segmentos' => $segmentos,
            ];
        }

        if ($principal->tipo === 'master') {
            return [
                'masters' => collect([[
                    'id' => $principal->id,
                    'nome' => $principal->nomeExibicao(),
                ]]),
                'marketplaces' => $this->mapUsuariosLista(
                    $this->usuariosVisiveisAoMaster($principal, 'marketplace')->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')->get()
                ),
                'revendas' => $this->mapUsuariosLista(
                    $this->usuariosVisiveisAoMaster($principal, 'revenda')->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')->get()
                ),
                'segmentos' => $segmentos,
            ];
        }

        return [
            'masters' => collect(),
            'marketplaces' => collect(),
            'revendas' => collect(),
            'segmentos' => $segmentos,
        ];
    }

    private function usuariosPorTipo(string $tipo)
    {
        return Usuario::query()
            ->where('tipo', $tipo)
            ->where('ativo', true)
            ->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo, email)')
            ->get()
            ->map(fn (Usuario $usuario) => [
                'id' => $usuario->id,
                'nome' => $usuario->nomeExibicao(),
            ]);
    }

    private function mapUsuariosLista($usuarios)
    {
        return $usuarios->map(fn (Usuario $usuario) => [
            'id' => $usuario->id,
            'nome' => $usuario->nomeExibicao(),
        ]);
    }
}
