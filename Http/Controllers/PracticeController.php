<?php
namespace App\Http\Controllers;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Practice;
use App\Models\Page;
use App\Helpers\SmsHelper;
use App\Helpers\widgetHelper;
use Sentinel; 
use Reminder;
use Redirect;
use Mail;
use DB;
use URL;
use Illuminate\Http\Request;
use Carbon\Carbon;
use League\Csv\Reader;
use Session;
use Setting;

class PracticeController extends Controller 
{

    public function __construct() 
    {  
        $this->user = Sentinel::getUser();
    }

    public function index(Request $request) 
    {
        return view('pages/practice/index', [
            'title' => 'Oefeningen',
            'data' => Practice::select()->orderBy('cat', 'ASC')->orderBy('name', 'ASC')->get()
        ]);
    }
}