<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchHistory extends Model
{

    protected $table = 'search_history';

    public function addTerm($term, $page)
    {
    	if (strlen($term) >= 4) {
	        $exists = $this
	            ->where('term',  $term)
	            ->where('page',  $page)
	            ->first()
	        ;

	        if (count($exists) == 1) {
	            $exists->count = $exists->count + 1;
	            $exists->save();
	        } else {
	            $this->page = $page;
	            $this->term = $term;
	            $this->save();
	        }
	    }
    }	

}