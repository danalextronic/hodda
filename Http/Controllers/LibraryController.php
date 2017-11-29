<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;

class LibraryController extends Controller
{
    function index()
    {
	    return view('pages/library/index', []);
    }
    
    function videos(Request $request, $slug = '')
    {
	    if($slug != '')
	    {
		    $Videos = \App\Models\Library::select()->where(['type' => 'video'], ['cat' => $slug])->get();
	    } else {
		    $Videos = array();
	    }
	    
	    $VideoCats = \App\Models\Library::select()->where(['type' => 'video'])->groupBy('cat')->get();
	    return view('pages/library/videos', [
		    'cats' => $VideoCats,
		    'videos' => $Videos
	    ]);
    }
    
    function library(Request $request, $slug = '')
    {
	    if($slug != '')
	    {
		    $Articles = \App\Models\Library::select()->where(['type' => 'library'], ['cat' => $slug])->get();
	    } else {
		    $Articles = array();
	    }
	    
	    $VideoCats = \App\Models\Library::select()->where(['type' => 'library'])->groupBy('cat')->get();
	    return view('pages/library/library', [
		    'cats' => $VideoCats,
		    'articles' => $Articles
	    ]);
    }
}
