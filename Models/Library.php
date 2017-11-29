<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Sentinel;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Cviebrock\EloquentSluggable\SluggableInterface;
use Cviebrock\EloquentSluggable\SluggableTrait;
use Phoenix\EloquentMeta\MetaTrait;
use App;
use Carbon\Carbon;
use App\Models\ReservationOption;
use App\Models\Preference;
use App\Models\CompanyClick;
use Config;
use URL; 
use Request;
use DB;

class Library extends Model implements SluggableInterface
{
    use MetaTrait;
    use SluggableTrait;

    protected $table = 'library';

    protected $sluggable = [
        'build_from' => 'name',
        'save_to' => 'slug',
    ];

}