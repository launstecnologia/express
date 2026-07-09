<?php

namespace App\Http\Controllers\Royalty;

use App\Http\Controllers\Controller;
use App\Models\PlanoTaxa;
use App\Models\PlanoTaxaRoyalty;
use App\Models\Usuario;
use App\Services\RoyaltyCalculadorService;
use App\Support\UsuarioComercial;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComissaoConfiguracaoController extends Controller
{
    public function index()
    {
        $query = PlanoTaxaRoyalty::with(['taxa.plano', 'usuario'])->latest();
        $this->restringirConfiguracoesDaRede($query);

        return view('comissao.configuracoes.index', [
            'configuracoes' => $query->paginate(30),
        ]);
    }

    public function create()
    {
        return view('comissao.configuracoes.form', [
            'configuracao' => new PlanoTaxaRoyalty,
            'taxas' => PlanoTaxa::with('plano')->orderBy('instituicao')->get(),
            'usuarios' => $this->usuariosConfiguraveis(),
        ]);
    }

    public function store(Request $request, RoyaltyCalculadorService $calculador)
    {
        $dados = $this->validar($request);
        $this->validarLimite($dados, $calculador);

        PlanoTaxaRoyalty::create($dados);

        return redirect()->route('comissoes.configuracoes.index')->with('status', 'Configuração de comissão criada.');
    }

    public function edit(PlanoTaxaRoyalty $configuracao)
    {
        $this->autorizarConfiguracao($configuracao);

        return view('comissao.configuracoes.form', [
            'configuracao' => $configuracao,
            'taxas' => PlanoTaxa::with('plano')->orderBy('instituicao')->get(),
            'usuarios' => $this->usuariosConfiguraveis(),
        ]);
    }

    public function update(Request $request, PlanoTaxaRoyalty $configuracao, RoyaltyCalculadorService $calculador)
    {
        $this->autorizarConfiguracao($configuracao);

        $dados = $this->validar($request, $configuracao);
        $this->validarLimite($dados, $calculador);
        $configuracao->update($dados);

        return redirect()->route('comissoes.configuracoes.index')->with('status', 'Configuração de comissão atualizada.');
    }

    public function destroy(PlanoTaxaRoyalty $configuracao)
    {
        $this->autorizarConfiguracao($configuracao);
        $configuracao->delete();

        return redirect()->route('comissoes.configuracoes.index')->with('status', 'Configuração de comissão removida.');
    }

    private function validar(Request $request, ?PlanoTaxaRoyalty $configuracao = null): array
    {
        $dados = $request->validate([
            'plano_taxa_id' => [
                'required',
                'exists:plano_taxas,id',
                Rule::unique('plano_taxa_royalties', 'plano_taxa_id')
                    ->where(fn ($query) => $query->where('usuario_id', $request->integer('usuario_id')))
                    ->ignore($configuracao?->id),
            ],
            'usuario_id' => ['required', 'exists:usuarios,id'],
            'percentual' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $usuario = Usuario::findOrFail($dados['usuario_id']);
        abort_if($usuario->tipo === 'revenda', 422, 'Revenda não repassa comissão para níveis abaixo.');
        abort_unless($this->podeConfigurarUsuario($usuario), 403, 'Usuário fora da sua hierarquia.');

        $dados['nivel'] = $usuario->tipo;

        return $dados;
    }

    private function validarLimite(array $dados, RoyaltyCalculadorService $calculador): void
    {
        $taxa = PlanoTaxa::findOrFail($dados['plano_taxa_id']);
        $usuario = Usuario::findOrFail($dados['usuario_id']);
        $recebe = $calculador->percentualRecebidoUsuario($taxa, $usuario);

        $calculador->validarRepasse((float) $dados['percentual'], $recebe);
    }

    private function usuariosConfiguraveis()
    {
        $query = Usuario::with('hierarquia.pai.usuario')
            ->whereIn('tipo', ['admin', 'master', 'marketplace'])
            ->where('ativo', true)
            ->orderBy('tipo')
            ->orderBy('nome_fantasia');

        if (UsuarioComercial::ehMaster()) {
            $master = UsuarioComercial::principal();
            abort_unless($master, 403);

            $marketplaceIds = UsuarioComercial::marketplacesDo($master)->pluck('id');

            $query->where(function (Builder $q) use ($master, $marketplaceIds) {
                $q->whereKey($master->id)
                    ->orWhereIn('id', $marketplaceIds);
            });
        }

        return $query->get();
    }

    private function restringirConfiguracoesDaRede(Builder $query): void
    {
        if (! UsuarioComercial::ehMaster()) {
            return;
        }

        $master = UsuarioComercial::principal();
        abort_unless($master, 403);

        $ids = UsuarioComercial::marketplacesDo($master)->pluck('id')
            ->push($master->id)
            ->unique()
            ->values();

        $query->whereIn('usuario_id', $ids);
    }

    private function autorizarConfiguracao(PlanoTaxaRoyalty $configuracao): void
    {
        $usuario = $configuracao->usuario ?? Usuario::find($configuracao->usuario_id);
        abort_unless($usuario && $this->podeConfigurarUsuario($usuario), 403);
    }

    private function podeConfigurarUsuario(Usuario $usuario): bool
    {
        if (UsuarioComercial::ehAdmin()) {
            return true;
        }

        if (! UsuarioComercial::ehMaster()) {
            return false;
        }

        return UsuarioComercial::podeGerenciar($usuario)
            && in_array($usuario->tipo, ['master', 'marketplace'], true);
    }
}
