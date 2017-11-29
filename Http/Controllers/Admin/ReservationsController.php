<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App;
use App\User;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyReservation;
use App\Models\Content;
use App\Models\Preference;
use App\Models\GuestThirdParty;
use App\Models\MailTemplate;
use App\Models\Reservation;
use App\Models\Invoice;
use Illuminate\Pagination\LengthAwarePaginator;
use Config;
use Sentinel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use PDF;
use Redirect;
use DB;
use URL;

class ReservationsController extends Controller 
{
    
    public static $per_time = array(
        15 => '15 minutes', 
        30 => '30 minutes', 
        60 => '1 hour'
    );

    public function __construct(Request $request)
    {
        setlocale(LC_ALL, 'nl_NL.ISO8859-1');
        setlocale(LC_TIME, 'nl_NL.ISO8859-1');
        setlocale(LC_TIME, 'Dutch');

        $this->slugController = 'reservations';
        $this->section = 'Reserveringen';
        $this->companies = Company::lists('name', 'id');
        $this->limit = $request->input('limit', 15);
        $this->months = Config::get('preferences.months');
    }

    public function index(Request $request, $slug = null) 
    {
        $limit = $request->input('limit', 15);

        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        $data = CompanyReservation::select(
            'companies.id', 
            'companies.name as company_name',
            'company_reservations.start_time', 
            'company_reservations.end_time',  
            'company_reservations.per_time',  
            'company_reservations.id as res_id',  
            'company_reservations.company_id', 
            'company_reservations.locked_times', 
            'company_reservations.available_persons',
            'company_reservations.date as company_reservation_date',
            'company_reservations.is_locked', 
            'company_reservations.date', 
            DB::raw('SUM(reservations.persons) as persons')
        )
            ->leftJoin('companies', 'company_reservations.company_id', '=', 'companies.id')
            ->leftJoin('reservations', function ($join) {
                $join
                    ->on('companies.id', '=', 'reservations.company_id')
                    ->on('company_reservations.date', '=', 'reservations.date')
                    ->where('reservations.is_cancelled', '=', 0)
                ;
            })
            ->where('company_reservations.date', '>=', date('Y-m-d'))
            ->groupBy('company_reservations.id')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            if (Sentinel::inRole('bedrijf')) {
                $data = $data->where('companies.user_id', '=', Sentinel::getUser()->id);
            } elseif (Sentinel::inRole('bediening')) {
                $data = $data->where('companies.waiter_user_id', '=', Sentinel::getUser()->id);
            }
        }
                        
        if ($request->has('q')) {
            $data = $data->where('company_reservations.date', '=', date('Y-m-d', strtotime($request->input('q'))));
        }

        if ($request->has('date')) {
            $data = $data->where('company_reservations.date', '=', date('Y-m-d', strtotime($request->input('date'))));
        }

        if ($request->has('dayno')) {
            $daynoArray = implode(',', $request->input('dayno'));
            $data = $data->whereRaw('weekday(company_reservations.date) IN('.$daynoArray.')');
        }

        if ($request->has('time')) {
            $i = 0;

            $data = $data->where(function ($query) use($request, $i) {
                foreach ($request->input('time') as $time) {
                    $i++;

                    if ($i == 1) {
                        $query->where('available_persons', 'REGEXP', '"([^"]*)'.date('H:i', strtotime($time)).'([^"]*)"');
                    } else {
                        $query->orWhere('available_persons', 'REGEXP', '"([^"]*)'.date('H:i', strtotime($time)).'([^"]*)"');
                    }
                }
            });
        }

