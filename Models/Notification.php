<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;

class Notification extends Model implements HasMediaConversions
{
    use HasMediaTrait;

    protected $table = 'alert_notifications';
    
    protected $guarded = array();

    public function registerMediaConversions()
    {
        $this
            ->addMediaConversion('medium')
            ->setManipulations(
                array(
                    'w' => 600, 
                    'h' => 400, 
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

}
