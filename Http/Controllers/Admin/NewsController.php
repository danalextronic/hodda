<?php
namespace App\Http\Controllers\Admin;

use App;
use Alert;
use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\Company;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class NewsController extends Controller 
{

    public function __construct(Request $request)
    {
       	$this->slugController = 'news';
       	$this->section = 'Nieuws';
       	$this->admin = Sentinel::inRole('admin');
        $this->companies = Company::orderBy('id', 'asc');
    }

    public function index(Request $request, $slug = null)
    {   
        $limit = $request->input('limit', 15);

        $userCompanyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        $data = News::select(
            'news.*',
            'companies.name as name'
        )
            ->leftJoin('companies', 'companies.id', '=', 'news.company_id')
        ;

        if (Sentinel::inRole('bedrijf')) {
            $data = $data->where('companies.user_id', Sentinel::getUser()->id);
        }

        if ($request->has('q')) {
            $data = $data->where('title', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('news.id', 'desc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($limit);
        $data->setPath($this->slugController.(trim($slug) != '' ? '/'.$slug : ''));

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);
        
        if ($data) {
            return view('admin/'.$this->slugController.'/index', [
                'data' => $data, 
                'countItems' => $dataCount,
                'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
                'section' => $this->section,
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $limit,
                'currentPage' => 'Overzicht'        
            ]);
        } else {
            App::abort(404);
        }
    }

    public function create($slug = null)
    {
        if (Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id) || Sentinel::inRole('admin')) {
            return view('admin/'.$this->slugController.'/create', [
                'admin' => $this->admin,
                'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
                'section' => $this->section, 
                'slug' => (trim($slug) != '' ? $slug.'/' : ''),
                'currentPage' => 'Nieuw nieuwsbericht',
                'companies' => $this->companies->lists('name', 'id')
            ]);
        } else {
            App::abort(404);
        }
    }

    public function update($id)
    {
        $data = News::select(
            'news.*'
        )
            ->leftJoin('companies', 'companies.id', '=', 'news.company_id')
            ->find($id)
        ;

        if(count($data) >= 1) {
            if(Company::isCompanyUser($data->company_id, Sentinel::getUser()->id) || Sentinel::inRole('admin')){
                $mediaItems = $data->getMedia(); 

    	        return view('admin/'.$this->slugController.'/update', [
                    'media' => $mediaItems,
    	            'data' => $data,
    	            'admin' => $this->admin,
    	            'section' => $this->section, 
    	            'slugController' => $this->slugController.(trim($data->slug) != '' ? '/'.$data->slug : ''),
                    'currentPage' => 'Wijzig nieuwsbericht',
                	'companies' => $this->companies->lists('name', 'id')
    	        ]);
            } else {
    	    	App::abort(404);
            }
        } else {
            App::abort(404);
        }
    }

    public function createAction(Request $request, $slug = null)
    {
        $rules = [
            'title' => 'required',
            'content' => 'required',
            'images' => 'max:6'
        ];

        $images = count($request->file('images')) - 1;
        $files = $request->file('images');

        if ($request->hasFile('images')) {
            foreach (range(0, $images) as $index) {
                $rule['images.' . $index] = 'required|mimes:jpeg,jpg,png';
            }

            $rules = array_merge($rules, $rule);
        }

        $this->validate($request, $rules);

        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner || Sentinel::inRole('admin')) {
			$data = News::create([
                'title' => $request->input('title'),
		    	'meta_description' => $request->input('meta_description'),
		    	'content' => $request->input('content'),
		    	'company_id' => ($request->has('company') ? $request->input('company') : $companyOwner['company_id']),
		    	'is_published' => ($request->has('is_published') ? $request->input('is_published') : 0),
			]);

            if ($request->hasFile('images')) {
                foreach($files as $file) {
                    $data->addMedia($file)->toMediaLibrary();
                }
            }

	        Alert::success('Dit nieuwsbericht is succesvol aangemaakt.')->persistent('Sluiten');   
            return Redirect::to('admin/news'.($slug != null ? '/'.$slug : ''));
	    } else {
	    	App::abort(404);
	    }
    }

    public function updateAction(Request $request, $id)
    {
        $rules = [
            'title' => 'required',
            'content' => 'required',
            'images' => 'max:6'
        ];

        $images = count($request->file('images')) - 1;
        $files = $request->file('images');

        if ($request->hasFile('images'))  {
            foreach(range(0, $images) as $index)  {
                $rule['images.' . $index] = 'required|mimes:jpeg,jpg,png';
            }

            $rules = array_merge($rules, $rule);
        }

        $this->validate($request, $rules);

        $data = News::find($id);

        if (Company::isCompanyUser($data->company_id, Sentinel::getUser()->id) || Sentinel::inRole('admin')) {
            if ($request->hasFile('images')) {
                foreach($files as $file) {
                    $data->addMedia($file)->toMediaLibrary();
                }
            }

	        $data->update([
		    	'title' => $request->input('title'),
                'content' => $request->input('content'),
		    	'meta_description' => $request->input('meta_description'),
		    	'company_id' => $data->company_id,
		    	'is_published' => ($request->has('is_published') ? $request->input('is_published') : 0),
			]);

            Alert::success('Dit nieuwsbericht is succesvol aangepast.')->persistent('Sluiten');   
            return Redirect::to('admin/news/update/'.$data->id);
	    } else {
	    	App::abort(404);
	    }
    }

    public function deleteAction(Request $request)
    {
        if ($request->input('id') != '') {
            $data = News::join('companies', 'companies.id', '=', 'news.company_id');

            if (Sentinel::inRole('bedrijf')) {
                $data = $data->where('companies.user_id',  Sentinel::getUser()->id);
            }

            $data = $data->whereIn('news.id', $request->input('id'));

            if ($data->count() >= 1) {
                $data->delete();
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }
}