        if (trim($slug) != '') {
            $data = $data->where('companies.slug', $slug);
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));
            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('company_reservations.date', 'asc');
        }

        if (isset($data) && $companyOwner['is_owner'] == TRUE || Sentinel::inRole('admin')) {
            $data = $data->paginate($limit);
            $data->setPath((trim($slug) != '' ? $slug : ''));

            # Redirect to last page when page don't exist
            if ($request->input('page') > $data->lastPage()) { 
                $lastPageQueryString = json_decode(json_encode($request->query()), true);
                $lastPageQueryString['page'] = $data->lastPage();

                return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
            }

            $queryString = $request->query();
            unset($queryString['limit']);

            $getTimes = CompanyReservation::getAllTimes();
            sort($getTimes);

            return view('admin/'.$this->slugController.'/index', [
                'times' => $getTimes, 
                'data' => $data, 
                'admin' => Sentinel::inRole('admin'),
                'companies' => Company::all(),
                'owner' => $companyOwner,
                'carbon' => new Carbon,
                'slug' => $slug != null ? '/'.$slug : '',
                'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $limit,
                'section' => $this->section, 
                'currentPage' => 'Overzicht'
            ]);
        } else {
            App::abort(404);
        }
    }

    public function listSaldo(Request $request, $company = null)
    {
        $reservations = Reservation::select(
            DB::raw('month(reservations.date) as month'),
            DB::raw('year(reservations.date) as year'),
            'reservations.name',
            'reservations.restaurant_is_paid',
            'reservations.email',
            'reservations.phone',
            'reservations.saldo',
            'reservations.persons',
            'reservations.user_id',
            'reservations.date',
            'reservations.time',
            'companies.price_per_guest',
            'companies.slug as companySlug',
            'companies.name as companyName'
        )
            ->leftJoin('users', 'reservations.user_id', '=', 'users.id') 
            ->leftJoin('companies', 'reservations.company_id', '=', 'companies.id')
            ->where('reservations.is_cancelled', 0)
            ->whereIn('reservations.status', array('reserved', 'present'))
        ;

        if ($company != NULL) {
            $reservations = $reservations->where('companies.slug', '=', $company);
        }

        if ($request->has('user')) {
            $reservations = $reservations->where('reservations.user_id', '=', $request->input('user'));
        }

        if ($request->has('source')) {
                switch ($request->input('source')) {
                    case 'wifi':
                        $reservations = $reservations
                            ->leftJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email') 
                            ->whereNotNull('guests_wifi.email')
                            ->whereNotNull('guests_wifi.name')
                        ;
                    break;
                        
                default:
                    $reservations = $reservations
                        ->where('reservations.source', '=', $request->input('source'))
                    ;
                break;
            }
        }

        if ($request->has('saldo') && $request->input('saldo') == 1) {
            $reservations = $reservations->where('reservations.saldo', '>', 0);
        }

        if ($request->has('saldo') && $request->input('saldo') == 0) {
            $reservations = $reservations->where('reservations.saldo', '=', 0);
        }

        if ($request->has('city')) {
            $preferences = new Preference();
            $regio = $preferences->getRegio();

            $regioName = $request->input('city');

            if (isset($regio['regioNumber'][$regioName])) {
                $reservations = $reservations->whereNotNull(
                    'users.city'
                )
                    ->where('users.city', 'REGEXP', '"([^"]*)'.$regio['regioNumber'][$regioName].'([^"]*)"')
                ;
            }
        }

        if ($request->has('caller_id')) {
            $reservations = $reservations->where('companies.caller_id', '=', $request->input('caller_id'));
        }

        if (Sentinel::inRole('callcenter')) {
            $reservations = $reservations->where('companies.caller_id', '=', Sentinel::getUser()->id);
        } else if (Sentinel::inRole('admin') == FALSE && $company != NULL) {
            $reservations = $reservations
                ->where('companies.user_id', '=', Sentinel::getUser()->id)
                ->orWhere('companies.waiter_user_id', '=', Sentinel::getUser()->id)
            ;
        }

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            if ($request->input('sort') == 'date')  {
                 $reservations = $reservations->orderBy(
                    'reservations.date', $request->input('order')
                )
                    ->orderBy('reservations.time', $request->input('order'))
                ;
            } else {
                $reservations = $reservations->orderBy($request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $reservations = $reservations->orderBy(
                'reservations.date', 'desc'
            )
                ->orderBy('reservations.time', 'desc')
            ;
        }

        # Get all months and years
        foreach($reservations->get() as $reservation) {
            $selectMonths[$reservation->month] = $this->months[$reservation->month];
            $selectYears[$reservation->year] = $reservation->year;
            $companyName = $reservation->companyName;
        }

        $totalPersons = 0;
        $pricePerGuest = 1;
        $totalSaldo = 0;

        # Filter by month and year
        if ($request->has('month') && $request->has('year')) {
            $reservations = $reservations
                ->whereMonth('reservations.date', '=', $request->input('month'))
                ->whereYear('reservations.date', '=', $request->input('year'))
            ;

            # Get invoices
            $invoices = Invoice::select(
                'invoices.id',
                'invoices.week'
            )
                ->leftJoin('companies', 'invoices.company_id', '=', 'companies.id')   
                ->whereMonth('invoices.date', '=', $request->input('month'))
                ->whereYear('invoices.date', '=', $request->input('year'))
                ->where('companies.slug', $company)
                ->get()
            ;
        }
        
        # Count total persons and saldo
        foreach($reservations->get() as $reservation) {
            $pricePerGuest = $reservation->price_per_guest;
            $totalPersons += $reservation->persons;
            $totalSaldo += $reservation->saldo;
        }
        
        $reservations = $reservations->paginate($this->limit);

        if ($request->input('page') > $reservations->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $reservations->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);
        unset($queryString['saldo']);
        unset($queryString['source']);
        unset($queryString['city']);
        unset($queryString['caller_id']);

        $role = Sentinel::findRoleBySlug('callcenter');
        $callcenterUsers = $role->users()->with('roles')->get();

        return view('admin/'.$this->slugController.'/saldo', [
            'data' => $reservations, 
            'callcenterUsers' => $callcenterUsers, 
            'company' => $company, 
            'months' => $this->months, 
            'filterCompanies' => Company::select('id', 'slug', 'name')->get(),
            'slugController' => 'reservations/saldo/'.$company,
            'pricePerGuest' =>  $pricePerGuest,
            'totalPersons' => isset($totalPersons) ? $totalPersons : 0,
            'totalSaldo' => isset($totalSaldo) ? $totalSaldo : 0,
            'selectMonths' => isset($selectMonths) ? $selectMonths : array(),
            'selectYears' => isset($selectYears) ? $selectYears : array(),
            'invoices' => isset($invoices) ? $invoices : '',
            'companyName' => isset($companyName) ? $companyName : '',
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $this->limit
        ]);
    }

    public function listClients(Request $request, $companyId = null, $date = null)
    {
        $preferences = new Preference();

        $regio = $preferences->getRegio();
        $regioName = $request->input('city');

        $statistics = Reservation::select(
            DB::raw('(SELECT 
                            count(reservations.id) 
                        FROM 
                            reservations
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'LEFT JOIN companies ON companies.id = reservations.company_id' : '').'
                        WHERE
                            reservations.status IN ("reserved", "reserved-pending", "reserved-present", "present", "iframe", "iframe-pending", "iframe-reserved", "iframe-present")
                        '.($companyId != NULL ? 'AND reservations.company_id = "'.$companyId.'"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'AND (companies.regio REGEXP "[[:<:]]'.$regio['regioNumber'][$regioName].'[[:>:]]"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'OR companies.regio = "'.$regio['regioNumber'][$regioName].'")' : '').'
                        '.($request->input('date') != NULL ? 'AND reservations.date = date("'.$request->input('date').'")' : '').'
                    ) AS allReservations'),

            DB::raw('(
                        SELECT 
                            count(reservations.id) 
                        FROM 
                            reservations
                            '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'LEFT JOIN companies ON companies.id = reservations.company_id' : '').'

                        WHERE
                            is_cancelled = 1
                        '.($companyId != NULL ? 'AND reservations.company_id = "'.$companyId.'"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'AND (companies.regio REGEXP "[[:<:]]'.$regio['regioNumber'][$regioName].'[[:>:]]"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'OR companies.regio = "'.$regio['regioNumber'][$regioName].'")' : '').'
                        '.($request->input('date') != NULL ? 'AND reservations.date = date("'.$request->input('date').'")' : '').'
                    ) AS cancelledReservations'),
            DB::raw('(
                        SELECT 
                            count(reservations.id) 
                        FROM 
                            reservations
                            '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'LEFT JOIN companies ON companies.id = reservations.company_id' : '').'
                        WHERE
                           source IN ("couverts", "seatme", "eetnu")
                        '.($companyId != NULL ? 'AND reservations.company_id = "'.$companyId.'"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'AND (companies.regio REGEXP "[[:<:]]'.$regio['regioNumber'][$regioName].'[[:>:]]"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'OR companies.regio = "'.$regio['regioNumber'][$regioName].'")' : '').'
                        '.($request->input('date') != NULL ? 'AND reservations.date = date("'.$request->input('date').'")' : '').'                        '.($companyId != NULL ? 'AND company_id = "'.$companyId.'"' : '').'
                        '.($request->input('date') != NULL ? 'AND date = date("'.$request->input('date').'")' : '').'
                    ) AS thirdPartyReservations'),
            DB::raw('(
                        SELECT 
                            count(reservations.id) 
                        FROM 
                            reservations
                            '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'LEFT JOIN companies ON companies.id = reservations.company_id' : '').'
                        WHERE
                            status IN ("iframe", "iframe-pending", "iframe-reserved", "iframe-present")
                        '.($companyId != NULL ? 'AND reservations.company_id = "'.$companyId.'"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'AND (companies.regio REGEXP "[[:<:]]'.$regio['regioNumber'][$regioName].'[[:>:]]"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'OR companies.regio = "'.$regio['regioNumber'][$regioName].'")' : '').'
                        '.($request->input('date') != NULL ? 'AND reservations.date = date("'.$request->input('date').'")' : '').'
                    ) AS iframeReservations'),
            DB::raw('(
                        SELECT 
                            count(reservations.id) 
                        FROM 
                            reservations
                            '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'LEFT JOIN companies ON companies.id = reservations.company_id' : '').'
                        WHERE
                            status = "no_show"
                        '.($companyId != NULL ? 'AND reservations.company_id = "'.$companyId.'"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'AND (companies.regio REGEXP "[[:<:]]'.$regio['regioNumber'][$regioName].'[[:>:]]"' : '').'
                        '.($request->has('city') && isset($regio['regioNumber'][$regioName]) ? 'OR companies.regio = "'.$regio['regioNumber'][$regioName].'")' : '').'
                        '.($request->input('date') != NULL ? 'AND reservations.date = date("'.$request->input('date').'")' : '').'
                    ) AS noShowReservations')
        );  

        if ($companyId != NULL) {
            $statistics = $statistics->where('reservations.company_id', $companyId);
        }

        $statistics = $statistics->first();

        $data = Reservation::select(
            'companies.name as companyName', 
            'companies.slug as companySlug', 
            'companies.id as companyId', 
            'companies.user_id as owner', 
            'companies.days', 
            'companies.discount', 
            'reservations.*',
            'barcodes_users.created_at as barcodeDate'
        )
            ->leftJoin('companies', 'reservations.company_id', '=', 'companies.id')
            ->leftJoin('users', 'reservations.user_id', '=', 'users.id') 
            ->leftJoin('barcodes_users', function($join) {
                $join
                    ->on('barcodes_users.company_id', '=', 'companies.id')
                    ->on('barcodes_users.user_id', '=', 'reservations.user_id')
                ;
            })
        ;  

        if ($request->has('source')) {
            switch ($request->input('source')) {
                case 'wifi':
                    $data = $data
                        ->leftJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email') 
                        ->whereNotNull('guests_wifi.email')
                        ->whereNotNull('guests_wifi.name')
                    ;
                break;
                        
                default:
                    $data = $data
                        ->where('reservations.source', '=', $request->input('source'))
                    ;
                break;
            }
        }

        if ($request->has('city')) {
            if (isset($regio['regioNumber'][$regioName])) {
                $data = $data
                    ->where('companies.regio', 'REGEXP', '"[[:<:]]'.$regio['regioNumber'][$regioName].'[[:>:]]"')
                    ->orWhere('companies.regio', '=', $regio['regioNumber'][$regioName])
                ;
            }
        }

        if (Sentinel::inRole('admin') == FALSE) {
            if (Sentinel::inRole('bedrijf')) {
                $data = $data->where('companies.user_id', '=', Sentinel::getUser()->id);
            } elseif (Sentinel::inRole('bediening')) {
                $data = $data->where('companies.waiter_user_id', '=', Sentinel::getUser()->id);
            }
        }

        if ($companyId != NULL) {
            $data = $data->where('reservations.company_id', $companyId);
        }

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            if ($request->has('time')) {
                $data = $data->orderBy('date, time', $request->input('order'));
            } else {
                $data = $data->orderBy($request->input('sort'), $request->input('order'));
            }
            
            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            if ($request->has('date')) {
                $data = $data
                    ->where('reservations.date', date('Y-m-d', strtotime($request->input('date'))))
                    ->orderBy('reservations.time', 'asc')
                ;
            } else {
                $data = $data->orderBy('reservations.created_at', 'desc');
            }
         }

        if ($request->has('status')) {
            if ($request->input('status') == 'iframe') {
                $data = $data->whereIn('reservations.status', array('iframe', 'iframe-pending', 'iframe-reserved', 'iframe-present'));
            } elseif ($request->input('status') == 'email') {
                $data = $data->whereIn('reservations.source', array('couverts', 'seatme', 'eetnu'));
            } elseif ($request->input('status')) {
                $data = $data->where('reservations.status', $request->input('status'));
            }
        } else {
            $data = $data->whereIn('reservations.status', array('reserved', 'reserved-pending', 'reserved-present', 'present', 'iframe', 'iframe-pending', 'iframe-reserved', 'iframe-present'));
        }

        $data = $data
            ->where('reservations.is_cancelled', ($request->has('cancelled') ? 1 : 0))
            ->paginate($this->limit)
        ;

        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        if ($companyId != null) {
            $companyInfo = Company::where('id', '=', $companyId);
        }

        if (Sentinel::inRole('admin') == FALSE && Sentinel::inRole('bediening')) {
            $companyInfo = $companyInfo->orWhere('waiter_user_id', Sentinel::getUser()->id);
        } else if (Sentinel::inRole('admin') == FALSE && Sentinel::inRole('bedrijf')) {
            $companyInfo = $companyInfo->where('user_id', Sentinel::getUser()->id);
        }
        
        if ($companyId != null) {
            $companyInfo = $companyInfo->first();
        }

        if (isset($companyInfo) || $companyId == null) {
            $queryString = $request->query();
            unset($queryString['limit']);
            unset($queryString['source']);
            unset($queryString['city']);

            return view('admin/'.$this->slugController.'/clients', [
                'data' => $data, 
                'date' => $date, 
                'companyInfo' => isset($companyInfo) ? $companyInfo : '',
                'company' => $companyId,
                'filterCompanies' => Company::select('id', 'slug', 'name')->get(),
                'companyParam' => (isset($companyInfo) && trim($companyInfo['slug']) != '' ? '/'.$companyInfo['slug'] : ''),
                'slugController' => 'reservations/clients/'.$companyId.(trim($date) != null ? '/'.$date : ''),
                'sessionSort' => $request->session()->get('sort'),
                'sessionOrder' => $request->session()->get('order'),
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $this->limit,
                'statistics' => $statistics
            ]);
        } else  {
            App::abort(404);
        }
    }

    public function listClientsAction(Request $request, $company = NULL, $date = NULL)
    {
        $reservationInfo = Reservation::join('companies', 'reservations.company_id', '=', 'companies.id')
            ->join('company_reservations', 'company_reservations.id', '=', 'reservations.reservation_id')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            if (Sentinel::inRole('bedrijf')) {
                $reservationInfo = $reservationInfo->where('companies.user_id', '=', Sentinel::getUser()->id);
            } elseif (Sentinel::inRole('bediening')) {
                $reservationInfo = $reservationInfo->where('companies.waiter_user_id', '=', Sentinel::getUser()->id);
            }
        }

        $reservationInfo = $reservationInfo
            ->where('reservations.id', $request->input('reservationId'))
            ->first()
        ;

        $mailtemplate = new MailTemplate();

        if ($reservationInfo) {
            $reservation = Reservation::find($request->input('reservationId'));

            switch ($request->input('reservationSubmit')) {
                case 'refused':
                    $reservation->status = 'refused';

                    $user = Sentinel::findById($reservation->user_id);
                    
                    $mailtemplate->sendMail(array(
                        'email' => $reservation->email,
                        'template_id' => 'reservation-refused',
                        'company_id' => $reservation->company_id,
                        'reservation_id' => $reservation->id,
                        'replacements' => array(
                            '%name%' => $reservation->name,
                            '%cname%' => '',
                            '%saldo%' => $reservation->saldo,
                            '%phone%' => $reservation->phone,
                            '%email%' => $reservation->email,
                            '%date%' => date('d-m-Y', strtotime($reservation->date)),
                            '%time%' => date('H:i', strtotime($reservation->time)),
                            '%persons%' => $reservation->persons,
                            '%comment%' => $reservation->comment,
                            '%allergies%' => (count(json_decode($reservation->allergies)) >= 1 ? implode(",", json_decode($reservation->allergies)) : ''),
                            '%preferences%' => (count(json_decode($reservation->preferences)) >= 1 ? implode(",", json_decode($reservation->preferences)) : '')
                        )
                    ));

                    // Give user cash back when the user is not coming.
                    if ($reservation->user_is_paid_back == 0) {
                        if ($reservation->saldo > 0) {
                            $user->saldo = $user->saldo + $reservation->saldo;
                            $user->save();
                        }
                    }

                    $reservation->user_is_paid_back = 1;
                    break;

                case 'reserved-pending':
                    $reservation->status = 'reserved';

                    $user = Sentinel::findById($reservation->user_id);
                    if ($reservationInfo->is_manual == 1) {
                        // Send to client
                        $mailtemplate->sendMail(array(
                            'email' => $reservation->email,
                            'template_id' => 'reservation-pending-client',
                            'company_id' => $reservation->company_id,
                            'reservation_id' => $reservation->id,
                            'replacements' => array(
                                '%name%' => $reservation->name,
                                '%cname%' => '',
                                '%saldo%' => $reservation->saldo,
                                '%phone%' => $reservation->phone,
                                '%email%' => $reservation->email,
                                '%date%' => date('d-m-Y', strtotime($reservation->date)),
                                '%time%' => date('H:i', strtotime($reservation->time)),
                                '%persons%' => $reservation->persons,
                                '%comment%' => $reservation->comment,
                                '%allergies%' => (count(json_decode($reservation->allergies)) >= 1 ? implode(",", json_decode($reservation->allergies)) : ''),
                                '%preferences%' => (count(json_decode($reservation->preferences)) >= 1 ? implode(",", json_decode($reservation->preferences)) : '')
                            )
                        ));
                    }
                    break;

                case 'iframe-pending':
                    $reservation->status = 'iframe-reserved';

                    if ($reservationInfo->is_manual == 1) {
                         // Send to client
                        $mailtemplate->sendMail(array(
                            'email' => $reservation->email,
                            'template_id' => 'reservation-pending-client',
                            'company_id' => $reservation->company_id,
                            'reservation_id' => $reservation->id,
                            'replacements' => array(
                                '%name%' => $reservation->name,
                                '%cname%' => '',
                                '%saldo%' => $reservation->saldo,
                                '%phone%' => $reservation->phone,
                                '%email%' => $reservation->email,
                                '%date%' => date('d-m-Y', strtotime($reservation->date)),
                                '%time%' => date('H:i', strtotime($reservation->time)),
                                '%persons%' => $reservation->persons,
                                '%comment%' => $reservation->comment,
                                '%allergies%' => (count(json_decode($reservation->allergies)) >= 1 ? implode(",", json_decode($reservation->allergies)) : ''),
                                '%preferences%' => (count(json_decode($reservation->preferences)) >= 1 ? implode(",", json_decode($reservation->preferences)) : '')
                            )
                        ));
                    }
                    break;

                case 'iframe-present':
                    $reservation->status = 'iframe-present';
                    break;

                case 'present':
                    $reservation->status = 'present';
                    break;

                case 'noshow':
                    $reservation->status = 'noshow';
                    $reservation->user_is_paid_back = 1;

                    // Give user cash back when the user is not coming.
                    if ($reservation->user_is_paid_back == 0) {
                        $user = Sentinel::getUserRepository()->findById($reservation->user_id);
                        $user->saldo = $user->saldo + $reservation->saldo;
                        $user->save();
                    }
                    break;
            }

            $reservation->save();
            
            return Redirect::to('admin/reservations/clients/'.$reservation->company_id.($reservation->status == 'noshow' ? '?status=noshow' : ''));
        }
    }

    public function listDate($company, $date, Request $request) 
    {  
        $limit = $request->input('limit', 15);

        $companyOwner = Company::isCompanyUser($company, Sentinel::getUser()->id);

        $data = CompanyReservation::select(
            'company_reservations.date',
            'company_reservations.available_persons',
            'company_reservations.start_time',
            'company_reservations.end_time',
            'company_reservations.per_time',
            'company_reservations.locked_times',
            'company_reservations.id as company_reservation_id',
            'reservations.id as reservation_id',
            'reservations.time',
            'reservations.persons',
            'reservations.date as reservation_date',
            'companies.id',
            'companies.slug'
        )
            ->leftJoin('reservations', function ($join) {
                $join
                    ->on('company_reservations.date', '=', 'reservations.date')
                    ->on('company_reservations.company_id', '=', 'reservations.company_id')
                    ->where('reservations.is_cancelled', '=', 0)
                ;
            })
            ->leftJoin('companies', 'company_reservations.company_id', '=', 'companies.id')
            ->where('company_reservations.company_id', '=', $company)
            ->where('company_reservations.date', '=', date('Y-m-d', strtotime($date)))
            ->where('company_reservations.is_locked', '=', 0)
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            if (Sentinel::inRole('bedrijf')) {
                $data = $data->where('companies.user_id', '=', Sentinel::getUser()->id);
            } elseif (Sentinel::inRole('bediening')) {
                $data = $data->where('companies.waiter_user_id', '=', Sentinel::getUser()->id);
            }
        }

        $data = $data->get();

        $reservationArray = array();
        $lockedTimeArray = array();
        $timeArray = array();
        $timeResult = array();
        $availablePersons = array();
        $reservation = array();
        $id = '';
        $slug = '';

        foreach ($data as $result) {
            // Set same dates in an array
            $reservation[date('H:i', strtotime($result->time))][$result->reservation_id] = $result->persons;

            $reservationArray[$result->company_reservation_id] = array($result->date => array(
                'start_time' => $result->start_time,
                'end_time' => $result->end_time,
                'interval' => $result->per_time,
                'available_persons' => $result->available_persons,
                'locked_times' => $result->locked_times,
                'company_id' => $result->id
            ));

            $id = $result->id;
            $slug = $result->slug;
        }
        
        // Take all rows of the date
        foreach ($reservationArray as $reservationFetch) {
            foreach ($reservationFetch as $dateFetch) {
                foreach (json_decode($dateFetch['available_persons']) as $key => $available_persons) {
                    $availablePersons[$key] = $available_persons;
                }

                if (trim($dateFetch['locked_times']) != '') {
                    foreach (json_decode($dateFetch['locked_times']) as $time) {
                        $lockedTimeArray[$time] = $time;
                    }
                }

                $startTime = strtotime($dateFetch['start_time']);
                $endTime = strtotime($dateFetch['end_time']); 
                $convertedTime = date('H:i', $startTime);
               
                $timeResult[$convertedTime] = array(
                    'available_persons' => (isset($availablePersons[$convertedTime]) ? $availablePersons[$convertedTime] : 0),
                    'persons' => (isset($reservation[$convertedTime]) ? array_sum($reservation[$convertedTime]) : 0),
                    'available' => (isset($availablePersons[$convertedTime]) ? $availablePersons[$convertedTime] : 0) - (isset($reservation[$convertedTime]) ? array_sum($reservation[$convertedTime]) : 0),
                    'locked' => (count($lockedTimeArray) >= 1 && isset($lockedTimeArray[$convertedTime]) ? 1 : 0),
                    'company_id' => $dateFetch['company_id']
                );

                while ($startTime < $endTime) {  
                    $startTime = strtotime('+'.self::$per_time[$dateFetch['interval']], $startTime);

                    if ($endTime >= $startTime) {
                        $convertedTime = date('H:i', $startTime);
                        $timeResult[$convertedTime] = array(
                            'available_persons' => (isset($availablePersons[$convertedTime]) ? $availablePersons[$convertedTime] : 0),
                            'persons' => (isset($reservation[$convertedTime]) ? array_sum($reservation[$convertedTime]) : 0),
                            'available' => (isset($availablePersons[$convertedTime]) ? $availablePersons[$convertedTime] : 0) - (isset($reservation[$convertedTime]) ? array_sum($reservation[$convertedTime]) : 0),
                            'locked' => (count($lockedTimeArray) >= 1 && isset($lockedTimeArray[$convertedTime]) ? 1 : 0),
                            'company_id' => $dateFetch['company_id']
                        );
                    }
                } 
            }
        }

        if ($request->has('sort') && $request->has('order')) {
            if ($request->input('sort') == 'time' && $request->input('order') == 'desc') {
                krsort($timeResult);
            }  

            if ($request->input('sort') == 'available_persons' && $request->input('order') == 'desc') {
                uasort($timeResult, function($a, $b) {
                    return $a['available_persons'] < $b['available_persons'];
                });
            }

            if ($request->input('sort') == 'available' && $request->input('order') == 'desc') {
                uasort($timeResult, function($a, $b) {
                    return $a['available'] < $b['available'];
                });
            }

            if ($request->input('sort') == 'persons' && $request->input('order') == 'desc') {
                uasort($timeResult, function($a, $b) {
                    return $a['persons'] > $b['persons'];
                });
            }

            if ($request->input('sort') == 'persons' && $request->input('order') == 'asc') {
                uasort($timeResult, function($a, $b) {
                    return $a['persons'] < $b['persons'];
                });
            }

        } else {
            ksort($timeResult);
        }

        $currentPage = ($request->input('page', 1) - 1);
        $pagedData = array_slice($timeResult, $currentPage * $limit, $limit);

        $results = new LengthAwarePaginator($pagedData, count($timeResult), $limit);
        $results->setPath('');

        if ($request->input('page') > $results->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $results->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        if ($companyOwner == TRUE || Sentinel::inRole('admin')) {
            $queryString = $request->query();
            unset($queryString['limit']);

            $getTimes = CompanyReservation::getAllTimes();
            sort($getTimes);

            return view('admin/'.$this->slugController.'/date', array(
                'times' => $getTimes,
                'data' => $results, 
                'date' => date('Y-m-d', strtotime($date)), 
                'company' => $company, 
                'slugController' => $this->slugController.'/date/'.$company.'/'.$date,
                'carbon' => new Carbon,
                'companyId' => (trim($id) != '' ? '/'.$id : ''),
                'companyParam' => (trim($slug) != '' ? '/'.$slug : ''),
                'sessionSort' => $request->session()->get('sort'),
                'sessionOrder' => $request->session()->get('order'),
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $limit
            ));
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
    }

    public function create($company = null) 
    {  
        if ($company != null) {
            $company = Company::where('slug', $company)->first();

            if ($company) {
                if (Sentinel::inRole('admin') == FALSE && $company->signature_url == NULL) {
                    alert()->error('', 'U heeft nog geen handtekening opgegeven en u bent nog niet akkoord gegaan met de Algemene Voorwaarden.')->persistent('Sluiten');
                    return Redirect::to('admin/companies/update/'.$company->id.'/'.$company->slug.'?step=1');
                }
            }
        }

        return view('admin/'.$this->slugController.'/create', [
            'companies' => $this->companies,
            'company' => isset($company) ? $company->id : '',
            'slug' => isset($company) ? $company->slug : '',
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe reserveringen'        
        ]);
    }

    public function createAction(Request $request, $slug = null) 
    {
        $company = new Company();

        if (Sentinel::inRole('admin') == FALSE && Sentinel::inRole('bedrijf')) {
            $company = $company->where('companies.user_id', '=', Sentinel::getUser()->id);
        }

        $company = $company->find($request->input('company'));

        $this->validate($request, array(
            'company' => 'exists:companies,id',
            'start_time' => 'required',
            'end_time' => 'required',
            'available_persons' => 'required|numeric|min:1',
            'per_time' => 'required|numeric',
            'days' => 'required'
        ));

        $startDate = gmdate("Y-m-d", strtotime($request->input('start_date')));
        $endDate = gmdate("Y-m-d", strtotime($request->input('end_date')));
        
        foreach ($request->input('start_time') as $key => $startime) {
            $startTime  = strtotime($request->input('start_time')[$key]);
            $endTime = strtotime($request->input('end_time')[$key]);
            $timeLoop[] = date('H:i', $startTime);

            while ($startDate <= $endDate) {  
                // Get all dates between two dates
                $startDate = date("Y-m-d", strtotime("+1 day", strtotime($startDate)));  
                $dates[] = $startDate;  
            }  

            while ($startTime < $endTime) {  
                // Get all times between two times
                $startTime = strtotime('+'.self::$per_time[$request->input('per_time')], $startTime);
                if ($endTime >= $startTime)  {
                    $timeLoop[] = date('H:i', $startTime); 
                }
            }  

            // Set the amount of available persons per time period in an array.
            $availablePersons[$key] = array();
            $availableDeals[$key] = array();

            foreach ($timeLoop as $time) {
               $availablePersons[$key][$time] = $request->input('available_persons');
               $availableDeals[$key][$time] = $request->input('available_deals');
            }

            while ($startDate <= $endDate) {  
                // Get all dates between two dates
                $startDate = date("Y-m-d", strtotime("+1 day", strtotime($startDate)));  
                $dates[] = $startDate;  
            }  
        }  

        foreach ($dates as $date) {   
            foreach ($request->input('days') as $key => $day) {   
                if (in_array(date('N', strtotime($date)), $day)) {
                    $reservationDate[] = array(
                        'created_at' => date('now'),
                        'date' => $date,
                        'start_time' => $request->input('start_time')[$key],
                        'end_time' => $request->input('end_time')[$key],
                        'company_id' => $request->input('company'),
                        'per_time' => $request->input('per_time'),
                        'available_persons' => json_encode($availablePersons[$key]),
                        'available_deals' => json_encode($availableDeals[$key]),
                        'closed_before_time' => $request->input('closed_before_time'),
                        'cancel_before_time' => $request->input('cancel_before_time'),
                        'update_before_time' => $request->input('update_before_time'),
                        'max_persons' => $request->input('max_persons'),
                        'closed_before_time' => $request->input('closed_before_time'),
                        'cancel_before_time' => $request->input('cancel_before_time'),
                        'update_before_time' => $request->input('update_before_time'),
                        'reminder_before_date' => $request->input('reminder_before_date'),
                        'is_manual' => $request->input('is_manual'),
                        'closed_at_time' => ($request->has('closed_at_time') ? $request->input('closed_at_time').':00' : '')
                    );
                }
            }
        }

        if (isset($reservationDate)) {
            CompanyReservation::insert($reservationDate);
        }

        Alert::success('Uw opgegeven data voor reserveringen zijn succesvol aangemaakt.')->persistent('Sluiten');   

        if ($request->has('step')) {
            return Redirect::to('faq/3/restaurateurs?step=3&slug='.$slug);
        } else {
            return Redirect::to('admin/reservations/'.$company->slug);
        }
    }

    public function update(Request $request, $company, $date = null) 
    {  
        $limit = $request->input('limit', 15);

        $companyOwner = Company::isCompanyUser($company, Sentinel::getUser()->id);
        $companyInfo = Company::find($company);

        $reservations = CompanyReservation::select(
            'company_reservations.*',
            'companies.name as companyName',
            'companies.slug',
            'companies.min_saldo'
        )
            ->leftJoin('companies', 'company_reservations.company_id', '=', 'companies.id')
            ->where('company_reservations.company_id', '=', $company)
        ;

        if ($date != null) {
            $reservations = $reservations->where('company_reservations.date', '=', date('Y-m-d', strtotime($date)));
        }
        
        $reservations = $reservations->first();

        if ($companyOwner == TRUE || Sentinel::inRole('admin')) {
            if (count($reservations) == 0 && isset($companyInfo)) {
                return Redirect::to('admin/reservations/create/'.$companyInfo->slug.'?step=2');
            }
            
            if ($reservations) {
                $date = \Carbon\Carbon::create(
                    date('Y', strtotime($reservations->date)), 
                    date('m', strtotime($reservations->date)), 
                    date('d', strtotime($reservations->date))
                );
            }

            return view('admin/'.$this->slugController.'/update', array(
                'data' => $reservations, 
                'date' => $date, 
                'company' => $company, 
                'slugController' => $this->slugController.'/settings/'.$company.($date != null ? '/'.$date : ''),
                'carbon' => new Carbon,
            ));
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
    }

    public function updateAction(Request $request, $company, $date = null) 
    {  
        $companyOwner = Company::isCompanyUser($company, Sentinel::getUser()->id);

        $reservations = CompanyReservation::select(
            'id',
            'date',
            'available_persons',
            'start_time',
            'end_time',
            'per_time',
            'locked_times',
            'company_id'
        )
            ->where('company_id', '=', $company)
        ;

        if ($companyOwner == TRUE || Sentinel::inRole('admin')) {
            if ($request->has('add')) {
                $reservations->delete();

                $i = 0;

                $startDate = gmdate("Y-m-d", strtotime($request->input('start_date')));
                $endDate = gmdate("Y-m-d", strtotime($request->input('end_date')));

                foreach ($request->input('start_time') as $key => $startime) {
                    $startTime  = strtotime($request->input('start_time')[$key]);
                    $endTime = strtotime($request->input('end_time')[$key]);
                    $timeLoop[] = date('H:i', $startTime);

                    while ($startDate <= $endDate) {  
                        // Get all dates between two dates
                        $startDate = date("Y-m-d", strtotime("+1 day", strtotime($startDate)));  
                        $dates[] = $startDate;  
                    }  

                    while ($startTime < $endTime) {  
                        // Get all times between two times
                        $startTime = strtotime('+'.self::$per_time[$request->input('per_time')], $startTime);
                        if ($endTime >= $startTime)  {
                            $timeLoop[] = date('H:i', $startTime); 
                        }
                    }  

                    // Set the amount of available persons per time period in an array.
                    $availablePersons[$key] = array();
                    $availableDeals[$key] = array();

                    foreach ($timeLoop as $time) {
                       $availablePersons[$key][$time] = $request->input('available_persons');
                    }

                    while ($startDate <= $endDate) {  
                        // Get all dates between two dates
                        $startDate = date("Y-m-d", strtotime("+1 day", strtotime($startDate)));  
                        $dates[] = $startDate;  
                    }  
                }  

                foreach ($dates as $date) {   
                    if ($request->has('days')) {
                        foreach ($request->input('days') as $key => $day) {   
                            if (in_array(date('N', strtotime($date)), $day)) {
                                $i++; 

                                $reservationDate[$i] = array(
                                    'created_at' => date('now'),
                                    'date' => $date,
                                    'start_time' => $request->input('start_time')[$key],
                                    'end_time' => $request->input('end_time')[$key],
                                    'company_id' => $company,
                                    'per_time' => $request->input('per_time'),
                                    'available_persons' => json_encode($availablePersons[$key]),
                                );
                            }
                        }
                    }
                }

                if (isset($reservationDate)) {
                    CompanyReservation::insert($reservationDate);
                }
            } else {
                $reservationsGet = $reservations->get();

                if (count($reservationsGet) >= 1) {
                    CompanyReservation::where('company_id', $company)
                        ->update(array(
                            'max_persons' => $request->input('max_persons'),
                            'closed_before_time' => $request->input('closed_before_time'),
                            'cancel_before_time' => $request->input('cancel_before_time'),
                            'update_before_time' => $request->input('update_before_time'),
                            'reminder_before_date' => $request->input('reminder_before_date'),
                            'is_manual' => $request->input('is_manual'),
                            'extra_reservations' => $request->input('extra_reservations'),
                            'closed_at_time' => $request->input('closed_at_time')
                        )
                    ); 
                        
                    Company::where('id', '=', $company)->update(array('min_saldo' => $request->input('min_saldo'))); 
                }
            }
          
            Alert::success('De instellingen zijn succesvol doorgevoerd.')->persistent('Sluiten');   
            return Redirect::to('admin/reservations/update/'.$company);
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
    }

    public function dateUpdateAction(Request $request, $company = null)
    {
        if ($request->has('time')) {
            $reservations = CompanyReservation::select(
                'id',
                'date',
                'locked_times',
                'available_persons'
            )
                ->where('company_id', '=', $request->input('company'))
                ->whereIn('id', $request->input('id'))
                ->get()
            ;

            $times = $request->input('time');

            // Locked times already in database
            foreach ($reservations as $reservation) {
                if (trim($reservation->locked_times) != '') {
                    foreach (json_decode($reservation->locked_times) as $time) {
                        $timeArray[$reservation->id][$time] = $time;
                    }
                }
            }

            if ($request->input('action') == 'close') {
                foreach ($request->input('id') as $resId) {
                    if (isset($times[$resId])) {
                        foreach ($times[$resId] as $companyTimes) {
                            foreach ($companyTimes as $time) {
                                $timeArray[$resId][$time] = $time;
                            }
                        }
                    }
                }
            }
        }

        switch ($request->input('action')) {
            case 'close':
                if ($request->has('time')) {
                    if (isset($timeArray)) {                   
                        foreach ($timeArray as $reservationId => $timeFetch) {
                            CompanyReservation::where('id', '=', $reservationId)
                                   ->update(
                                    array(
                                        'locked_times' => json_encode(array_keys($timeArray[$reservationId]))
                                    )
                                )
                            ;
                        }
                    }
                } else {
                    if($request->has('id')) {
                        foreach($request->get('id') as $id) {
                            $data = CompanyReservation::find($id);
                            $data->is_locked = 1;
                            $data->save();
                        }
                    }
                }
                break;
            case 'open':
                if ($request->has('time')) {
                    if (isset($timeArray)) {
                        foreach ($request->input('id') as $resId) {
                            foreach ($times[$resId] as $companyTimes) {
                                foreach ($companyTimes as $time) {
                                    if (isset($timeArray[$resId][$time])) {
                                        unset($timeArray[$resId][$time]);
                                    }
                                }
                             }

                            if (isset($times[$resId])) {
                                CompanyReservation::where('id', '=', $resId)
                                    ->update(
                                        array(
                                            'locked_times' => json_encode(array_keys($timeArray[$resId]))
                                        )
                                    )
                                ;
                            }
                        }
                    }
                } else {
                    if ($request->has('id')) {
                        foreach ($request->input('id') as $id) {
                            $data = CompanyReservation::find($id);

                            if (count($data) == 1) {
                                $data->is_locked = 0;
                                $data->locked_times = '';
                                $data->save();
                            }
                        }
                    }
                }
                break;
            case 'remove':
                if ($request->has('id')) {
                    foreach ($request->input('id') as $id) {
                        $data = CompanyReservation::find($id);

                        if (count($data) == 1) {
                            $data->delete();
                        }
                    }
                }
                break;
            case 'update':
                $this->validate($request, [
                    'persons' => 'min:1'
                ]);
                
                if ($request->has('persons')) {
                    if ($request->has('time')) {
                        foreach ($reservations as $reservation) {
                            foreach (json_decode($reservation->available_persons) as $time => $persons) {
                                $availableTimeArray[$reservation->id][$time] = $persons;
                            }

                            if (isset($times[$reservation->id])) {
                                foreach ($times[$reservation->id] as $key => $timeFetch) {
                                    foreach ($timeFetch as $timeResult) {
                                       $availableTimeArray[$reservation->id][$timeResult] = $request->input('persons')[$reservation->id];

                                    }
                                }
                            }
                        }

                        if(isset($availableTimeArray)) {
                            foreach($availableTimeArray as $reservationId => $times) {
                                CompanyReservation::where(
                                    'id', '=', $reservationId
                                )
                                    ->update(array(
                                        'available_persons' => json_encode($times)
                                    ))
                                ;
                            }
                        }
                    } else {
                        foreach ($request->input('persons') as $key => $persons) {
                            if ($persons >= 1) {
                                $data = CompanyReservation::where(
                                    'id', $key
                                )
                                    ->where('is_locked', 0)
                                    ->get()
                                ;

                                $timesArray = array();
                                
                                if (count($data) == 1) {
                                    foreach ($data as $reservation) {
                                        foreach (json_decode($reservation->available_persons) as $key => $times) {
                                            $timesArray[$reservation->id][$key] = $persons;
                                        }

                                        $saveData = CompanyReservation::find($reservation->id);
                                        $saveData->available_persons = json_encode($timesArray[$reservation->id]);
                                        $saveData->save();
                                    }
                                }
                            }
                        }
                    }
                }
            break;
        }
        
        return Redirect::to('admin/'.$this->slugController.(isset($company) ? '/'.$company : ''));
    }

    public function timeUpdateAction(Request $request)
    {
        $reservations = CompanyReservation::select(
            'id',
            'date',
            'locked_times',
            'available_persons'
        )
            ->where('company_id', '=', $request->input('company'))
            ->where('date', '=', $request->input('date'))
            ->get()
        ;

        switch ($request->get('action')) {
            case 'open': 
                if (count($reservations) >= 1) {
                    foreach ($reservations as $reservation) {
                        if (trim($reservation->locked_times) != '') {
                            foreach (json_decode($reservation->locked_times) as $time) {
                                $timeArray[$reservation->id][$time] = $time;
                            }
                        }
                    }

                    if (isset($timeArray)) {
                        foreach ($timeArray as $reservationId => $timeFetch) {
                            foreach ($request->input('id') as $value) {
                                if(isset($timeArray[$reservationId][$value])) {
                                    unset($timeArray[$reservationId][$value]);
                                }
                            }

                            CompanyReservation::where(
                                'id', '=', $reservationId
                            )
                                ->update(array(
                                    'locked_times' => json_encode(array_keys($timeArray[$reservationId]))
                                ))
                            ;
                        }
                    }
                }
               break;

            case 'lock':
                if (count($reservations) >= 1) {
                    $i = 0;
                    foreach ($reservations as $reservation) {
                        foreach (json_decode($reservation->available_persons) as $time => $persons) {
                            $i++;

                            $availableTimeArray[$time][$i] = array(
                                'reservationId' => $reservation->id
                            );
                        }

                        if (trim($reservation->locked_times) != '') {
                            foreach (json_decode($reservation->locked_times) as $time) {
                                $lockedTimeArray[$reservation->id][$time] = $time;
                            }
                        }

                        foreach ($request->input('id') as $time) {
                            if(isset($availableTimeArray[$time])) {
                                foreach($availableTimeArray[$time] as $availableTimeFetch) {
                                    $lockedTimeArray[$availableTimeFetch['reservationId']][$time] = $time;
                                }
                            }
                        }
                    }

                    if(isset($lockedTimeArray)) {
                        foreach($lockedTimeArray as $reservationId => $timeFetch) {
                            CompanyReservation::where(
                                'id', '=', $reservationId
                            )
                                ->update(array(
                                    'locked_times' => json_encode(array_keys($lockedTimeArray[$reservationId]))
                                ))
                            ;
                        }
                    }
                }
                break;

            case 'remove':
                if ($request->has('persons')) {
                    foreach ($reservations as $reservation) {
                        foreach (json_decode($reservation->available_persons) as $key => $times) {
                            $timesArray[$reservation->id][$key] = $key;
                        }

                        foreach ($request->get('id') as $key => $value) {
                            if (isset($timesArray[$reservation->id][$value])) {
                                unset($timesArray[$reservation->id][$value]);
                            }
                        }
                    }   

                    if (isset($timesArray)) {
                        foreach ($timesArray as $reservationId => $times)  {
                            CompanyReservation::where(
                                'id', '=', $reservationId
                            )
                                ->update(array(
                                    'available_persons' => json_encode($times)
                                ))
                            ;
                        }
                    }
                }
                break;

            case 'update':
                if ($request->has('persons')) {
                    foreach ($reservations as $reservation) {
                        foreach (json_decode($reservation->available_persons) as $key => $times) {
                            $timesArray[$reservation->id][$key] = $times;
                        }

                        foreach ($request->get('persons') as $key => $value) {
                            if (isset($timesArray[$reservation->id][$key])){
                                if ($timesArray[$reservation->id][$key] != $value)  {
                                    $timesArray[$reservation->id] = array_except($timesArray[$reservation->id], [$key]);
                             
                                     // Set new  value
                                    array_set($timesArray[$reservation->id] , $key, $value);
                                    ksort($timesArray[$reservation->id]);
                                }
                            }
                        }

                        if(isset($timesArray)) {
                            if($reservation->available_persons == json_encode($timesArray[$reservation->id])) {
                                unset($timesArray[$reservation->id]);
                            }
                        }
                    }

                    if(isset($timesArray)) {
                        foreach($timesArray as $reservationId => $times) {
                            CompanyReservation::where(
                                'id', '=', $reservationId
                            )
                                ->update(array(
                                    'available_persons' => json_encode($times)
                                ))
                            ;
                        }
                    }
                }
                break;
        }
        
        return Redirect::to('admin/'.$this->slugController.'/date/'.$request->input('company').'/'.date('Ymd', strtotime($request->input('date'))));
    }

    public function statusUpdate(Request $request, $reservationId)
    {
        $reservation = Reservation::select(
            'companies.name as companyName',
            'companies.allergies as companyAllergies',
            'companies.preferences as companyPreferences',
            'companies.user_id as companyOwner',
            'companies.id as companyId',
            'reservations.*'
        )
            ->leftJoin('companies', 'reservations.company_id', '=', 'companies.id')
            ->whereIn('reservations.status', array('reserved-pending', 'pending', 'iframe-pending'))
            ->find($reservationId)
        ;

        if (count($reservation) == 1) {
            if(
                $reservation->companyOwner == Sentinel::getUser()->id
                OR Sentinel::inRole('admin')
            ) {
                $user = Sentinel::findById($reservation->user_id);
                $mailtemplate = new MailTemplate();

                switch ($request->input('status')) {
                    case 'refused':
                        $reservation->status = 'refused';
                            
                        $mailtemplate->sendMail(array(
                            'email' => $reservation->email,
                            'template_id' => 'reservation-refused',
                            'company_id' => $reservation->company_id,
                            'reservation_id' => $reservation->id,
                            'replacements' => array(
                                '%name%' => $reservation->name,
                                '%saldo%' => $reservation->saldo,
                                '%phone%' => $reservation->phone,
                                '%email%' => $reservation->email,
                                '%date%' => date('d-m-Y', strtotime($reservation->date)),
                                '%time%' => date('H:i', strtotime($reservation->time)),
                                '%persons%' => $reservation->persons,
                                '%comment%' => $reservation->comment,
                                '%allergies%' => (count(json_decode($reservation->allergies)) >= 1 ? implode(",", json_decode($reservation->allergies)) : ''),
                                '%preferences%' => (count(json_decode($reservation->preferences)) >= 1 ? implode(",", json_decode($reservation->preferences)) : '')
                            )
                        ));

                        // Give user cash back when the user is not coming.
                        if ($reservation->user_is_paid_back == 0) {
                            if ($reservation->saldo > 0) {
                                $user->saldo = $user->saldo + $reservation->saldo;
                                $user->save();
                            }
                        }

                        Alert::success('Deze reservering staat nu als geweigerd.')
                            ->persistent('Sluiten')
                        ;
                            
                        $reservation->user_is_paid_back = 1;
                    break;

                    case 'reserved-pending':
                        $reservation->status = 'reserved';
                        
                        // Send to client
                        $mailtemplate->sendMail(array(
                            'email' => $reservation->email,
                            'template_id' => 'reservation-pending-client',
                            'company_id' => $reservation->company_id,
                            'reservation_id' => $reservation->id,
                            'replacements' => array(
                                '%name%' => $reservation->name,
                                '%saldo%' => $reservation->saldo,
                                '%phone%' => $reservation->phone,
                                '%email%' => $reservation->email,
                                '%date%' => date('d-m-Y', strtotime($reservation->date)),
                                '%time%' => date('H:i', strtotime($reservation->time)),
                                '%persons%' => $reservation->persons,
                                '%comment%' => $reservation->comment,
                                '%allergies%' => (count(json_decode($reservation->allergies)) >= 1 ? implode(",", json_decode($reservation->allergies)) : ''),
                                '%preferences%' => (count(json_decode($reservation->preferences)) >= 1 ? implode(",", json_decode($reservation->preferences)) : '')
                            )
                        ));

                        Alert::success('Deze reservering is succesvol geaccepteerd.')
                            ->persistent('Sluiten')
                        ;
                    break;
                }

                $reservation->save();
                
                return Redirect::to('admin/reservations/clients/'.$reservation->companyId);
            } else {
                Alert::error('U heeft niet genoeg rechten om deze reservering te wijzigen.')->persistent('Sluiten');

                return Redirect::to('admin/reservations/clients/'.$reservation->companyId);
            }
        } else {
            Alert::error('Deze reservering bestaat niet.')->persistent('Sluiten');
        
            return Redirect::to('/');
        }
    }

    public function emails(Request $request)
    {
        $data = new GuestThirdParty();

        if ($request->has('network')) {
            $data = $data->where('network', '=', $request->input('network'));
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } 

        $data =  $data
            ->where('reservation_date', '!=', '0000-00-00 00:00:00')
            ->where('created_at', '!=', '0000-00-00 00:00:00')
        ;

        $data = $data->where(function ($query) use($request) {
            $query
                ->where('restaurant_zipcode', '=', '')
                ->orWhereNull('restaurant_zipcode')
            ;
        });

        $data=  $data
            ->orderBy('created_at', 'desc')
            ->groupBy('mail_id')
            ->paginate($this->limit)
        ;

        $guestThirdPartyData = GuestThirdParty::select(
            DB::raw('(SELECT 
                        count(id) 
                     FROM  
                        guests_third_party 
                     GROUP BY 
                        mail_id
                     ) as pending'),
            DB::raw('(SELECT 
                        count(reservations.id)
                    FROM 
                        reservations
                    WHERE
                        source IN ("couverts", "seatme", "eetnu")
                ) as success')
        )
            ->first()
        ;
        $queryString = $request->query();
        unset($queryString['limit']);
        unset($queryString['network']);

        $companies = Company::lists('name', 'id');

        return view('admin/'.$this->slugController.'/emails', array(
           'data' => $data,
           'guestThirdPartyData' => $guestThirdPartyData,
           'limit' => $this->limit,
           'slugController' => $this->slugController.'/emails',
           'carbon' => new Carbon,
           'companies' => $companies,
           'queryString' => $queryString,
           'paginationQueryString' => $request->query(),
        ));
    }

    public function emailsAction(Request $request)
    {
        $this->validate($request, array(
            'id' => 'required'
        ));
        
        $companiesQuery = Company::all();

        foreach ($companiesQuery as $key => $companiesFetch) {
           $companyArray[$companiesFetch->id] = $companiesFetch->zipcode;
        }

        $error = 0;

        switch ($request->input('status')) {
            case 'accept':
                foreach ($request->input('id') as $id => $value) {
                    $reservationInput = $request->input('reservation');

                    if ($reservationInput[$id]['restaurant_id'] > 0) {
                        $reservation = GuestThirdParty::find($id);

                        $restaurantName[$reservation->restaurant_name] = array(
                            'id' => $reservationInput[$id]['restaurant_id'],
                            'zipcode' => $companyArray[$reservationInput[$id]['restaurant_id']]
                        );

                        $reservation->reservation_number = $reservationInput[$id]['reservation_number'];
                        $reservation->save();
                    } else {
                        $error++;
                    }
                }

                foreach ($restaurantName as $restaurantName => $restaurant) {
                    GuestThirdParty::where('restaurant_name', $restaurantName)
                        ->update(
                            array(
                                'restaurant_id' =>  $restaurant['id'],
                                'restaurant_zipcode' =>  $restaurant['zipcode']
                            )
                        )
                    ;
                }

                if ($error > 0) {
                    alert()->error('', 'Er is een reservering niet meegenomen omdat u bent vergeten een bedrijf op te geven')->persistent('Sluiten');
                } else {
                    alert()->success('', 'De opgegeven mails zijn succesvol opgeslagen als een echte reservering.')->persistent('Sluiten');
                }
                break;

            case 'decline':
                GuestThirdParty::whereIn('id', $request->input('id'))->update(array(
                    'reservation_status' => 'decline'
                ));

                alert()->success('', 'De opgegeven mails zijn succesvol afgkeurd.')->persistent('Sluiten');
                break;
        }

        return Redirect::to('admin/reservations/emails');
    }

}