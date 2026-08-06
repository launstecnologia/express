<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChecarTrocaSenha
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (
            $user
            && method_exists($user, 'getAttribute')
            && $user->must_change_password
            && ! $request->session()->has('acesso_via_admin_id')
            && ! $request->routeIs('senha.trocar', 'senha.trocar.salvar', 'logout')
        ) {
            return redirect()->route('senha.trocar');
        }

        return $next($request);
    }
}
