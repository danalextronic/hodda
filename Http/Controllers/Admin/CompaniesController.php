<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyService;
use App\Models\Invoice;
use App\Models\Appointment;
use App\Models\MailTemplate;
use App\Models\TemporaryAuth;
use Sentinel;
use Setting;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Storage;
use Redirect;
use Mail;
use Intervention\Image\ImageManagerStatic as Image;
use File;
use DB;
use Spatie\MediaLibrary\Media;

class CompaniesController extends Controller
{
    
    public function __construct()
    {
        $this->slugController = 'companies';
        $this->section = Sentinel::inRole('admin') ? 'Bedrijven' : 'Bedrijven';
        $this->users = Sentinel::getUserRepository()->whereIn('default_role_id', [2,3])->get();
    }

    public function index(Request $request)
    {
        $data = Company::select(
            'companies.*'
        )
            ->with('media')
            ->leftJoin('users', 'users.id', '=', 'companies.user_id')
        ;

        if ($request->has('q')) {
            $data = $data
                ->where('companies.name', 'LIKE', '%'.$request->input('q'))
                ->orWhere('companies.email', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.address', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.contact_name', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.phone', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.contact_role', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.website', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.city', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies.zipcode', 'LIKE', '%'.$request->input('q').'%')
            ;
        }

        if ($request->has('regio')) {
            $data = $data
                ->whereRaw('companies.regio REGEXP "[[:<:]]'.$request->input('regio').'[[:>:]]"')
                ->orWhere('companies.regio', '=', $request->input('regio'))
            ;
        }

        if ($request->has('city')) {
            $data = $data->where('companies.city', 'LIKE', '%'.$request->input('city').'%');
        }

        if ($request->has('type')) {
            $data = $data->where('companies.company_type', '=', $request->input('type'));
        }
        
        if ($request->has('sort') && $request->has('order')) {
            $companiesColumn = array(
                'updated_at',
                'name',
                'city'
            );

            if (in_array($request->input('sort'), $companiesColumn)) {
                $data = $data->orderBy('companies.'.$request->input('sort'), $request->input('order'));
            } elseif ($request->input('sort') == 'saldoCompany') {
                 $data = $data->orderByRaw('(SELECT 
                        (sum(saldo) - sum(persons))
                    FROM 
                        reservations 
                    WHERE 
                        companies.id = reservations.company_id 
                    AND 
                        reservations.is_cancelled = 0
                    AND 
                        reservations.status IN("present", "reserved")
                    ) '.$request->input('order'));

            } else {
                $data = $data->orderBy($request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('companies.id', 'asc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));
        $data->setPath($this->slugController);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }
        
        $queryString = $request->query();
        unset($queryString['type']);
        unset($queryString['limit']);
        unset($queryString['regio']);

        return view('admin/'.$this->slugController.'/index', [
            'data' => $data,
            'countItems' => $dataCount,
            'slugController' => $this->slugController,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section,
            'currentPage' => 'Overzicht'
        ]);
    }

    public function create()
    {
        return view('admin/'.$this->slugController.'/create', [
            'users' => $this->users,
            'slugController' => $this->slugController,
            'section' => $this->section,
            'currentPage' => 'Nieuw bedrijf'
        ]);
    }

    public function createAction(Request $request)
    {
        $rules = [
            'name' => 'required|unique:companies',
            'email' => 'required|email',
            'contact_name' => 'required',
            'financial_email' => 'email',
            'images' => 'max:6',
            'logo' => 'mimes:jpeg,jpg,png|max:2000'
        ];

        $images = count($request->file('images')) - 1;
        $files  = $request->file('images');

        if ($request->hasFile('images')) {
            foreach (range(0, $images) as $index)  {
                $rule['images.'.$index] = 'required|mimes:jpeg,jpg,png';
            }
        }

        $this->validate($request, $rules);

        //  Create company
        $data = new Company;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');

        if ($request->has('company_type')) {
            $data->company_type = $request->input('company_type');
        }

        $data->company_html = $request->input('company_html');
        $data->contact = $request->input('contact');
        $data->details = $request->input('details');
        $data->menu = $request->input('menu');
        $data->website = $request->input('website');
        $data->contact_name = $request->input('contact_name');
        $data->contact_role = $request->input('contact_role');
        $data->email = $request->input('email');
        $data->contact_phone = $request->input('contact_phone');
        $data->contact_email = $request->input('contact_email');
        $data->phone = $request->input('phone');
        $data->address = $request->input('address');
        $data->zipcode = $request->input('zipcode');
        $data->city = $request->input('city');
        $data->regio = json_encode($request->input('regio'));
        $data->financial_iban = $request->input('financial_iban');
        $data->financial_iban_tnv = $request->input('financial_iban_tnv');
        $data->financial_email = $request->input('financial_email');
        $data->kvk = $request->input('kvk');
        $data->btw = $request->input('btw');

        if ($request->has('waiter')) {
            $data->waiter_user_id = $request->input('waiter');
        }

        if ($request->has('caller')) {
            $data->caller_id = $request->input('caller');
        }

        $user = Sentinel::findByCredentials(array(
            'login' => $request->input('email') 
        ));

        if ($request->input('new_user') == 1 OR !$user) {
            $user = Sentinel::registerAndActivate(array(
                'email' =>  $request->input('email'),
                'password' => 12345678
            ));

            $user->name = $request->input('contact_name');
            $user->phone = $request->input('phone');
            $user->default_role_id = 2;
            $user->save();

            $data->user_id = $user->id;

            $role = Sentinel::findRoleByName('Bedrijf');
            $role->users()->attach($user);
            
            $userId = $user->id;
        } else {
            $data->user_id = $request->input('owner');

            $userId = $request->input('owner');
        }

        $data->start_invoice = $request->input('start_invoice') == 0 ? 0 : 1;
        $data->pref_price = $request->input('pref_price');
        $data->pref_description = $request->input('pref_description');
        $data->website = $request->input('website');
        $data->days = json_encode($request->input('days'));
        $data->save();

        // Create new service

        // MaDiWodo
        $service = new CompanyService();
        $service->name = 'MaDiWoDo';
        $service->price = 8.50;
        $service->company_id = $data->id;
        $service->period = 0;
        $service->content = 'MaDiWoDo';
        $service->tax = 6;
        $service->start_date = date('d-m-Y');
        $service->end_date = '';
        $service->save();

        // Vue Filmvoucher
        $service = new CompanyService();
        $service->name = 'Vue Filmvoucher';
        $service->price = 8.50;
        $service->company_id = $data->id;
        $service->period = 0;
        $service->content = 'Vue Filmvoucher';
        $service->tax = 6;
        $service->start_date = date('d-m-Y');
        $service->end_date = '';
        $service->save();

        $pdfCode = str_random(5);
        $pdfName = $data->id.'-'.str_slug($request->input('name')).$pdfCode.'.pdf';

        if ($request->hasFile('pdf')) {
            foreach($request->file('pdf') as $pdf) {
                $data->addMedia($pdf)->toCollection('documents');
            }
        }

        if ($request->hasFile('logo')) {
            $data->addMedia($request->file('logo'))->toCollection('logo');
        }

        if ($request->hasFile('images'))  {
            foreach($files as $file) {
                $data->addMedia($file)->toMediaLibrary();
            }
        }

        $temporaryAuth = new TemporaryAuth();
        $mailtemplate = new MailTemplate();
        
        $createAuthSignup = $temporaryAuth->createCode($user->id, 'admin/companies/update/'.$data->id.'/'.$data->slug.'?step=1');

        // Send a company sign up mail
        $mailtemplate->sendMailSite(array(
            'email' => $request->input('email'),
            'template_id' => 'appointment_mail',
            'fromEmail' => 'info@uwvoordeelpas.nl',
            'replacements' => array(
                '%name%' => $user->name,
                '%cname%' => $user->name,
                '%email%' => $request->input('email'),
                '%date%' => date('d-m-Y'),
                '%time%' => date('H:i'),
                '%randomPassword%' => 12345678,
                '%url%' => url('auth/set/'.$createAuthSignup)
            )
        ));
            
        MailTemplate::createMailTemplates(array($data->id));

        Alert::success('Dit bedrijf is succesvol aangemaakt.')->persistent('Sluiten');
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id.'/'.$data->slug);
        
    }

    public function update($id, $slug)
    {
        $data = Company::where('id','=', $id);

        if (Sentinel::inRole('bedrijf') && Sentinel::inRole('admin') == FALSE)  {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if ($data) {
            if ($data->click_registration == 0 && $data->user_id == Sentinel::getUser()->id) {
                $data->click_registration = 1;
                $data->save();
            }

            $mediaItems = $data->getMedia('default');
            $documentItems = $data->getMedia('documents');
            $logoItem = $data->getMedia('logo');

            return view('admin/'.$this->slugController.'/update', [
                'users' => $this->users,
                'data' => $data,
                'admin' => Sentinel::inRole('admin'),
                'media' => $mediaItems,
                'documentItems' => $documentItems,
                'logoItem' => $logoItem,
                'slugController' => $this->slugController,
                'section' => $this->section,
                'currentPage' => 'Wijzig bedrijf'
            ]);
        } else {
            Alert::error('Dit bedrijf bestaat helaas niet.')->persistent("Sluiten");
            return Redirect::to('admin/'.$this->slugController);
        }
        
    }

    public function updateAction(Request $request, $id, $slug)
    {
        $data = Company::where('id','=', $id);

        if (Sentinel::inRole('bedrijf') && Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();
        
        if ($data) {
            $rules = [
                'name' => 'required',
                'financial_email' => 'email',
                'images' => 'max:6',
                'logo' => 'mimes:jpeg,jpg,png|max:2000',
            ];

            if ($request->has('step')) {
                $rules['av'] = 'required';
                $rules['signature'] = 'required';
            }

            $images = count($request->file('images')) - 1;
            $files = $request->file('images');

            if ($request->hasFile('images'))  {
                foreach (range(0, $images) as $index) {
                    $rule['images.' . $index] = 'required|mimes:jpeg,jpg,png';
                }

                $rules = array_merge($rules, $rule);
            }

            if ($request->has('signature')) {
                $data->signature_url = $request->input('signature');
            }

            $this->validate($request, $rules);

            $pdfCode = str_random(5);
            $pdfName = $data->id.'-'.str_slug($request->input('name')).$pdfCode.'.pdf';

            $data->slug = str_slug($request->input('name'));
            $data->company_html = $request->input('company_html');
            $data->name = $request->input('name');
            $data->contact = $request->input('contact');
            $data->details = $request->input('details');
            $data->facebook = $request->input('facebook');
            $data->menu = $request->input('menu');
            $data->waiter_user_id = $request->input('waiter');
            $data->caller_id = $request->input('caller');

            if (Sentinel::inRole('admin')) {
                $data->user_id = $request->input('owner');
                $data->clicks = $request->input('clicks');
            }

            if ($request->has('serialize')) {
                $asAssociativeArray = json_decode($request->input('serialize'), true);

                foreach ($asAssociativeArray as $asAssociativeFetch) {
                    foreach ($asAssociativeFetch as $asAssociativeResult) {
                        $orderArray[] = $asAssociativeResult['id'];
                    }
                }

                if (is_array($orderArray)) {
                    Media::setNewOrder($orderArray);
                }
            }

            $data->contact_name  = $request->input('contact_name');
            $data->contact_role  = $request->input('contact_role');
            $data->days = json_encode($request->input('days'));
            $data->email = $request->input('email');
            $data->contact_phone = $request->input('contact_phone');
            $data->contact_email = $request->input('contact_email');
            $data->phone = $request->input('phone');
            $data->address = $request->input('address');
            $data->zipcode = $request->input('zipcode');
            $data->city = $request->input('city');
            $data->regio = json_encode($request->input('regio'));
            $data->financial_iban = $request->input('financial_iban');
            $data->financial_iban_tnv  = $request->input('financial_iban_tnv');
            $data->financial_email = $request->input('financial_email');
            $data->kvk = $request->input('kvk');
            $data->btw = $request->input('btw');
            $data->website = $request->input('website');

            if ($request->has('company_type')) {
                $data->company_type = $request->input('company_type');
            }

            if ($request->has('step')) {
                $data->ip_address = $request->getClientIp();
                $data->os_system = $_SERVER['HTTP_USER_AGENT'];
                $data->signature_date = date('Y-m-d H:i:s');
                $data->no_show = 1;
            } else {
                $data->no_show = $request->input('no_show') == 1 ? 1 : 0;
            }

            $data->pref_price = $request->input('pref_price');
            $data->pref_description = $request->input('pref_description');
            $data->save();

            if ($request->hasFile('images')) {
                foreach($files as $file) {
                    $data->addMedia($file)->toMediaLibrary();
                }
            }

            if ($request->hasFile('logo')) {
                $data->addMedia($request->file('logo'))->toCollection('logo');
            }

            $mediaItems = $data->getMedia();

            if ($request->hasFile('pdf')) {
                foreach($request->file('pdf') as $pdf) {
                    $data->addMedia($pdf)->toCollection('documents');
                }
            }

            Alert::success('Dit bedrijf is succesvol aangepast.')->persistent('Sluiten');

            if ($request->has('step')) {
                $invoicesSettings = json_decode(json_encode(Setting::get('default')), true);
                
                if ($request->has('signature')) {
                    // Remove Appointment
                    Appointment::where('send_mail', '=', 1)
                        ->where('company_id', '=', $data->id)
                        ->delete()
                    ;

                    MailTemplate::createMailTemplates(array($data->id));

                    // Send welcome mail
                    $mailtemplate = new MailTemplate();
                    $mailtemplate->sendMailSite(array(
                        'email' => $data->email,
                        'template_id' => 'welcome',
                        'replacements' => array(
                            '%cname%' => $data->contact_name,
                            '%name%' => $data->contact_name,
                            '%email%' => $data->email
                        )
                    ));
                }

                Alert::success('U bent succesvol ingeschreven. U wordt doorgestuurd naar de volgende stap.')->persistent('Sluiten');
                return Redirect::to('faq/3/restaurateurs?step=2&slug='.$data->slug);
            } else {
                Alert::success('Dit bedrijf is succesvol aangepast.')->persistent('Sluiten');
                
                return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id.'/'.$data->slug);
            }
        } else {
            Alert::error('Dit bedrijf bestaat helaas niet.')->persistent("Sluiten");
            return Redirect::to('admin/'.$this->slugController);
        }
    }

    public function cropImage(Request $request, $slug, $image)
    {
        $data = Company::where('slug','=', $slug);

        if (Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if ($data) {
            switch ($request->input('type')) {
                case 'logo':
                    $type = 'logo';
                    break;
                    
                case 'documents':
                    $type = 'documents';
                    break;

                case 'images':
                    $type = 'default';
                    break;
                
                default:
                    $type = 'default';
                    break;
            }

            $mediaItem = $data->getMedia($type)[$image];
       

            return view('admin/'.$this->slugController.'/crop', [
                'slugController' => $this->slugController,
                'mediaItem' => $mediaItem,
                'section' => $this->section,
                'image' => $image,
                'slug' => $slug,
                'currentPage' => 'Bewerk deze afbeelding'
            ]);
        } else {
            alert()->error('', 'Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent('Sluiten');
            return Redirect::to('admin/companies/update/'.$slug);
        }
    }

    public function cropImageAction(Request $request, $slug, $image)
    {
        $data = Company::where('slug','=', $slug);

        if (Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if ($data) {
            switch ($request->input('type')) {
                case 'logo':
                    $type = 'logo';
                    break;
                    
                case 'documents':
                    $type = 'documents';
                    break;

                case 'images':
                    $type = 'default';
                    break;
                
                default:
                    $type = 'default';
                    break;
            }

            $mediaItem = $data->getMedia($type);
     echo (int) $request->input('width');
            $mediaItem[$image]->manipulations = [
                    'hugeThumb' => [
                    /*
                        'w' => $request->input('width'),
                        'h' => $request->input('height'),
                        'rect' => $request->input('width').','.$request->input('height').','.$request->input('left').','.$request->input('top')
                    */
                        'w' => 286,
                        'h' => 386,
                        'rect' => '586,536,164,0'

                    ]
                ]
            ;

            $mediaItem[$image]->save();

            Alert::success('Deze afbeelding is succesvol aangepast')->persistent('Sluiten');
            /*
            return Redirect::to('admin/companies/crop/image/'.$slug.'/'.$image.'?type='.$request->input('type'));
            */

         } else {
            alert()->error('', 'Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent('Sluiten');
            return Redirect::to('admin/companies/update/'.$slug);
        }
    }

    public function deleteImage(Request $request, $slug, $image)
    {
        $data = Company::where('slug','=', $slug);

        if (Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if ($data) {
            switch ($request->input('type')) {
                case 'logo':
                    $type = 'logo';
                    break;
                    
                case 'documents':
                    $type = 'documents';
                    break;

                case 'images':
                    $type = 'default';
                    break;
                
                default:
                    $type = 'default';
                    break;
            }

            $mediaItems = $data->getMedia($type);
            
            if (isset($mediaItems[$image])) {
                $mediaItems[$image]->delete();
            }

            return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id.'/'.$data->slug);
        } else {
            alert()->error('', 'Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent('Sluiten');
            return Redirect::to('admin/companies/update/'.$slug);
        }
    }

    public function deleteAction(Request $request)
    {
        $data = Company::whereIn('id', $request->input('id'));

        if (Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        switch ($request->input('action')) {
            case 'remove':
                $data->delete();

                Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
                return Redirect::to('admin/'.$this->slugController);
                break;

            case 'reset':
                $mailtemplates = MailTemplate::whereIn('company_id', $request->input('id'));
                $mailtemplates->delete();
                MailTemplate::createMailTemplates($request->input('id'));

                Alert::success('De mailtemplates voor uw gekozen selectie zijn gereset..')->persistent("Sluiten");
                return Redirect::to('admin/'.$this->slugController);
                break;
        }
    }

    public function widgetsIndex()
    {
        $data = Company::all();

        return view('admin/'.$this->slugController.'/widgets-companies', [
            'slugController' => 'widgets',
            'section' => 'Widgets',
            'currentPage' => 'Seleteer widget',
            'companies' => $data
        ]);
    }

    public function widgets($slug)
    {
        $data = Company::where('slug','=', $slug);

        if (Sentinel::inRole('bedrijf') && Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if (count($data) == 1) {
            return view('admin/'.$this->slugController.'/widgets', [
                'slugController' => 'widgets/'.$data->slug,
                'section' => $data->name,
                'company' => $data,
                'companies' => Company::all(),
                'currentPage' => 'Widgets'
            ]);
        } else {
            Alert::error('Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent("Sluiten");
            return Redirect::to('admin/'.$this->slugController);
        }
    }

    public function login($slug)
    {
        $data = Company::where('slug','=', $slug)->first();

        if (count($data) == 1 && trim($data->user_id) != '') {
            if ($data->user_id == 0) {
                Alert::error('Er moet eerst een eigenaar gekoppeld worden aan dit bedrijf. ')->persistent("Sluiten");
                return Redirect::to('admin/'.$this->slugController);
            } else {
                $user = Sentinel::findById($data->user_id);

                Sentinel::login($user);

                Alert::success('U bent succesvol ingelogd op het account van '.$data->name)->persistent('Sluiten');
                return Redirect::to('/');
            }
        } else {
            Alert::error('Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent("Sluiten");
            return Redirect::to('admin/'.$this->slugController);
        }
    }

    public function contract($id, $slug)
    {
        $data = Company::where('id','=', $id);

        if (Sentinel::inRole('bedrijf') && Sentinel::inRole('admin') == FALSE)  {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if (count($data) == 1) {
            $company = new Company();
            return $company->createContract($id);
        } else {
            Alert::error('Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent("Sluiten");
            return Redirect::to('/');
        }
    }

}
