<?php
namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Sentinel;
use Redirect;
use Alert;

class CompanyOwner
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
        $companyCheck = Company::where(
            'id', $request->input('company')
        )
            ->where('user_id', Sentinel::getUser()->id)
            ->get()
        ;

        if(Sentinel::inRole('admin') == FALSE)  {
            if($companyCheck->count() == 0) {
                alert()->error('', 'U heeft hier niet de bevoegde rechten voor.')->persistent('Sluiten');
                return Redirect::to('/');
            }
        }
        
        return $next($request);
    }
}
