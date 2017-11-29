<?php

namespace App\Http\Middleware;

use Alert;
use App\User;
use Closure;
use Illuminate\Contracts\Auth\Guard;
use Sentinel;
use Redirect;

class Authenticate
{
    /**
     * The Guard implementation.
     *
     * @var Guard
     */
    protected $auth;

    /**
     * Create a new filter instance.
     *
     * @param  Guard  $auth
     * @return void
     */
    public function __construct(Guard $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (!Sentinel::check()) {
            if (!$request->ajax()) {
                if ($request->is('/') == FALSE) {
                    alert()->error('', 'U heeft geen toegang tot deze pagina.')->persistent('Sluiten');
                    return Redirect::to('/');
                }
            }
        }

        return $next($request);
    }
}
