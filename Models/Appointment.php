<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Phoenix\EloquentMeta\MetaTrait;

class Appointment extends Model
{
	use MetaTrait;

 	protected $guarded = array();
 	
    protected $table = 'appointments';

}