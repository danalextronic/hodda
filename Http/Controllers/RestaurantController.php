<?php
namespace App\Http\Controllers;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Http\Requests\RestaurantRequest;
use App\Models\Company;
use App\Models\CompanyReservation;
use App\Models\MailTemplate;
use App\Models\Preference;
use App\Models\News;
use App\Helpers\CalendarHelper;
use Sentinel;
use Redirect;
use Carbon\Carbon;
use Mail;
use URL;
use Illuminate\Http\Request;
use Setting;

class RestaurantController extends Controller 
{

    public function index($slug, Request $request)
    {
        $company = Company::with('media')
            ->where('no_show', 0)
            ->where('slug', $slug)
            ->first()
        ;

        if ($company) {
            // Add Click
            $companyClick = new Company;
            $companyClick->addClick($request->getClientIp(), $company->id);

            $mediaItems = $company->getMedia('default'); 

            $companyRegioArray = json_decode($company->regio);

            $companies = Company::where('name', '!=', $company->name)
                ->where(function ($query) use($company, $companyRegioArray) {
                    if (is_array($companyRegioArray)) {
                        foreach ($companyRegioArray as $key => $regio) {  
                            if ($key == 0) {                 
                                $query->where(function ($subQuery) use($regio) {
                                    $subQuery
                                        ->where('regio', 'REGEXP', '"[[:<:]]'.$regio.'[[:>:]]"')
                                        ->orWhere('regio', '=', $regio)
                                    ;
                                });
                            } else {
                                $query->orWhere(function ($subQuery) use($regio) {
                                    $subQuery
                                        ->where('regio', 'REGEXP', '"[[:<:]]'.$regio.'[[:>:]]"')
                                        ->orWhere('regio', '=', $regio)
                                    ;
                                });
                            }
                        }
                    } else {
                        $query
                            ->where('regio', 'REGEXP', '"[[:<:]]'.$company->regio.'[[:>:]]"')
                            ->orWhere('regio', '=', $company->regio)
                        ;
                    }
                })
                ->where('no_show', '=', 0)
                ->with('media')
                ->take(20)
                ->get()
            ;

            $disabled = array();

            $preferences = Preference::getPreferences();
            
            $attributes = [
                'data-theme' => 'light',
                'data-type' => 'audio',
            ];

            return view('pages/restaurant', [
                'attributes' => $attributes, 
                'companies' => $companies, 
                'preferences' => $preferences, 
                'company' => $company, 
                'media' => $mediaItems,
                'iframe' => $request->has('iframe'),
                'paginationQueryString' => $request->query(),
                'disabled' => $disabled,
                'user' => Sentinel::getUser()
            ]);
        } else {
            App::abort(404);
        }
    }

    public function landingpage($slug)
    {
        $company = Company::where('no_show', 0)
            ->where('slug', $slug)
            ->first()
        ;

        if ($company) {
            $websiteSettings = json_decode(json_encode(Setting::get('website')), true);

            return view('pages/restaurant/landingpage', [
                'websiteSettings' => $websiteSettings,
                'company' => $company,
                'restaurantUrl' => URL::to('restaurant/'.$company['slug'].'?open_popup_res=1'),
            ]);
        } else {
            App::abort(404);
        }
    }

    public function contact($slug, RestaurantRequest $request)
    {
        $company = Company::where('slug', $slug)
            ->where('no_show', 0)    
            ->first()
        ;

        if ($company) {
            $this->validate($request, []);

            $request->session()->flash('contact', 1);
            $request->session()->flash('success_message', 'Uw bericht is succesvol verzonden.  Wij hopen u zo snel mogelijk antwoord te kunnen geven.');

            $data = array(
                'request' => $request,
                'company' => $company
            );

            Mail::send('emails.contact', $data, function ($message) use ($company, $request) {
                $message->to((trim($company->contact_email) == '' ? $company->email : $company->contact_email))->subject($request->input('subject'));
            });

            return Redirect::to('restaurant/'.$slug);
        } else {
            App::abort(404);
        }
    }

