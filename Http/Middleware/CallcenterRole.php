<?php

namespace App\Http\Middleware;

use Closure;
use App\User;
use Sentinel;
use Redirect;

class CallcenterRole
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
        if (Sentinel::check()){
            if(Sentinel::inRole('admin') == FALSE && Sentinel::inRole('callcenter') == FALSE) {
                User::getRoleErrorPopup();
                return Redirect::to('/');
            }
        } else  {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        return $next($request);
    }
}
