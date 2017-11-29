<?php
namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;
use Cviebrock\EloquentSluggable\SluggableInterface;
use Cviebrock\EloquentSluggable\SluggableTrait;
use URL;
use Sentinel;

class Content extends Model implements SluggableInterface
{

    use SluggableTrait;

    protected $sluggable = [
        'build_from' => 'name',
        'save_to' => 'slug'
    ];

    protected $table = 'content_blocks';

    public static function getBlocks($options = NULL)
    {
        $blocks = static::select(
            'id',
            'content',
            'category'
        )
            ->get()
        ;

        $content = array();

        if ($blocks->count() >= 1) {
            foreach ($blocks as $block) {
                $content[$block->category] = '';

                if (Sentinel::check() && Sentinel::inRole('admin')) {
                    $content[$block->category] .= '<a href=\''.URL::to('admin/contents/update/'.$block->id).'\' target=\'_blank\' style=\' float: right;\'><i class=\'pencil icon\'></i></a>';
                }

                $content[$block->category] .= preg_replace('/%days%/i', (isset($options['days']) ? $options['days'] : ''), $block->content);
            }
        }
        
        return $content;
    }

    public static function getMailTemplate()
    {
    	$blocks = static::select(
            'id',
            'name',
            'content',
            'category'
        )
            ->get()
        ;

        $content = array();

        if ($blocks->count() >= 1) {
        	foreach ($blocks as $block) {
                $content[$block->category] = array(
                    'title' => $block->name,
                    'content' => $block->content
                );
    	    }
        }
        
        return $content;
    }

}