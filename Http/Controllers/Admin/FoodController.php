<?php
namespace App\Http\Controllers\Admin;

use App;
use Alert;
use App\Http\Controllers\Controller;
use App\Models\FoodCategory;
use App\Models\Food;
use App\Models\Invoice;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class FoodController extends Controller 
{

    public function __construct(Request $request)
    {
       	$this->slugController = 'food';
       	$this->section = 'Voedingsmiddelen';
        $this->companies = Food::all();
    }

    public function index(Request $request, $slug = NULL)
    {   
        $limit = $request->input('limit', 15);

        $dropdownData = Food::select()->groupBy('cat')->orderBy('cat', 'ASC')->get();

        $data =  Food::select();

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
        $dbCats = Food::select()->groupBy('cat')->orderBy('cat', 'ASC')->get();
        foreach($dbCats AS $Key => $Value)
        {
	        $Cats[$Value->cat] = $Value->cat;
        }
	    
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe voedingsmiddel',
            'DropdownItems' => $Cats
        ]);
    }

    public function createAction(Request $request)
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
        
        $Food = new Food();
        $Food->name = $request->input('name');
        $Food->cat = $request->input('cat');
        $Food->kcal = $request->input('kcal');
        $Food->eiwit = $request->input('eiwit');
        $Food->koolhydraat = $request->input('koolhydraat');
        $Food->vezels = $request->input('vezels');
        $Food->vet = $request->input('vet');
        $Food->gram = $request->input('gram');
        $Food->save();

        Alert::success('Voedingsmiddel is succesvol aangemaakt.')->persistent('Sluiten');   

        return Redirect::to('admin/food');
    }

    public function update($id)
    {
        $data = Food::find($id);
        
        $Cats = array();
        $dbCats = Food::select()->groupBy('cat')->orderBy('cat', 'ASC')->get();
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

        $Food = Food::find($id);
        $Food->name = $request->input('name');
        $Food->cat = $request->input('cat');
        $Food->kcal = $request->input('kcal');
        $Food->eiwit = $request->input('eiwit');
        $Food->koolhydraat = $request->input('koolhydraat');
        $Food->vezels = $request->input('vezels');
        $Food->vet = $request->input('vet');
        $Food->gram = $request->input('gram');
        $Food->save();

        return Redirect::to('admin/food/update/'.$id);
    }

    public function deleteAction(Request $request)
    {
        if($request->has('id')) {
            $data = Food::whereIn('id', $request->input('id'));

            if($data->count() >= 1) {
                $data->delete();
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/food');
    }
}