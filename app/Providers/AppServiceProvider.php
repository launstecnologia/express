<?php

namespace App\Providers;

use App\Auth\MultiUserProvider;
use App\Models\Estabelecimento;
use App\Observers\EstabelecimentoObserver;
use App\Services\ChamadoMenuBadgeService;
use App\Services\NotificacaoHeaderService;
use App\Support\AvatarUsuario;
use App\Support\PlatformMail;
use App\Support\PlatformSettings;
use App\Support\TenantBranding;
use App\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Session::extend('database', function ($app) {
            $connection = $app['db']->connection($app['config']['session.connection']);

            return new DatabaseSessionHandler(
                $connection,
                $app['config']['session.table'],
                $app['config']['session.lifetime'],
                $app
            );
        });
    }

    public function boot(): void
    {
        Estabelecimento::observe(EstabelecimentoObserver::class);

        PlatformMail::apply();

        Auth::provider('multi_users', fn ($app) => new MultiUserProvider($app['hash']));

        View::composer(['layouts.app', 'auth.*', 'publico.*'], function ($view) {
            $view->with(
                TenantBranding::deveExibirMarcaTenant()
                    ? TenantBranding::paraViews()
                    : PlatformSettings::paraViews()
            );
        });

        View::composer('layouts.app', function ($view) {
            $usuario = auth()->user();

            $view->with([
                'chamadosAbertos' => app(ChamadoMenuBadgeService::class)->contar($usuario),
                'avatarUrl' => AvatarUsuario::url($usuario),
                'userIniciais' => AvatarUsuario::iniciais($usuario),
                'notificacoes' => app(NotificacaoHeaderService::class)->listar($usuario),
                'notificacoesTotal' => app(NotificacaoHeaderService::class)->total($usuario),
            ]);
        });
    }
}
