<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use DB;

class TemporaryAuth extends Model
{

    protected $table = 'temporary_auth';

    public function createCode($userId, $redirectTo = null)
    {
    	$randomCode = str_random(64);

    	// Add new temporary login session
    	$this->code = $randomCode;
    	$this->user_id = $userId;
    	$this->redirect_to = $redirectTo == null ? 'account' : $redirectTo;
    	$this->save();

    	return $randomCode;
    }	

}