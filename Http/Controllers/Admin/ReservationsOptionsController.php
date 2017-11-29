<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReservationOption;
use App\Models\Company;
use App\User;
use Alert;
use Mail;
use App;
use DB;
use Config;
use Illuminate\Http\Request;
use Redirect;
use Sentinel;

class ReservationsOptionsController extends Controller 
{
    public function __construct(Request $request) 
    {
        $this->slugController = 'reservations-options';
        $this->limit = $request->input('limit', 15);
        $this->companies = Company::orderBy('id', 'asc')->lists('name', 'id');
    }

    public function isCompanyOwner($slug) 
    {
        $myCompany = Company::where('user_id', Sentinel::getUser()->id)
            ->where('slug', $slug)
            ->first()
        ;

        return array(
            'exist' => count($myCompany),
            'id' => (isset($myCompany->id) ? $myCompany->id : 0),
        );
    }

    public function index(Request $request, $slug = NULL) 
    {
        if ($this->isCompanyOwner($slug)['exist'] == 0 && $slug != NULL && Sentinel::inRole('admin') == FALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $data = ReservationOption::select(
            'reservations_options.id',
            'reservations_options.name',
            'reservations_options.total_amount',
            DB::raw('count(reservations.option_id) as total_res')
        )
            ->leftJoin('companies', 'companies.id', '=', 'reservations_options.company_id')
            ->leftJoin('reservations', function ($join) {
                $join
                    ->on('reservations.company_id', '=', 'reservations_options.company_id')
                    ->on('reservations.option_id', '=', 'reservations_options.id')
                ;
            })
        ;

        if ($request->has('q')) {
            $data = $data->where('reservations_options.name', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($slug != null) {
            $data = $data->where('companies.slug', '=', $slug);
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('reservations_options.id', 'desc');
        }

        $data = $data->groupBy('option_id')->paginate($this->limit);
        $data->setPath($this->slugController.(trim($slug) != '' ? '/'.$slug : ''));
        
        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);

        return view('admin/'.$this->slugController.'/index', [
            'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
            'section' => 'Aanbiedingen',
            'currentPage' => 'Overzicht',     
            'slug' => $slug, 
            'data' => $data, 
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $this->limit,
        ]);
    }

    public function indexAction(Request $request, $slug = NULL)
    {
        if ($this->isCompanyOwner($slug)['exist'] == 0 && $slug != NULL && Sentinel::inRole('admin') == fALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        if ($request->has('id')) {
            $data = ReservationOption::join('companies', 'companies.id', '=', 'reservations_options.company_id');

            if (Sentinel::inRole('admin') == FALSE) {
                $data = $data
                    ->where('companies.user_id', Sentinel::getUser()->id)
                    ->where('companies.slug', $slug)
                ;
            }

            $data = $data->whereIn('reservations_options.id', $request->input('id'));

            if ($data->count() >= 1) {
                $data->delete();
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }

    public function create(Request $request, $slug = NULL) 
    {
        if ($this->isCompanyOwner($slug)['exist'] == 0 && $slug != NULL) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
            'section' => 'Aanbiedingen',
            'currentPage' => 'Nieuw',
            'companies' => $this->companies,
            'company' => $this->isCompanyOwner($slug),
            'slug' => $slug,
        ]);
    }

    public function createAction(Request $request, $slug = NULL) 
    {
        if ($this->isCompanyOwner($slug)['exist'] == 0 && $slug != NULL && Sentinel::inRole('admin') == fALSE) {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }

        $this->validate($request, [
            'name' => 'required',
            'date_from' => 'required',
            'date_to' => 'required',
            'time_to' => 'required',
            'time_from' => 'required'
        ]);
        
        $data = new ReservationOption();
        $data->name = $request->input('name');
        $data->description = $request->input('content');
        $data->total_amount = $request->input('total_amount');
        $data->time_to = $request->input('time_to');    
        $data->time_from = $request->input('time_from');    
        $data->date_from = $request->input('date_from');    
        $data->date_to = $request->input('date_to');    
        $data->company_id = ($slug != NULL ? $this->isCompanyOwner($slug)['id'] : $request->input('company_id'));    
        $data->save();

        Alert::success('U heeft succesvol een nieuwe reserverings optie aangemaakt.')->persistent('Sluiten');
        
        return Redirect::to('admin/'.$this->slugController.'/create');
    }

    public function update(Request $request, $id) 
    {
        $data = ReservationOption::select(
            'reservations_options.id',
            'reservations_options.company_id',
            'reservations_options.total_amount',
            'reservations_options.time_from',
            'reservations_options.time_to',
            'reservations_options.date_to',
            'reservations_options.date_from',
            'reservations_options.description',
            'reservations_options.name',
            'companies.slug'
        )
            ->leftJoin('companies', 'reservations_options.company_id', '=', 'companies.id')
            ->where('reservations_options.id', $id)
            ->first()
        ;

        if ($data) {
            if ($this->isCompanyOwner($data->slug)['exist'] == 0 && Sentinel::inRole('admin') == fALSE) {
                User::getRoleErrorPopup();
                return Redirect::to('/');
            }

            return view('admin/'.$this->slugController.'/update', [
                'slugController' => $this->slugController.(trim($data->slug) != '' ? '/'.$data->slug : ''),
                'section' => 'Aanbiedingen',
                'currentPage' => 'Wijzigen',
                'companies' => $this->companies,
                'company' => $this->isCompanyOwner($data->slug),
                'slug' => $data->slug,
                'data' => $data,
            ]);
        } else {
            App::abort(404);
        }
    }

    public function updateAction(Request $request, $id) 
    {
        $data = ReservationOption::select(
            'reservations_options.id',
            'reservations_options.company_id',
            'reservations_options.total_amount',
            'reservations_options.time_from',
            'reservations_options.time_to',
            'reservations_options.date_to',
            'reservations_options.date_from',
            'reservations_options.description',
            'reservations_options.name',
            'companies.slug'
        )
            ->leftJoin('companies', 'reservations_options.company_id', '=', 'companies.id')
            ->where('reservations_options.id', $id)
            ->first()
        ;

        $this->validate($request, [
            'name' => 'required',
            'date_from' => 'required',
            'date_to' => 'required',
            'time_to' => 'required',
            'time_from' => 'required'
        ]);
        
        if ($data) {
            if ($this->isCompanyOwner($data->slug)['exist'] == 0 && Sentinel::inRole('admin') == fALSE) {
                User::getRoleErrorPopup();
                return Redirect::to('/');
            }    

            $data->name = $request->input('name');
            $data->description = $request->input('content');
            $data->total_amount = $request->input('total_amount');
            $data->time_to = $request->input('time_to');    
            $data->time_from = $request->input('time_from');    
            $data->date_from = $request->input('date_from');    
            $data->date_to = $request->input('date_to');    
            $data->save();

            Alert::success('U heeft succesvol deze reserverings optie aangepast.')->persistent('Sluiten');
            
            return Redirect::to('admin/'.$this->slugController.'/update/'.$id);
        }

    }

}