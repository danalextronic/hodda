<?php
namespace App\Http\Controllers;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Company;
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

class HomeController extends Controller 
{

    public function __construct() 
    {  
        $this->user = Sentinel::getUser();
    }

    public function index(Request $request) 
    {
        // Companies
        $companies = Company::select(           
            'companies.id',
            'companies.name',
            'companies.slug',
            'companies.days',
            'companies.menu',
            'companies.description',
            'companies.city'
        );

        if (
            Sentinel::check()
            && $this->user->city != 'null'
            && $this->user->city != NULL
            && $this->user->city != '[""]'
        ) {
            $userCities = json_decode($this->user->city);

            if (is_array($userCities)) {
                foreach ($userCities as $userCity) {
                    $companies = $companies->orderByRaw('companies.regio REGEXP "[[:<:]]'.$userCity.'[[:>:]]" desc');
                }
            } else {
                $companies = $companies->orderBy('companies.created_at', 'asc');
            }
        } else {
            $companies = $companies->orderBy('companies.created_at', 'asc');
        }

        $companies = $companies
            ->where('no_show', 0)
            ->where('company_type', 0)
            ->orderBy('companies.clicks', 'desc')
            ->with('media')
        ;
        
        $countCompanies = $companies->count();
        $companies = $companies->paginate($request->input('limit', 15));

        $queryString = $request->query();
        unset($queryString['limit']);

        return view('pages/home', [
            'user' => $this->user,
            'countCompanies' => $countCompanies,
            'companies' => $companies,
            'limit' => $request->input('limit', 15),
            'queryString' => $queryString,
            'paginationQueryString' => $request->query()
        ]);
    } 

    public function cinemas(Request $request) 
    {
        // Companies
        $companies = Company::select(           
            'companies.id',
            'companies.name',
            'companies.slug',
            'companies.days',
            'companies.menu',   
            'companies.description',
            'companies.city'
        );

        if (
            Sentinel::check()
            && $this->user->city != 'null'
            && $this->user->city != NULL
            && $this->user->city != '[""]'
        ) {
            $userCities = json_decode($this->user->city);

            if (is_array($userCities)) {
                foreach ($userCities as $userCity) {
                    $companies = $companies->orderByRaw('companies.regio REGEXP "[[:<:]]'.$userCity.'[[:>:]]" desc');
                }
            } else {
                $companies = $companies->orderBy('companies.created_at', 'asc');
            }
        } else {
            $companies = $companies->orderBy('companies.created_at', 'asc');
        }

        $companies = $companies
            ->where('no_show', 0)
            ->where('company_type', 1)
            ->orderBy('companies.clicks', 'desc')
            ->with('media')
        ;
        
        $countCompanies = $companies->count();
        $companies = $companies->paginate($request->input('limit', 15));

        $queryString = $request->query();
        unset($queryString['limit']);

        return view('pages/home', [
            'cinema' => 1,
            'user' => $this->user,
            'countCompanies' => $countCompanies,
            'companies' => $companies,
            'limit' => $request->input('limit', 15),
            'queryString' => $queryString,
            'paginationQueryString' => $request->query()
        ]);
    } 

    public function searchRedirect(Request $request) 
    {
        switch ($request->input('page')) {
            case 'restaurant':
                $redirectTo = 'search';
                break;
        }

        return Redirect::to($redirectTo.'?q='.$request->input('q'));
    }

    public function preferences(Request $request) 
    {
        $dataSearch = array(
            'filter' => 1,
            'q' => $request->input('q'),
        );

        $data = array(
            'filter' => 1,
            'q' => $request->input('q'),
        );

        if ($request->has('q') || $request->has('date')) {
            return Redirect::to('search?'.http_build_query($dataSearch));
        } else {
           return Redirect::to('/?'.http_build_query($data)); 
        }
    }

    public function setLang(Request $request, $locale) 
    {
        $request->session()->put('locale', $locale);

        App::setLocale($locale);

        return Redirect::to($request->has('redirect') ? $request->input('redirect') : '/');
    }

