<?php
namespace App\Http\Controllers\Admin;

use App;
use Alert;
use App\Http\Controllers\Controller;
use App\Models\Practice;
use App\Models\Invoice;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class PracticeController extends Controller 
{

    public function __construct(Request $request)
    {
       	$this->slugController = 'practice';
       	$this->section = 'Oefeningen';
        $this->companies = Practice::all();
    }

    public function index(Request $request, $slug = NULL)
    {   
        $limit = $request->input('limit', 15);

        $dropdownData = Practice::select()->groupBy('cat')->orderBy('cat', 'ASC')->get();

        $data =  Practice::select();

        if ($request->has('q')) {
            $data = $data->where('cat', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($slug != NULL) {
            $data = $data->where('slug', '=', $slug);
        }

        if ($request->has('sort') && $request->has('order'))  {
            $companiesColumn = array(
                'company',
                'end_date'
            );

            if ($request->input('sort') == 'price') {
                $data = $data->orderBy('name', $request->input('order'));
            } else if (in_array($request->input('sort'), $companiesColumn)) {
                $data = $data->orderBy('name', $request->input('order'));
            } else {
                $data = $data->orderBy(''.$request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('id', 'desc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($limit);
        $data->setPath($this->slugController);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);

        return view('admin/'.$this->slugController.'/index', [
            'data' => $data, 
            'dropdownData' => $dropdownData, 
            'countItems' => $dataCount,
            'slugController' => $this->slugController,
            'section' => $this->section,
            'companies' => $this->companies,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $limit,
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function create($slug = null)
    {
        $Cats = array();
        $dbCats = Practice::select()->groupBy('cat')->orderBy('cat', 'ASC')->get();
        foreach($dbCats AS $Key => $Value)
        {
	        $Cats[$Value->cat] = $Value->cat;
        }
	    
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe oefening',
            'DropdownItems' => $Cats
        ]);
    }

    public function createAction(Request $request)
    {
        $rules = [
            'name' => 'required',
            'cat' => 'required',
            'sets' => 'required',
        ];
        
        $Food = new Practice();
        $Food->name = $request->input('name');
        $Food->cat = $request->input('cat');
        $Food->sets = $request->input('sets');
        
        $Food->pectoralis = str_replace(',', '.', $request->input('pectoralis'));
        $Food->anterior_delts = str_replace(',', '.', $request->input('anterior_delts'));
        $Food->lateral_delts = str_replace(',', '.', $request->input('lateral_delts'));
        $Food->posterior_delts = str_replace(',', '.', $request->input('posterior_delts'));
        $Food->biceps = str_replace(',', '.', $request->input('biceps'));
        $Food->triceps = str_replace(',', '.', $request->input('triceps'));
        $Food->upper_traps = str_replace(',', '.', $request->input('upper_traps'));
        $Food->middel_traps = str_replace(',', '.', $request->input('middel_traps'));
        $Food->lower_traps = str_replace(',', '.', $request->input('lower_traps'));
        $Food->latissimus_dorsi = str_replace(',', '.', $request->input('latissimus_dorsi'));
        $Food->quadriceps = str_replace(',', '.', $request->input('quadriceps'));
        $Food->hamstrings = str_replace(',', '.', $request->input('hamstrings'));
        $Food->gluteus_maximus = str_replace(',', '.', $request->input('gluteus_maximus'));
        $Food->calves = str_replace(',', '.', $request->input('calves'));
        $Food->youtubelink = $request->input('youtubelink');
        $Food->picture = $request->input('picture');
        $Food->save();

        Alert::success('Oefening is succesvol aangemaakt.')->persistent('Sluiten');   

        return Redirect::to('admin/practice');
    }

    public function update($id)
    {
        $data = Practice::find($id);
        
        $Cats = array();
        $dbCats = Practice::select()->groupBy('cat')->orderBy('cat', 'ASC')->get();
        foreach($dbCats AS $Key => $Value)
        {
	        $Cats[$Value->cat] = $Value->cat;
        }

        if(count($data) >= 1) {
            return view('admin/'.$this->slugController.'/update', [
                'data' => $data,
                'section' => $this->section, 
                'slugController' => $this->slugController,
                'currentPage' => 'Wijzig voedingsmiddel',
                'DropdownItems' => $Cats
            ]);
	    } else {
            App::abort(404);
        }
    }

    public function updateAction(Request $request, $id)
    {
        $rules = [
            'name' => 'required',
            'cat' => 'required',
            'kcal' => 'required',
            'eiwit' => 'required',
            'koolhydraat' => 'required',
            'vezels' => 'required',
            'vet' => 'required',
            'gram' => 'required'
        ];

        $Food = Practice::find($id);
        $Food->name = $request->input('name');
        $Food->cat = $request->input('cat');
        $Food->sets = $request->input('sets');
        
        $Food->pectoralis = str_replace(',', '.', $request->input('pectoralis'));
        $Food->anterior_delts = str_replace(',', '.', $request->input('anterior_delts'));
        $Food->lateral_delts = str_replace(',', '.', $request->input('lateral_delts'));
        $Food->posterior_delts = str_replace(',', '.', $request->input('posterior_delts'));
        $Food->biceps = str_replace(',', '.', $request->input('biceps'));
        $Food->triceps = str_replace(',', '.', $request->input('triceps'));
        $Food->upper_traps = str_replace(',', '.', $request->input('upper_traps'));
        $Food->middel_traps = str_replace(',', '.', $request->input('middel_traps'));
        $Food->lower_traps = str_replace(',', '.', $request->input('lower_traps'));
        $Food->latissimus_dorsi = str_replace(',', '.', $request->input('latissimus_dorsi'));
        $Food->quadriceps = str_replace(',', '.', $request->input('quadriceps'));
        $Food->hamstrings = str_replace(',', '.', $request->input('hamstrings'));
        $Food->gluteus_maximus = str_replace(',', '.', $request->input('gluteus_maximus'));
        $Food->calves = str_replace(',', '.', $request->input('calves'));
        $Food->youtubelink = $request->input('youtubelink');
        $Food->picture = $request->input('picture');
        $Food->save();

        return Redirect::to('admin/practice/update/'.$id);
    }

    public function deleteAction(Request $request)
    {
        if($request->has('id')) {
            $data = Practice::whereIn('id', $request->input('id'));

            if($data->count() >= 1) {
                $data->delete();
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/practice');
    }
}