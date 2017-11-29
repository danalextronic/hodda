<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\FaqCategory;
use Config;
use DB;
use Illuminate\Http\Request;
use Redirect;

class FaqController extends Controller 
{
    public function __construct()
    {
        $this->slugController = 'faq';
        $this->section = 'FAQ';
    }

    public function index(Request $request)
    {
        $data = Faq::select(
            'id',
            'title',
            'clicks',
            DB::raw('(SELECT name FROM faq_categories WHERE id = faq_questions.category ) as categoryName'),
            DB::raw('(SELECT name FROM faq_categories WHERE id = faq_questions.subcategory ) as subcategoryName')
        );

        if ($request->has('q')) {
            $data = $data->where('title', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('id', 'desc');
        }

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
            'slugController' => $this->slugController,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'category' => Config::get('preferences.faq'),
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function create()
    {
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe vraag',
            'categories' => FaqCategory::whereNull('category_id')->lists('name', 'id')
        ]);
    }

    public function update($id)
    {
        $data = Faq::find($id);

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'categories' => FaqCategory::whereNull('category_id')->lists('name', 'id'),
            'subcategories' => FaqCategory::whereNotNull('category_id')->lists('name', 'id'),
            'currentPage' => 'Wijzig vraag'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'title' => 'required',
            'content' => 'required'
        ]);

        $data = new Faq;
        $data->title = $request->input('title');
        $data->answer = $request->input('content');
        $data->category = $request->input('category');
        $data->subcategory = $request->input('subcategory');
        $data->save();
        
        Alert::success('Deze vraag is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'title' => 'required',
            'content' => 'required'
        ]);

        $data = Faq::find($id);
        $data->title = $request->input('title');
        $data->answer = $request->input('content');
        $data->category = $request->input('category');
        $data->subcategory = $request->input('subcategory');
        $data->save();

        Alert::success('Deze vraag is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController);
    }

    public function deleteAction(Request $request)
    {
        if($request->has('id'))
        {
            Faq::whereIn('id', $request->input('id'))->delete(); 
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }
}