<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class PagesController extends Controller 
{
    public function __construct()
    {
        $this->slugController = 'pages';
        $this->section = 'Pagina\'s';
    }

    public function index(Request $request)
    {
        $data = new Page;

        if($request->has('q')) {
            $data = $data->where('title', 'LIKE', '%'.$request->input('q').'%');
        }

        if($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('id', 'desc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));
        $data->setPath($this->slugController);
        
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
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe pagina'
        ]);
    }

    public function update($id)
    {
        $data = Page::find($id);

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig pagina'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'title' => 'required|unique:pages',
            'content' => 'required'
        ]);

        $data = new Page;
        $data->slug = str_slug($request->input('title'));
        $data->meta_description = $request->input('meta_description');
        $data->title = $request->input('title');
        $data->content = $request->input('content');
        $data->category_id = $request->input('category');
        $data->link_to = $request->input('link_to');
        $data->is_hidden = ($request->input('is_hidden') == 0 ? 1 : 1);
        $data->save();

        Alert::success('Deze pagina is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'title' => 'required|unique:pages,title,'.$id,
            'content' => 'required'
        ]);

        $data = Page::find($id);
        $data->slug = str_slug($request->input('title'));
        $data->meta_description = $request->input('meta_description');
        $data->title = $request->input('title');
        $data->content = $request->input('content');
        $data->category_id = $request->input('category');
        $data->link_to = $request->input('link_to');
        $data->is_hidden  = $request->input('is_hidden');
        $data->save();

        Alert::success('Deze pagina is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if ($request->input('id') != '') {
            foreach ($request->input('id') as $id) {
                $data = Page::find($id);

                if ($data != '') {
                    $data->delete();
                }
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }
}