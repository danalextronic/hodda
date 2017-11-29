<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use App\Models\Content;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class ContentsController extends Controller 
{
    public function __construct()
    {
        $this->slugController = 'contents';
        $this->section        = 'Tekstblokken';
    }

    public function index(Request $request)
    {

        $data = new Content;

        if($request->has('q'))
        {
            $data = $data->where('name', 'LIKE', '%'.$request->input('q').'%');
        }

        if($request->has('sort') && $request->has('order'))
        {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        }
        else
        {
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
            'data'                  => $data, 
            'countItems'            => $dataCount, 
            'slugController'        => $this->slugController,
            'queryString'           => $queryString,
            'paginationQueryString' => $request->query(),
            'limit'                 => $request->input('limit', 15),
            'section'               => $this->section, 
            'currentPage'           => 'Overzicht'        
        ]);
    }

    public function create()
    {
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section'        => $this->section, 
            'currentPage'    => 'Nieuw tekstblok'
        ]);
    }

    public function update($id)
    {
        $data = Content::find($id);

        return view('admin/'.$this->slugController.'/update', [
            'data'           => $data,
            'slugController' => $this->slugController,
            'section'        => $this->section, 
            'currentPage'    => 'Wijzig tekstblok'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'name'       => 'required|unique:content_blocks',
            'content'    => 'required'
        ]);

        $data              = new Content;
        $data->slug        = str_slug($request->input('name'));
        $data->name        = $request->input('name');
        $data->content     = $request->input('content');
        $data->category    = $request->input('category');
        $data->save();
        
        Alert::success('Dit tekstblok is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'name'       => 'required|unique:content_blocks,name,'.$id,
        ]);

        $data              = Content::find($id);
        $data->slug        = str_slug($request->input('name'));
        $data->name        = $request->input('name');
        $data->content     = $request->input('content');
        $data->category    = $request->input('category');
        $data->save();

        Alert::success('Dit tekstblok is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if($request->input('id') != '')
        {
            foreach($request->input('id') as $id)
            {
                $data = Content::find($id);

                if($data != '')
                {
                    $data->delete();
                }
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }
}