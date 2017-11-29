<?php
namespace App\Http\Controllers;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Models\Page;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\SearchHistory;
use App\Models\Preference;
use App\Models\Barcode;
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

class FoodController extends Controller 
{

    public function __construct() 
    {  
        $this->user = Sentinel::getUser();
    }

    public function index(Request $request) 
    {
        return view('pages/food/index', [
            'title' => 'Voedingsmiddelen',
            'foods' => Food::select()->orderBy('cat', 'ASC')->orderBy('name', 'ASC')->get()
        ]);
    }
}