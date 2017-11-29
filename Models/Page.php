<?php
namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table = 'pages';

    public static function getPages()
    {
    	$pages = static::select(DB::raw('id, slug, title, link_to, category_id'))
    					->where('is_hidden', 0)->get();
    					
        $content = array();

        if($pages->count() >= 1)
        {
        	foreach($pages as $pageLink)
            {
                $content[$pageLink->category_id][] = array(
                                                'link_to' => $pageLink->link_to,
                								'title' => $pageLink->title,
                								'slug'  => $pageLink->slug
                							);
    	    }
        }
        
        return $content;
    }
}