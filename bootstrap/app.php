<?php

use App\Http\Middleware\EnsureAcessoAdminMaster;
use App\Http\Middleware\EnsureFinanceiroVisivel;
use App\Http\Middleware\EnsureUsuarioAtivo;
use App\Http\Middleware\ChecarTrocaSenha;
use App\Http\Middleware\EnsureMarketplaceTenantAccess;
use App\Http\Middleware\EnsureNivel;
use App\Http\Middleware\EnsurePermissao;
use App\Http\Middleware\RedirectMarketplacePlanos;
use App\Http\Middleware\ResolveMarketplaceTenant;
use App\Support\TenantUrl;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Http\Request;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);

        $middleware->web(append: [
            ResolveMarketplaceTenant::class,
            EnsureMarketplaceTenantAccess::class,
        ]);

        $middleware->priority([
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ResolveMarketplaceTenant::class,
            EnsureMarketplaceTenantAccess::class,
        ]);

        $middleware->alias([
            'nivel' => EnsureNivel::class,
            'permissao' => EnsurePermissao::class,
            'tenant.access' => EnsureMarketplaceTenantAccess::class,
            'usuario.ativo' => EnsureUsuarioAtivo::class,
            'trocar.senha' => ChecarTrocaSenha::class,
            'planos.marketplace' => RedirectMarketplacePlanos::class,
            'acesso.admin-master' => EnsureAcessoAdminMaster::class,
            'financeiro.visivel' => EnsureFinanceiroVisivel::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            return TenantUrl::aplicarTenant(route('login'), TenantUrl::slugAtual($request));
        });

        $middleware->redirectUsersTo(function (Request $request) {
            return TenantUrl::aplicarTenant(route('dashboard'), TenantUrl::slugAtual($request));
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (HttpException|TokenMismatchException $e, Request $request) {
            $sessaoExpirada = $e instanceof TokenMismatchException
                || ($e instanceof HttpException && ($e->getStatusCode() === 419 || $e->getPrevious() instanceof TokenMismatchException));

            if (! $sessaoExpirada) {
                return null;
            }

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['message' => 'Sessão expirada. Atualize a página e tente novamente.'], 419);
            }

            try {
                $loginUrl = TenantUrl::aplicarTenant(route('login'), TenantUrl::slugAtual($request));
            } catch (\Throwable) {
                $loginUrl = url('/login');
            }

            if ($request->is('logout') || $request->routeIs('logout')) {
                return redirect()->to($loginUrl);
            }

            try {
                return redirect()
                    ->to($loginUrl)
                    ->withInput($request->except(['password', '_token']))
                    ->withErrors(['email' => 'Sua sessão expirou. Entre novamente.']);
            } catch (\Throwable) {
                return redirect()->to($loginUrl);
            }
        });
    })
    ->create();
