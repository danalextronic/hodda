<?php
namespace App\Http\Middleware;

use App\User;
use Closure;
use Sentinel;
use Redirect;
use Alert;

class CompanyWaiter
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
        if (
            Sentinel::check() && 
            Sentinel::inRole('bediening') == FALSE && 
            Sentinel::inRole('bedrijf') == FALSE && 
            Sentinel::inRole('admin') == FALSE
        )  {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
        
        return $next($request);
    }
}
