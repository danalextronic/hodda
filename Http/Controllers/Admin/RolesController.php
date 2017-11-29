<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class RolesController extends Controller 
{
    public function __construct()
    {
        $this->slugController = 'roles';
        $this->section = 'Rollen';
    }

    public function index(Request $request)
    {
        $data = Sentinel::getRoleRepository();

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
            'currentPage'    => 'Nieuwe rol'
        ]);
    }

    public function update($id)
    {
        $data = Sentinel::findRoleById($id);

        return view('admin/'.$this->slugController.'/update', [
            'data'           => $data,
            'section'        => $this->section, 
            'slugController' => $this->slugController,
            'section'        => $this->section, 
            'currentPage'    => 'Wijzig rol'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:roles'
        ]);

        $data = Sentinel::getRoleRepository()->createModel()->create([
            'name' => $request->input('name'),
            'slug' => str_slug($request->input('name')),
        ]);

        Alert::success('Deze rol is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'name'    => 'required|unique:roles,name,'.$id
        ]);

        $data = Sentinel::findRoleById($id);
        $data->name = $request->input('name');
        $data->save();
    
        Alert::success('Deze rol is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if($request->input('id') != '')
        {
            foreach($request->input('id') as $id)
            {
                $data = Sentinel::findRoleById($id);

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