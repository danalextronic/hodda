<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMedia;

class SysSetting extends Model implements HasMediaConversions
{
    use HasMediaTrait;

    protected $table = 'system_settings';

  	public function registerMediaConversions()
    {
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
}