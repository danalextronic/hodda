<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\FaqCategory;
use Illuminate\Http\Request;
use Redirect;
use DB;

class FaqCategoryController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'faq_categories';
        $this->section = 'FAQ Categorie&euml;n';
    }

    public function indexParent(Request $request)
    {
        $data = FaqCategory::whereNull('category_id');

        if($request->has('q')) {
            $data = $data->where('name', 'LIKE', '%'.$request->input('q').'%');
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
        unset($queryString['limit']);

        return view('admin/'.$this->slugController.'/index', [
            'data' => $data, 
            'countItems' => $dataCount, 
            'slugController' => 'faq/categories',
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function createParent()
    {
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => 'faq/categories',
            'section' => $this->section, 
            'currentPage' => 'Nieuwe categorie'
        ]);
    }

    public function createParentAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required'
        ]);

        $data = new FaqCategory;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->save();
        
        Alert::success('Deze categorie is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/faq/categories');
    }

    public function indexChild(Request $request)
    {
        $data = FaqCategory::select(
            DB::raw('(SELECT f.name FROM faq_categories f WHERE f.id = faq_categories.category_id) as categoryName'),
            'id',
            'name'
        )
        ->whereNotNull('category_id');

        if($request->has('q')) {
            $data = $data->where('name', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('id', 'asc');
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
        unset($queryString['limit']);

        return view('admin/'.$this->slugController.'/index_child', [
            'data' => $data, 
            'countItems' => $dataCount, 
            'slugController' => 'faq/categories/children',
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'currentPage' => 'Overzicht'
        ]);
    }
    
    public function createChild()
    {
        return view('admin/'.$this->slugController.'/create_child', [
            'slugController' => 'faq/categories/children',
            'section' => $this->section, 
            'currentPage' => 'Nieuwe subcategorie',
            'categories' => FaqCategory::whereNull('category_id')->lists('name', 'id')
        ]);
    }

    public function createChildAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required'
        ]);

        $data = new FaqCategory;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->category_id = $request->input('category');
        $data->save();
        
        Alert::success('Deze subcategorie is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/faq/categories');
    }

    public function updateChild($id)
    {
        $data = FaqCategory::find($id);

        return view('admin/'.$this->slugController.'/update_child', [
            'data' => $data,
            'slugController' => 'faq/categories/children',
            'section' => $this->section, 
            'categories' => FaqCategory::whereNull('category_id')->lists('name', 'id'),
            'currentPage' => 'Wijzig subcategorie'
        ]);
    }

    public function updateChildAction($id, Request $request)
    {
        $this->validate($request, [
            'name' => 'required'
        ]);

        $data = FaqCategory::find($id);
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->category_id = $request->input('category');
        $data->save();

        Alert::success('Deze subcategorie is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/faq/categories/update/child/'.$data->id);
    }

    public function updateParent($id)
    {
        $data = FaqCategory::find($id);

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'slugController' => 'faq/categories',
            'section' => $this->section, 
            'currentPage' => 'Wijzig categorie'
        ]);
    }

    public function updateParentAction($id, Request $request)
    {
        $this->validate($request, [
            'name' => 'required'
        ]);

        $data = FaqCategory::find($id);
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->save();

        Alert::success('Deze categorie is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/faq/categories/update/parent/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if ($request->has('id')) {
            FaqCategory::whereIn('id', $request->input('id'))->delete(); 
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent('Sluiten');
        return Redirect::to('admin/faq/categories');
    }
}