<?php

namespace App\Http\Middleware;

use App\User;
use Closure;
use Sentinel;
use Redirect;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {   
        if(Sentinel::check()) {
            if(Sentinel::inRole('admin') == FALSE) {
                User::getRoleErrorPopup();
                return Redirect::to('/');
            }
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        return $next($request);
    }
}
