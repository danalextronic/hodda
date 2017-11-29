<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\UserBan;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;
use Sentinel;

class UsersBanController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'bans';
        $this->section = 'Bannen';
    }

    public function index(Request $request)
    {
        $data = UserBan::select(
            'users.name',
            'users_bans.id',
            'users_bans.user_id',
            'users_bans.reason',
            'users_bans.expired_date'
        )
            ->leftJoin('users', 'users.id', '=', 'users_bans.user_id')
        ;

        if ($request->has('sort') && $request->has('order'))  {
            $data = $data->orderBy('users_bans.'.$request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('users_bans.id', 'asc');
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

    public function create($id = null)
    {
        if ($id != NULL) {
            $data = Sentinel::findById($id);
        }

        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'user' => isset($data) ? $data : '', 
            'currentPage' => 'Nieuwe ban'
        ]);
    }

    public function update($id)
    {
        $data = UserBan::select(
            'users.name',
            'users_bans.id',
            'users_bans.user_id',
            'users_bans.reason',
            'users_bans.expired_date'
        )
            ->leftJoin('users', 'users.id', '=', 'users_bans.user_id')
            ->find($id)
        ;

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig ban'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'user_id' => 'required'
        ]);

        $data = new UserBan;
        $data->user_id = $request->input('user_id');
        $data->reason = $request->input('reason');
        $data->expired_date = date('Y-m-d', strtotime('+'.$request->input('days').' days'));
        $data->save();

        Alert::success('Deze gebruiker is succesvol verbannen.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'title' => 'required|unique:pages,title,'.$id,
            'content' => 'required'
        ]);

        $data = UserBan::find($id);
        $data->user_id = $request->input('user_id');
        $data->reason = $request->input('reason');
        $data->expired_date = date('Y-m-d', strtotime('+'.$request->input('days').' days'));
        $data->save();

        Alert::success('Deze verbanning is succesvol gewijzigd.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if ($request->input('id') != '') {
            foreach($request->input('id') as $id) {
                $data = UserBan::find($id);

                if($data != '') {
                    $data->delete();
                }
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }
}