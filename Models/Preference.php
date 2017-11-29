<?php
namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;
use Cviebrock\EloquentSluggable\SluggableInterface;
use Cviebrock\EloquentSluggable\SluggableTrait;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;

class Preference extends Model implements SluggableInterface, HasMediaConversions
{

    use SluggableTrait;
    use HasMediaTrait;

    protected $sluggable = [
        'build_from' => 'name',
        'save_to'    => 'slug'
    ];

    protected $table = 'preferences';

    public function addClick($name, $categoryId) 
    {
        $existsQuery = $this
            ->where('slug', '=', $name)
            ->where('category_id', '=', $categoryId)
            ->first()
        ;

        if ($existsQuery) {
            $existsQuery->clicks = $existsQuery->clicks + 1;
            $existsQuery->save();
        }
    }

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

    public static function getPreferences()
    {
    	$preferences = static::select(
            DB::raw('id, name, slug, category_id')
        )
            ->orderBy('name', 'asc')
            ->get()
        ;

        $option = array();

        if ($preferences->count() >= 1) {
        	foreach ($preferences as $preference) {
                if ($preference->category_id == 5) {
                    $option[$preference->category_id][rawurlencode($preference->name)] = $preference->name;
                } else {
                    $option[$preference->category_id][str_slug($preference->slug)] = $preference->name;
                }
    	    }
        }

        return $option;
    }

    public function getRegio()
    {
        $preferences = static::select(
            'id',
            'category_id',
            'slug',
            'name'
        )
            ->where('category_id', 9)
            ->get()
        ;

        $regio = array();
        $regioName = array();

        foreach ($preferences as $preference) {
            $regio[$preference->id] = $preference->name;
            $regioNumber[$preference->slug] = $preference->id;
            $regioName[$preference->id] = $preference->name;
        }

        return array(
            'regio' => isset($regio) ? $regio : '', 
            'regioNumber' => isset($regioNumber) ? $regioNumber : '', 
            'regioNumber' => isset($regioName) ? $regioName : '', 
        );
    }

}