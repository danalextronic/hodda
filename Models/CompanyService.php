<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use DB;
use Carbon\Carbon;

class CompanyService extends Model
{    
    
    protected $table = 'company_services';

    protected $filliable = array(
    	'name',
    	'tax',
    	'content',
    	'company',
    	'period',
    	'price'
    );

}