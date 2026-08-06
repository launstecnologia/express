<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PerfilPermissao;
use App\Models\SubUsuario;
use App\Models\Usuario;
use App\Rules\EmailUnicoAutenticacao;
use App\Services\AcessoOperacionalService;
use App\Services\SubUsuarioPrincipalService;
use App\Support\UsuarioComercial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class SubUsuarioController extends Controller
{
    public function create(Usuario $usuario)
    {
        abort_if($usuario->tipo === 'admin', 404);
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        return view('admin.subusuarios.form', [
            'dono' => $usuario,
            'subUsuario' => new SubUsuario,
            'perfis' => PerfilPermissao::where('dono_id', $usuario->id)->where('ativo', true)->orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request, Usuario $usuario)
    {
        abort_if($usuario->tipo === 'admin', 404);
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:150', new EmailUnicoAutenticacao(permitirEmailDonoId: $usuario->id)],
            'password' => ['required', 'string', 'min:8'],
            'perfil_id' => ['nullable', Rule::exists('perfis_permissao', 'id')->where('dono_id', $usuario->id)],
            'ativo' => ['boolean'],
        ]);

        $dados['dono_id'] = $usuario->id;
        $dados['dono_tipo'] = $usuario->tipo;

        SubUsuario::create($dados);

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Usuário operacional cadastrado.');
    }

    public function garantirPrincipal(Usuario $usuario, SubUsuarioPrincipalService $service)
    {
        abort_if($usuario->tipo === 'admin', 404);
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);

        $subUsuario = $service->garantirParaDono($usuario);

        $mensagem = $subUsuario->wasRecentlyCreated
            ? 'Usuário operacional criado com o mesmo e-mail da conta comercial.'
            : 'Usuário operacional com o e-mail comercial já existia.';

        return redirect()
            ->route('usuarios.show', $usuario)
            ->with('status', $mensagem.' Login: '.$subUsuario->email);
    }

    public function editPassword(Usuario $usuario, SubUsuario $subUsuario)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);
        $this->validarDono($usuario, $subUsuario);

        return view('admin.subusuarios.password', compact('usuario', 'subUsuario'));
    }

    public function updatePassword(Request $request, Usuario $usuario, SubUsuario $subUsuario)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);
        $this->validarDono($usuario, $subUsuario);

        $dados = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $subUsuario->update(['password' => $dados['password']]);

        return redirect()->route('usuarios.show', $usuario)->with('status', 'Senha do usuário operacional atualizada.');
    }

    public function acessar(Usuario $usuario, SubUsuario $subUsuario, AcessoOperacionalService $acesso)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);
        abort_unless(auth()->user()?->tipo === 'admin', 403);
        $this->validarDono($usuario, $subUsuario);

        try {
            $url = $acesso->gerarUrlAcesso($usuario, $subUsuario, auth()->user());
        } catch (RuntimeException $e) {
            return redirect()
                ->route('usuarios.show', $usuario)
                ->withErrors(['acesso' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function resetarSenha(Usuario $usuario, SubUsuario $subUsuario)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);
        $this->validarDono($usuario, $subUsuario);

        $subUsuario->update([
            'password' => '123456',
            'must_change_password' => true,
        ]);

        return redirect()->route('usuarios.show', $usuario)
            ->with('status', strtolower($subUsuario->email) === strtolower($usuario->email)
                ? 'Senha operacional resetada para 123456. Como o e-mail é o mesmo da conta comercial, use "Resetar senha comercial" no topo para alterar o login principal.'
                : 'Senha operacional resetada para 123456. O usuário deverá criar uma nova senha no próximo acesso.');
    }

    public function redirectShow(Usuario $usuario, SubUsuario $subUsuario)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);
        $this->validarDono($usuario, $subUsuario);

        return redirect()->route('usuarios.show', $usuario);
    }

    public function destroy(Request $request, Usuario $usuario, SubUsuario $subUsuario)
    {
        abort_unless(UsuarioComercial::podeGerenciar($usuario), 403);
        $this->validarDono($usuario, $subUsuario);

        $dados = $request->validate([
            'senha_admin' => ['required', 'string'],
            'confirmacao' => ['accepted'],
        ], [
            'senha_admin.required' => 'Informe sua senha de administrador.',
            'confirmacao.accepted' => 'Confirme que deseja excluir este usuário.',
        ]);

        if (! Hash::check($dados['senha_admin'], $request->user()->password)) {
            return redirect()
                ->route('usuarios.show', $usuario)
                ->withErrors(['senha_admin' => 'Senha de administrador incorreta.'])
                ->with('abrir_modal_excluir_subusuario', $subUsuario->id);
        }

        $nome = $subUsuario->nome;
        $subUsuario->delete();

        return redirect()
            ->route('usuarios.show', $usuario)
            ->with('status', "Usuário operacional {$nome} excluído.");
    }

    private function validarDono(Usuario $usuario, SubUsuario $subUsuario): void
    {
        abort_unless((int) $subUsuario->dono_id === (int) $usuario->id, 404);
    }
}