    public function faq(Request $request, $id = null, $slug = null)
    {
        if ($request->has('q')) {
            $searchHistory = new SearchHistory(); 
            $searchHistory->addTerm($request->input('q'), '/faq');  // Add to Search History
        }
        
        if ($request->has('slug') && $request->has('step')) {
            $company = Company::where('slug', $request->input('slug'))->first();

            if ($company) {
                if (Sentinel::inRole('admin') == FALSE && $company->signature_url == NULL) {
                    alert()->error('', 'U heeft nog geen handtekening opgegeven en u bent nog niet akkoord gegaan met de Algemene Voorwaarden.')->persistent('Sluiten');
                    return Redirect::to('admin/companies/update/'.$company->id.'/'.$company->slug.'?step=1');
                }
            }
        }

        $category = FaqCategory::select(
            'id',
            'name',
            'category_id'
        )
            ->where('id', '=', $id)
            ->where('slug', '=', $slug)
            ->first()
        ;

        if (count($category) == 1) {
            $categories = FaqCategory::select(
                'id',
                'slug',
                'name'
            )
                ->whereNull('category_id')
                ->get()
            ;

            $subcategories = FaqCategory::select(
                'id',
                'slug',
                'name'
            )
                ->whereNotNull('category_id')
                ->where('category_id', '=', $category->category_id)
                ->orWhere('category_id', '=', $category->id)
                ->get()
            ;

            $questions = Faq::select(
                'id',
                'title',
                'answer'
            )
                ->orderBy('clicks', 'desc')
            ;

            if ($request->has('q')) {
                $questions = $questions->where('title', 'LIKE', '%'.$request->input('q').'%');
            }

            $questions = $questions->where(function ($query) use ($id, $category) {
                if (isset($category->category_id)) {
                    $query->where('subcategory', '=', $id);
                } else {
                    $query
                        ->where('category', '=', $category->id)
                        ->orWhere('subcategory', '=', $category->id)
                    ;
                }
            })
                ->paginate(15)
            ;

            return view('pages/faq', [
                'questions' => $questions,
                'categories' => $categories,
                'subcategories' => $subcategories,
                'slug' => $slug,
                'categoryId' => $category->category_id == null ? $category->id : $category->category_id,
            ]);
        } else {
            $categories = FaqCategory::select(
                'id',
                'slug',
                'name'
            )
                ->whereNull('category_id')
                ->get()
            ;

            $questions = Faq::select(
                'id',
                'title',
                'answer'
            )
                ->orderBy('clicks', 'desc')
            ;

            if ($request->has('q')) {
                $questions = $questions->where('title', 'LIKE', '%'.$request->input('q').'%');
            }

            $questions = $questions->paginate(15);

            return view('pages/faq', [
                'questions' => $questions,
                'categories' => $categories,
                'categoryId' => null
            ]);
        }
    }

    public function page($slug)
    {
        $page = Page::where('slug', $slug);

        if (Sentinel::check() == FALSE || Sentinel::check() && Sentinel::inRole('admin') == FALSE) {
            $page = $page->where('is_hidden', 0);
        }

        $page = $page->first();

        if ($page) {
            $widgetHelper = new WidgetHelper();

            return view('pages/page', array(
                'page' => $page,
                'content' => $widgetHelper->search($page->id, $page->content)
            ));
        } else {
            App::abort(404);
        }
    }