    public function reviewsAction(ReviewRequest $request, $slug)
    {
        $company = Company::where('slug', $slug)
            ->where('no_show', 0)    
            ->first()
        ;

        if ($company)  {
            $this->validate($request, []);

            $data = new Review;
            $data->content = $request->input('content');
            $data->food = $request->input('food');
            $data->service = $request->input('service');
            $data->decor = $request->input('decor');
            $data->company_id = $company->id;
            $data->user_id = Sentinel::getUser()->id;
            $data->save();

            $successMessage = 'Voor het plaatsen van uw feedback. Wij waarderen uw mening. U heeft '.$company->name.' beoordeeld met <br /><br />
                                 <strong>Eten</strong><br />
                                 <span class=\'ui star disabled no-rating rating\' data-rating=\''.$request->input('food').'\'></span><br /><br />
                                 <strong>Service</strong><br />
                                 <span class=\'ui star disabled no-rating rating\' data-rating=\''.$request->input('service').'\'></span><br /><br />
                                 <strong>Decor</strong><br />
                                 <span class=\'ui star disabled no-rating rating\' data-rating=\''.$request->input('decor').'\'></span><br /><br />
                                 Klopt dit niet? <a href=\''.url('account/reviews/edit/'.$data->id).'\'>Klik hier om uw recensie aan te passen.</a>';

            Alert::success(preg_replace('/[\n\r]/', '', $successMessage), 'Bedankt')->persistent('Sluiten');

            $mailtemplate = new MailTemplate();
                
            $mailtemplate->sendMail(array(
                'email' =>  Sentinel::getUser()->email,
                'template_id' => 'new-review-client',
                'company_id' => $company->id,
                'replacements' => array(
                    '%name%' => Sentinel::getUser()->name,
                    '%saldo%' => '',
                    '%phone%' => Sentinel::getUser()->phone,
                    '%email%' => Sentinel::getUser()->email,
                    '%date%' => date('d-m-Y', strtotime($data->date)),
                    '%time%' => date('H:i', strtotime($data->time)),
                    '%persons%' => '',
                    '%comment%' => '',
                    '%allergies%' => '',
                    '%preferences%' => ''
                )
            )); 

            $mailtemplate->sendMail(array(
                'email' => $company->email,
                'template_id' => 'new-review-company',
                'company_id' => $company->id,
                'replacements' => array(
                    '%name%' => Sentinel::getUser()->name,
                    '%cname%' => $company->contact_name,
                    '%saldo%' => '',
                    '%phone%' => Sentinel::getUser()->phone,
                    '%email%' => Sentinel::getUser()->email,
                    '%date%' => date('d-m-Y', strtotime($data->date)),
                    '%time%' => date('H:i', strtotime($data->time)),
                    '%persons%' => '',
                    '%comment%' => '',
                    '%allergies%' => '',
                    '%preferences%' => ''
                )
            )); 

            return Redirect::to('restaurant/'.$slug.'#reviews');
        } else {
            App::abort(404);
        }
    }

    public function widgetCalendar($slug)
    {
        $company = Company::where('slug', $slug)
            ->where('no_show', 0)    
            ->first()
        ;

        if ($company) {
            $reservationTimesArray = CompanyReservation::getReservationTimesArray(
                array(
                    'company_id' => array($company->id), 
                    'date' => date('Y-m-d'),
                    'selectPersons' => NULL
                )
            );

            return view('pages/restaurant/widgets/calendar', [
                'company' => $company,
                'reservationTimesArray' => $reservationTimesArray,
            ]);
        } else {
            if (Sentinel::check() && Sentinel::inRole('admin') OR Sentinel::inRole('bedrijf')) {
                return view('pages/restaurant/widgets/error', [
                    'id' => $company->id,
                    'slug' => $slug
                ]);
            }
        }
    }
}