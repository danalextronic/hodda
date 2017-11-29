<?php

namespace App;

use Alert;
use Redirect;
use App\Models\UserBan;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\UserTransaction;
use App\Models\Reservation;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;
use DB;

class User extends Model implements AuthenticatableContract, AuthorizableContract, CanResetPasswordContract, HasMediaConversions
{
    use Authenticatable, Authorizable, CanResetPassword;
    use HasMediaTrait;

    protected $table = 'users';
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];

 public function registerMediaConversions()  
    {
        $this
            ->addMediaConversion('hugeThumb')
            ->setManipulations(
                array(
                    'w' => 550, 
                    'h' => 500, 
                    'fit' => 'stretch', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;

        $this
            ->addMediaConversion('175Thumb')
            ->setManipulations(
                array(
                    'w' => 175, 
                    'h' => 132, 
                    'fit' => 'stretch', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;

        $this
            ->addMediaConversion('mobileThumb')
            ->setManipulations(
                array(
                    'w' => 550, 
                    'h' => 340, 
                    'fit' => 'max', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;
        
        $this
            ->addMediaConversion('450pic')
            ->setManipulations(
                array(
                    'w' => 451, 
                    'h' => 340, 
                    'fit' => 'stretch', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;

        $this->addMediaConversion('thumb')
            ->setManipulations(
                array(
                    'w' => 368, 
                    'h' => 232, 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;
    }    

    public static function getRoleErrorPopup() 
    {
        alert()->error('Helaas', 'U heeft niet de bevoegde rechten om deze pagina te bezoeken')->persistent('Sluiten');
    }

    public function companies()
    {
        return $this->hasMany('App\Models\Company', 'user_id');
    }

    public function companiesWaiter()
    {
        return $this->hasMany('App\Models\Company', 'waiter_user_id');
    }

    public static function banned($userId)
    {
        $banned = UserBan::where('user_id', $userId)
            ->where('expired_date', '>=', date('Y-m-d'))
            ->get()
            ->toArray()
        ;

        return $banned;
    }

}
