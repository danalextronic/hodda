<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Barcode;
use App\Models\BarcodeUser;
use App\Models\MailTemplate;
use App\Models\Preference;
use App\Models\CompanyReservation;
use App\Helpers\StrHelper;
use App\User;
use Sentinel;
use Activation;
use DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use App\Http\Requests\AdminCreateReservationRequest;
use Redirect;
use Mail;
use Carbon\Carbon;
use Excel;
use Storage;

class GuestsController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'guests';
        $this->section = 'Gasten';
        $this->roles = Sentinel::getRoleRepository()->all();
    }

    public function importGuests($slug)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        return view('admin/'.$this->slugController.'/import', [
            'section' => $this->section, 
            'slugController' => Sentinel::inRole('admin') ? 'admin/users' : $this->slugController.'/'.$slug,
            'slug' => $slug,
            'currentPage' => 'Importeer'
        ]);
    }

    public function importGuestsAction(Request $request, $slug)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $excel = App::make('excel');
        $excel
            ->load($request->file('csv')->getRealPath(), function($reader) use($companyOwner) {
                $reader->each(function($sheet) use($companyOwner)  {
                    $sheet->each(function($row, $keys) use($companyOwner)  {
                        if ($row->email != NULL && $row->name != NULL) {
                            $user = Sentinel::findByCredentials(array(
                                'login' => $row->email
                            ));

                            $randomPassword = str_random(20);

                            if (!$user) {
                                $newUser = Sentinel::registerAndActivate(array(
                                    'email' => $row->email,
                                    'password' => $randomPassword
                                ));

                                $newUser->name = $row->name;
                                $newUser->phone = $row->phone;
                                $newUser->gender = $row->gender;
                                $newUser->source = 'newsletter';
                                $newUser->expire_code = str_random(64);

                                if ($companyOwner['regio'] != NULL) {
                                    $newUser->city = json_encode((array) $companyOwner['regio']);
                                }

                                $newUser->save();

                                $guest = new Guest();
                                $guest->addGuest(array(
                                    'user_id' => $newUser->id,
                                    'company_id' => $companyOwner['company_id']
                                ));

                                $mailtemplate = new MailTemplate();
                                $mailtemplate->sendMailSite(array(
                                    'email' => $row->email,
                                    'template_id' => 'register',
                                    'replacements' => array(
                                        '%name%' => $row->name,
                                        '%email%' => $row->email,
                                        '%phone%' => $row->phone,
                                        '%randomPassword%' => $randomPassword,
                                        '%randompassword%' => $randomPassword,
                                        '%url%' => \URL::to('auth/set/'.$newUser->expire_code)
                                    )
                                ));
                            } else {
                                $guest = new Guest();
                                $guest->addGuest(array(
                                    'user_id' => $user->id,
                                    'company_id' => $companyOwner['company_id']
                                ));
                            }
                        }
                    });
                });
            })
        ;
    }

    public function exportGuests(Request $request, $slug)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);
       
        if($companyOwner['is_owner'] == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $data = Guest::select(
            'users.name',
            'users.email',
            'users.phone',
            'users.gender'
        )
            ->leftJoin('users', 'users.id', '=', 'guests.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'guests.company_id')
            ->whereNotNull('users.id')
            ->where('companies.slug', '=',$slug)
            ->groupBy('users.id')
            ->get()
            ->toArray()
        ;

        $excel = App::make('excel');

        $excel
            ->create('export-gasten-'.$slug, function($excel) use($data) {
                $excel->sheet('Gasten UwVoordeelpas', function($sheet) use($data) {
                    $sheet->fromArray($data);
                });
            })
            ->export('csv')
        ;
    }

    public function index(Request $request, $slug)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);
       
        if ($companyOwner['is_owner'] == FALSE && Sentinel::inRole('admin') == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $preferences = new Preference();
        $regio = $preferences->getRegio();

        $data = Guest::select(DB::raw(
            'users.id,
            users.name,
            users.email,
            users.phone,
            users.gender,
            users.city,
            users.created_at,
            users.updated_at,
            users.saldo,
            guests.user_id,
            ( 
                SELECT count(barcodes_users.id)
                FROM barcodes_users 
                LEFT JOIN barcodes ON barcodes.code = barcodes_users.code
                WHERE
                    (
                        barcodes.expire_date != NULL
                        AND barcodes.expire_date >= date(now())
                    )
                OR 
                    (date(date_add(barcodes_users.created_at, interval 1 year)) >= date(now()))
                AND
                    barcodes_users.user_id = users.id
                AND
                    companies.id = barcodes_users.company_id
            ) as discountCard,
            ( SELECT count(id) FROM reservations WHERE user_id = users.id AND company_id = companies.id AND newsletter_company = 1) as newsletter,
            ( SELECT date FROM reservations WHERE user_id = users.id AND company_id = companies.id ORDER BY created_at DESC LIMIT 1) as last_reservation,
            ( SELECT time FROM reservations WHERE user_id = users.id AND company_id = companies.id  ORDER BY created_at DESC LIMIT 1) as last_reservation_time'
        ))
            ->leftJoin('users', 'users.id', '=', 'guests.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'guests.company_id')
            ->where('companies.slug', '=',$slug)
        ;

        if($request->has('q')) {
            $data->where(function ($query) use($request) {
                $query
                    ->where('users.name', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('users.email', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('users.phone', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('users.saldo', 'LIKE', '%'.$request->input('q').'%')
                ;
            });
        }

        if($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy('users.'.$request->input('sort'), $request->input('order'));
            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('users.id', 'desc');
        }
        
        if ($request->has('city')) {
            if (is_array($request->input('city'))) {
                foreach ($request->input('city') as $option) {
                    if (count($request->input('city')) >= 2) {
                        $data = $data->orWhere('users.city', 'REGEXP', '"([^"]*)'.$regio['regioNumber'][$option].'([^"]*)"');
                    } else {
                        $data = $data->where('users.city', 'REGEXP', '"([^"]*)'.$regio['regioNumber'][$option].'([^"]*)"');
                    }
                }
            }
        }
        
        if ($request->has('preferences')) {
            if (is_array($request->input('preferences'))) {
                foreach ($request->input('preferences') as $option) {
                    if (count($request->input('preferences')) >= 2) {
                        $data = $data->orWhere('users.preferences', 'REGEXP', '"([^"]*)'.$option.'([^"]*)"');
                    } else {
                        $data = $data->where('users.preferences', 'REGEXP', '"([^"]*)'.$option.'([^"]*)"');
                    }
                }
            }
        }

        if ($request->has('allergies')) {
            if (is_array($request->input('allergies'))) {
                foreach ($request->input('allergies') as $option) {
                    if (count($request->input('allergies')) >= 2) {
                        $data = $data->orWhere('users.allergies', 'REGEXP', '"([^"]*)'.$option.'([^"]*)"');
                    } else {
                        $data = $data->where('users.allergies', 'REGEXP', '"([^"]*)'.$option.'([^"]*)"');
                    }
                }
            }
        }

        if($request->input('newsletter') == 1) {
            $data = $data->whereRaw('( SELECT count(id) FROM reservations WHERE reservations.user_id = users.id AND reservations.company_id = companies.id AND newsletter_company = 1) >= 1');
        } elseif($request->has('newsletter') && $request->input('newsletter') == 0) {
            $data = $data->whereRaw('( SELECT count(id) FROM reservations WHERE reservations.user_id = users.id AND reservations.company_id = companies.id AND newsletter_company = 1) = 0');
        }

        $data = $data->groupBy('users.id');

        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }
        
        $queryString = $request->query();
        unset($queryString['limit']);

        $companyInfo = Company::select(
            'preferences',
            'allergies'
        )
            ->where('slug', $slug)
            ->first()
        ;

        return view('admin/'.$this->slugController.'/index', [
            'companyInfo' => $companyInfo, 
            'data' => $data, 
            'regio' => $regio['regio'], 
            'countItems' => $dataCount, 
            'slugController' => (Sentinel::inRole('admin') ? 'users' :  'guests/'.$slug),
            'searchPath' => 'guests/'.$slug,
            'slugParam' => Sentinel::inRole('admin') == FALSE ? '/'.$slug : '',
            'slug' => $slug,
            'reservation' => new Reservation,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function createReservation($slug)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);
        $company = Company::find($companyOwner['company_id']);

        if($companyOwner['is_owner'] == FALSE)
        {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $guests = Sentinel::getUserRepository()
            ->leftJoin('guests', 'users.id', '=', 'guests.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'guests.company_id')
            ->select(
                DB::raw(
                    'users.id,
                     users.name,
                     users.phone,
                     users.email'
                )
            )
            ->where('company_id', $companyOwner['company_id'])
            ->get()
        ;

        return view('admin/'.$this->slugController.'/create_reservation', [
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'slug' => $slug,
            'currentPage'  => 'Nieuwe reservering',
            'guests' => $guests,
            'slugParam' => '/'.$slug,
            'company' => $company,
            'roles' => $this->roles
        ]);
    }

    public function createReservationAction(AdminCreateReservationRequest $request, $slug)
    {   
        $this->validate($request, []);

        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $time = date('H:i', strtotime($request->input('time')));
        $date = date('Y-m-d', strtotime($request->input('date')));

        $reservationTimes = CompanyReservation::getReservationTimesArray(
            array(
                'company_id' => array($companyOwner['company_id']), 
                'date' => $date, 
                'selectPersons' => $request->input('persons')
            )
        );
        
        if (isset($reservationTimes[$time])) {
            if (trim($request->input('email')) != '') {
                $user = Sentinel::findByCredentials(array(
                    'login' => $request->input('email')
                ));

                if (!$user) {
                    $randomPassword = str_random(20);

                    $user = Sentinel::register(array(
                        'email' =>  $request->input('email'),
                        'password' =>  $randomPassword
                    ));

                    $user->name = $request->input('name');
                    $user->phone = $request->input('phone');

                    if ($request->input('newsletter') == 1) {
                        $user->newsletter = 1;
                    }

                    $user->save();

                    Mail::send(
                        'emails.noactivation', 
                        array(
                            'randomPassword' => $randomPassword,
                            'data' => $user
                        ),
                        function ($message) use ($user) {
                            $message
                                ->subject('Registratie bij UwVoordeelpas')
                                ->to($user->email)
                            ;
                        }
                    );
                }

                // Add as guests
                $guest = new Guest();
                $guest->addGuest(array(
                    'user_id' => $user->id,
                    'company_id' => $companyOwner['company_id']
                ));
            }

            // Add a reservation
            $data  = new Reservation;
            $data->date = date('Y-m-d', strtotime($request->input('date')));
            $data->time = date('H:i', strtotime($request->input('time'))).':00';
            $data->persons = $request->input('persons');
            $data->company_id = $companyOwner['company_id'];
            $data->user_id = (trim($request->input('email')) == '' ? 0 : $user->id);
            $data->name = $request->input('name');
            $data->email = $request->input('email');
            $data->phone  = $request->input('phone');
            $data->comment = $request->input('comment');
            $data->newsletter_company = ($request->input('newsletter') == '' ? 0 : 1);
            $data->allergies  = json_encode($request->input('allergies'));
            $data->preferences = json_encode($request->input('preferences'));
            $data->status = 'iframe';
            $data->save();
            
            $date = Carbon::create(date('Y', strtotime($request->input('date'))), date('m', strtotime($request->input('date'))), date('d', strtotime($request->input('date'))), 0, 0, 0);

            // Send mail part
            if (trim($request->input('email')) != '') {
                $mailtemplate = new MailTemplate();
                
                // Send mail to user
                $mailtemplate->sendMail(array(
                    'email' => $data->email,
                    'reservation_id' => $data->id,
                    'template_id' => 'new-reservation-company',
                    'company_id' => $companyOwner['company_id'],
                    'replacements' => array(
                        '%name%' => $data->name,
                        '%cname%' => '',
                        '%saldo%' => $data->saldo,
                        '%phone%' => $data->phone,
                        '%email%' => $data->email,
                        '%date%' => date('d-m-Y', strtotime($data->date)),
                        '%time%' => date('H:i', strtotime($data->time)),
                        '%persons%' => $data->persons,
                        '%comment%' => $data->comment,
                        '%allergies%' => ($request->has('allergies') ? implode(",", json_decode($data->allergies)) : ''),
                        '%preferences%' => ($request->has('preferences') ? implode(",", json_decode($data->preferences)) : '')
                    )
                ));
            }

            Alert::success(
                'U heeft succesvol een reservering geplaatst.',
                 'Bedankt!'
            )->persistent('Sluiten');

            return Redirect::to('admin/guests/create-reservation/'.$slug);
        } else {
            Alert::error('Het is niet mogelijk om op dit tijdstip te reserveren of er zijn geen plaatsen beschikbaar.')->persistent('Sluiten');
            
            return Redirect::to('admin/guests/create-reservation/'.$slug);
        }
    }
    
    public function create($slug)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $barcodeCompany = Barcode::select(
            'barcodes.company_id',
            'barcodes.id',
            'barcodes.code'
        )
            ->leftJoin('barcodes_users', 'barcodes_users.barcode_id', '=', 'barcodes.id')
            ->orWhere('barcodes.is_active', 1)
            ->whereNull('barcodes_users.user_id')
            ->where('barcodes.company_id', $companyOwner['company_id'])
            ->lists('barcodes.code', 'barcodes.id')
        ;

        return view('admin/'.$this->slugController.'/create', [
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'barcodeCompany' => $barcodeCompany, 
            'slug' => $slug,
            'currentPage' => 'Nieuwe gast',
            'companyId' => $companyOwner['company_id'],
            'slugParam' => '/'.$slug,
            'roles' => $this->roles
        ]);
    }

    public function createAction(Request $request, $slug)
    {
        $this->validate($request, [
            'name' => 'required',
            'password' => 'min:8|confirmed',
            'email' => 'required|email'
        ]);

        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        // Check if the user already exists
        $userCheck = Sentinel::findByCredentials(array('login' => $request->input('email')));
       
        if (count($userCheck) == 0) {
            // Add user
            $user = Sentinel::registerAndActivate(array(
                'email' =>  $request->input('email'),
                'password' => $request->has('password') ? $request->input('password') : '123456789'
            ));

            $user->name = $request->input('name');
            $user->phone = $request->input('phone');
            $user->gender = $request->input('gender');
            $user->birthday_at = $request->input('birthday_at');
            $user->city = $request->input('city');
            $user->facilities = json_encode($request->input('facilities'));
            $user->kitchens = json_encode($request->input('kitchens'));
            $user->allergies = json_encode($request->input('allergies'));
            $user->sustainability = json_encode($request->input('sustainability'));
            $user->kids = json_encode($request->input('kids'));
            $user->price = json_encode($request->input('price'));
            $user->preferences = json_encode($request->input('preferences'));
            $user->discount = json_encode($request->input('discount'));

            if ($request->has('role')) {
                switch ($request->input('role')) {
                    case 2:
                        $role = Sentinel::findRoleByName('Bedrijf');
                        $role->users()->attach($user);
                        break;
                    
                    case 3:
                        $role = Sentinel::findRoleByName('Admin');
                        $role->users()->attach($user);
                        break;
                }
            }

            $user->save();

            if ($request->has('barcode_user')) {
                $checkBarcode = Barcode::where('company_id', $companyOwner['company_id'])
                    ->where('id', $request->input('barcode_user'))
                    ->where('is_active', 1)
                    ->first()
                ;

                if (count($checkBarcode) == 1) {
                    $barcodeUser = new BarcodeUser();
                    $barcodeUser->code = $checkBarcode->code;
                    $barcodeUser->barcode_id = $request->input('barcode_user');
                    $barcodeUser->company_id = $companyOwner['company_id'];
                    $barcodeUser->user_id = $user->id;
                    $barcodeUser->is_active = 1;
                    $barcodeUser->save();
                }
            }
        }

        // Add as guest
        $guest = new Guest();
        $guest->addGuest(array(
            'user_id' => (isset($user) ? $user->id : $userCheck->id),
            'company_id' => $companyOwner['company_id']
        ));

        Alert::success('Deze gast is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/'.$slug);
    }

    public function deleteAction(Request $request, $slug)
    {
        if ($request->has('id')) {
            $company = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

            $guest = new Guest();

            foreach ($request->input('id') as $id) {
                $guest->deleteGuest(array(
                    'user_id' => $id,
                    'company_id' => $company['company_id']
                ));
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController.'/'.$slug);
    }
}