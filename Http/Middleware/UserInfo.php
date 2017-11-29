<?php

namespace App\Http\Middleware;

use Closure;
use App\User;
use App\Models\Company;
use Sentinel;
use Redirect;
use Route;

class UserInfo
{
    public function handle($request, Closure $next)
    {   
        $this->userCompanies = array();
        $this->userCompaniesWaiter = array();
        $this->userCompaniesCallcenter = array();

        if (Sentinel::check()) {
            if (Sentinel::inRole('bedrijf')) {
                $this->userCompanies = User::find(Sentinel::getUser()->id)->companies;
            }

            if (Sentinel::inRole('bediening')) {
                $this->userCompaniesWaiter = Company::select(
                    'id',
                    'slug',
                    'name'
                )
                    ->where('waiter_user_id', '=', Sentinel::getUser()->id)
                    ->get()
                ;
            }

            if (Sentinel::inRole('callcenter')) {
                $this->userCompaniesCallcenter = Company::select(
                    'id',
                    'slug',
                    'name'
                )
                    ->where('caller_id', '=', Sentinel::getUser()->id)
                    ->get()
                ;
            }

            // If banned
            $userBanned = User::banned(Sentinel::getUser()->id);

            if (is_array($userBanned) && count($userBanned) >= 1) {
                $reasonsArray = array();

                foreach ($userBanned as $key => $userBan) {
                    $reasonsArray[] = $userBan['reason'];
                }

                $reasons = implode($reasonsArray, '');

                Sentinel::logout();
                
                alert()->error('Helaas', 'Wij moeten u helaas mededelen dat u verbannen bent om de volgende reden(s): '.$reasons)->persistent('Sluiten');
               
                if (Route::getCurrentRoute()->uri() != '/')
                    return Redirect::to('/');
            }
        }

        $userCheck = Sentinel::check();
        $userInfo = Sentinel::getUser();

        view()->share(array(
            'userAuth' => $userCheck,
            'userInfo' => $userInfo,
            'userAdmin' => $userCheck ? Sentinel::inRole('admin') : '',
            'userCompanies' => $this->userCompanies,
            'userCompaniesWaiter' => $this->userCompaniesWaiter,
            'userCompaniesCallcenter' => $this->userCompaniesCallcenter,
            'userWaiter' => $userCheck ? Sentinel::inRole('bediening') : '',
            'userCompany' => $userCheck ? Sentinel::inRole('bedrijf') : '',
            'userCallcenter' => $userCheck ? Sentinel::inRole('callcenter') : '',
            'selectedUser' => new User(),
        ));

        return $next($request);
    }
}
