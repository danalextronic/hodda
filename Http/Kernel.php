<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * @var array
     */
    protected $middleware = [
        \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
        \App\Http\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\VerifyCsrfToken::class,
        \App\Http\Middleware\LanguageMiddleware::class,
        'anlutro\LaravelSettings\SaveMiddleware'
    ];
    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
        ],
        'api' => [
            'throttle:60,1',
        ],
    ];
    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'callcenter' => \App\Http\Middleware\CallcenterRole::class,
        'admin' => \App\Http\Middleware\Admin::class,
        'adminowner' => \App\Http\Middleware\AdminOwner::class,
        'userInfo' => \App\Http\Middleware\UserInfo::class,
        'owner' => \App\Http\Middleware\CompanyOwner::class,
        'waiter' => \App\Http\Middleware\CompanyWaiter::class,
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class
    ];
}
