<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\SearchHistory;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;
use DB;
use Config;

class StatisticsController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'statistics';
        $this->section = 'Statistieken';
    }

    public function reservations(Request $request)
    {
        $topDays = DB::table('reservations')
            ->select(
                DB::raw('weekday(date) as nameRow'),
                DB::raw('count(weekday(date)) as countRow')
            )
            ->orderBy('countRow', 'desc')
            ->groupBy('nameRow')
            ->take(10)
        ;

        $topTimes = DB::table('reservations')
            ->select(
                DB::raw('time as nameRow'),
                DB::raw('count(time) as countRow')
            )
            ->orderBy('countRow', 'desc')
            ->groupBy('nameRow')
            ->take(10)
        ;

        $topPersons = DB::table('reservations')
            ->select(
                DB::raw('persons as nameRow'),
                DB::raw('count(persons) as countRow')
            )
            ->orderBy('countRow', 'desc')
            ->groupBy('nameRow')
            ->take(10)
        ;

        $topCompanies = DB::table('reservations')
            ->select(
                DB::raw('companies.name as nameRow'),
                DB::raw('count(reservations.id) as countRow')
            )
            ->leftJoin('companies', 'companies.id', '=', 'reservations.company_id')
            ->orderBy('countRow', 'desc')
            ->groupBy('reservations.company_id')
            ->take(10)
        ;

        $topClicksFaqs = DB::table('faq_questions')
            ->select(
                DB::raw('title as nameRow'),
                DB::raw('count(clicks) as countRow')
            )
            ->orderBy('countRow', 'desc')
            ->groupBy('title')
            ->take(5)
        ;

        $topClicksCompanies = DB::table('companies')
            ->select(
                DB::raw('companies.name as nameRow'),
                DB::raw('count(clicks) as countRow')
            )
            ->orderBy('countRow', 'desc')
            ->groupBy('companies.name')
            ->take(10)
        ;

        $topClicksAffiliates = DB::table('affiliates')
            ->select(
                DB::raw('affiliates.name as nameRow'),
                DB::raw('count(clicks) as countRow')
            )
            ->orderBy('countRow', 'desc')
            ->groupBy('affiliates.name')
            ->take(10)
        ;

        $topClicksPreferences = DB::table('preferences')
            ->select(
                DB::raw('preferences.name as nameRow'),
                DB::raw('count(clicks) as countRow')
            )
            ->where('preferences.category_id', 1)
            ->orderBy('countRow', 'desc')
            ->groupBy('preferences.name')
            ->take(10)
        ;

        if ($request->has('from') && $request->has('to')) {
            $topDays = $topDays
                ->where('date', '>=', $request->input('from'))
                ->where('date', '<=', $request->input('to'))
            ;
            
            $topTimes = $topTimes
                ->where('date', '>=', $request->input('from'))
                ->where('date', '<=', $request->input('to'))
            ;

            $topPersons = $topPersons
                ->where('date', '>=', $request->input('from'))
                ->where('date', '<=', $request->input('to'))
            ;
            
            $topCompanies = $topCompanies
                ->where('date', '>=', $request->input('from'))
                ->where('date', '<=', $request->input('to'))
            ;
        }

        if ($request->has('source')) {
            switch ($request->input('source')) {
                case 'wifi':
                    $topDays = $topDays 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->rightJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email')
                    ;
                    
                    $topTimes = $topTimes 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->rightJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email')
                    ;

                    $topCompanies = $topCompanies 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->rightJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email')
                    ;

                    $topPersons = $topPersons 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->rightJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email')
                    ;
                    break;
                
                default:

                    $topDays = $topDays 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->where('reservations.source', '=', $request->input('source'))
                    ;
                    
                    $topTimes = $topTimes 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->where('reservations.source', '=', $request->input('source'))
                    ;

                    $topPersons = $topPersons 
                        ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                        ->where('reservations.source', '=', $request->input('source'))
                    ;
                    
                    break;
            }
        }

        $topStatistics = DB::table('reservations')
            ->select(
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        reservations
                    WHERE
                        saldo = 0
                ) as reservationsWithoutSaldo'),
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        reservations
                    WHERE
                        saldo > 0
                ) as reservationsSaldo'),
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        reservations
                    '.($request->has('source') ? 'LEFT JOIN users ON users.id = reservations.user_id' : '').'
                    '.($request->has('source') && $request->input('source') == 'wifi' ? 'RIGHT JOIN guests_wifi ON guests_wifi.email = users.email' : '').'

                    WHERE 
                        reservations.saldo >= 0
                    '.($request->has('from') && $request->has('to') ? 'AND date >= "'.$request->input('from').'"' : '').'
                    '.($request->has('from') && $request->has('to') ? 'AND date <= "'.$request->input('to').'"' : '').'
                    '.($request->has('source') && $request->input('source') != 'wifi' ? 'AND reservations.source = "'.$request->input('source').'"' : '').'
                
                ) as topReservations'),
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        affiliates
                 ) as topAffiliate'),
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        companies
                    '.($request->has('from') && $request->has('to') ? 'WHERE created_at >= "'.$request->input('from').'"' : '').'
                    '.($request->has('from') && $request->has('to') ? 'AND created_at <= "'.$request->input('to').'"' : '').'
                ) as topCompanies'),
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        transactions
                    '.($request->has('from') && $request->has('to') ? 'WHERE created_at >= "'.$request->input('from').'"' : '').'
                    '.($request->has('from') && $request->has('to') ? 'AND created_at <= "'.$request->input('to').'"' : '').'
                ) as topTransactions'),
                DB::raw('(
                    SELECT
                        count(*)
                    FROM
                        users

                    '.($request->has('from') && $request->has('to') ? 'WHERE created_at >= "'.$request->input('from').'"' : '').'
                    '.($request->has('from') && $request->has('to') ? 'AND created_at <= "'.$request->input('to').'"' : '').'
                ) as topUsers')
            )
            ->first()
        ;

        $queryString = $request->query();
        unset($queryString['source']);

        return view('admin/'.$this->slugController.'/reservations', [
            'queryString' => $queryString,
            'topDays' => $topDays->get(),
            'topTimes' => $topTimes->get(),
            'topCompanies' => $topCompanies->get(),
            'topPersons' => $topPersons->get(),
            'topStatistics' => $topStatistics,
            'topClicksCompanies' => $topClicksCompanies->get(),
            'topClicksAffiliates' => $topClicksAffiliates->get(),
            'topClicksPreferences' => $topClicksPreferences->get(),
            'topClicksFaqs' => $topClicksFaqs->get(),
            'dayName' => Config::get('preferences.days'),
            'slugController' => $this->slugController,
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function search(Request $request)
    {
        $data = new SearchHistory();

        if ($request->has('section'))  {
            $data = $data->where('page', '=', '/'.$request->input('section'));
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('id', 'desc');
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
        unset($queryString['section']);
        unset($queryString['limit']);

        return view('admin/'.$this->slugController.'/search', [
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

}