    public function search(Request $request)
    {   
        // Add to Search History
        $searchHistory = new SearchHistory();
        $searchHistory->addTerm($request->input('q'), '/search');

        $companiesLimit = $request->input('limit', 15);

        $companies = Company::select(           
            'companies.id',
            'companies.name',
            'companies.slug',
            'companies.days',
            'companies.description',
            'companies.city'
        );

        if (
            Sentinel::check()
            && $this->user->city != 'null'
            && $this->user->city != NULL
            && $this->user->city != '[""]'
        ) {
            $userCities = json_decode($this->user->city);

            if (is_array($userCities)) {
                foreach ($userCities as $userCity) {
                    $companies = $companies->orderByRaw('companies.regio REGEXP "[[:<:]]'.$userCity.'[[:>:]]" desc');
                }
            } else {
                $companies = $companies->orderBy('companies.created_at', 'asc');
            }
        } else {
            $companies = $companies->orderBy('companies.created_at', 'asc');
        }

        if ($request->has('q')) {         
            $termDivider = str_replace(' ', '|', $request->input('q'));

            $companies->where(function ($query) use($request, $termDivider) {
                $query
                    ->where('companies.name', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('address', 'RLIKE', $termDivider)
                    ->orWhere('zipcode', 'RLIKE', $termDivider)
                    ->orWhere('city', 'RLIKE', $termDivider)
                ;
            });
        }

        if ($request->has('regio')) {    
            $preferences = new Preference();
            $preferences->addClick(str_slug($request->input('regio')), 9);

            $regio = $preferences->getRegio();
            $companies = $companies
                ->where('companies.regio', 'REGEXP', '"[[:<:]]'.$regio['regioNumber'][$request->input('regio')].'[[:>:]]"')
                ->orWhere('companies.regio', '=', $regio['regioNumber'][$request->input('regio')])
            ;
        }

        $newCompanyId = array();
        $companyId = array();

        $companies = $companies->where('no_show', 0)->with('media');

        $countCompanies = $companies->count();
        $companies = $companies->paginate($companiesLimit);
        $queryString = $request->query();
        
        if (
            count($companies) == 0 
            OR date('Y-m-d') == date('Y-m-d', strtotime($request->input('date')))
            && date('H:i') >= date('H:i', strtotime($request->input('sltime')))
        ) {
            alert()->error('', 'Er zijn geen zoekresultaten gevonden met uw selectiecriteria.')->persistent('Sluiten');

            return Redirect::to('/');
        }   

        return view('pages/search', [
            'companies' => $companies,
            'countCompanies' => $countCompanies,
            'limit' => $companiesLimit,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query()
        ]); 
    }

    public function contact(Request $request)
    {
        return view('pages/contact');
    }

    public function contactAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'content' => 'required|min:10',
            'subject' => 'required|min:5',
            'CaptchaCode' => 'required|valid_captcha'
        ]);

        $websiteSettings = json_decode(json_encode(Setting::get('website')), true);

        $data = array(
            'request' => $request
        );

        Mail::send('emails.contact_site', $data, function ($message) use ($request, $websiteSettings) {
            $message
                ->to(isset($websiteSettings['contact_email']) ? $websiteSettings['contact_email'] : 'info@moviemeal.nl')
                ->subject($request->input('subject'))
                ->from($request->input('email'))
            ;
        });

        Alert::success('', 'Uw bericht is succesvol verzonden.  Wij hopen u zo snel mogelijk antwoord te kunnen geven.')->persistent('Sluiten');

        return Redirect::to('contact');
    }

    public function redirectTo(Request $request)
    {   
        if ($request->has('p')) {
            switch ($request->input('p')) {
                case 2:
                    Setting::set('discount.views2', Setting::get('discount.views2') + 1);
                    break;

                case 3:
                    Setting::set('discount.views3', Setting::get('discount.views3') + 1);
                    break;
                
                default:
                    Setting::set('discount.views', Setting::get('discount.views') + 1);
                    break;
            }
        }

        return Redirect::to($request->has('to') ? $request->input('to') : '/');
    }

    public function sourceRedirect(Request $request)
    {   
        $websiteSettings = json_decode(json_encode(Setting::get('website')), true);

        if (isset($websiteSettings['source'])) {
            $sources = explode(PHP_EOL, $websiteSettings['source']);

            if (is_array($sources) && in_array($request->input('source'), $sources)) {
                if ($request->has('source')) {
                    return Redirect::to('/')->withCookie(cookie('source', $request->input('source'), 44640));
                }
            } else {
                return Redirect::to('/');
            }
        }
    }

}