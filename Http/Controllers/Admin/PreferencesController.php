<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Preference;
use App\Models\Reservation;
use App\Models\Company;
use DB;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;
use Config;
use Setting;

class PreferencesController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'preferences';
        $this->section = 'Voorkeuren';
    }

    public function index(Request $request)
    {
        $data = new Preference;

        if($request->has('q'))  {
            $data = $data->where('name', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        }

        if ($request->has('filter'))  {
            $data = $data->where('category_id', $request->input('filter'));
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
        $websiteSettings = json_decode(json_encode(Setting::get('website')), true);

        $preferences = isset($websiteSettings['preferences']) ? json_decode($websiteSettings['preferences'], true) : Config::get('preferences.options');
        $preferences[9] = 'Stad';

        return view('admin/'.$this->slugController.'/create', [
            'preferences' => $preferences,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe voorkeur'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:preferences'
        ]);

        $data = new Preference;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->category_id = $request->input('category');
        $data->no_frontpage = $request->input('no_frontpage');
        $data->save();
        
        Alert::success('Deze voorkeur is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function update($id)
    {
        $data = Preference::find($id);

        $websiteSettings = json_decode(json_encode(Setting::get('website')), true);
        $preferences = isset($websiteSettings['preferences']) ? json_decode($websiteSettings['preferences'], true) : Config::get('preferences.options');
        $preferences[9] = 'Stad';

        return view('admin/'.$this->slugController.'/update', [
            'preferences' => $preferences,
            'data' => $data,
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig voorkeur'
        ]);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:content_blocks,name,'.$id,
            'category' => 'required'
        ]);

        $options = array(
            1 => 'preferences',
            2 => 'kitchens',
            3 => 'allergies',
            4 => 'price',
            5 => 'discount',
            6 => 'kids',
            7 => 'facilities',
            8 => 'sustainability'
        );

        if (isset($options[$request->input('category')])) {
            $companies = Company::select(           
                'id',
                'preferences',
                'name'
            )
                ->where($options[$request->input('category')], 'REGEXP', '"([^"]*)'.$request->input('old_value').'([^"]*)"')
                ->get()
            ;

            if ($options[$request->input('category')] == 'allergies' OR $options[$request->input('category')] == 'preferences') {
                $reservations = Reservation::select(           
                    'id'
                )
                    ->where($options[$request->input('category')], 'REGEXP', '"([^"]*)'.$request->input('old_value').'([^"]*)"')
                    ->get()
                ;
            }

            $users = Sentinel::getUserRepository()->select(           
                'id'
            )
                ->where($options[$request->input('category')], 'REGEXP', '"([^"]*)'.$request->input('old_value').'([^"]*)"')
                ->get()
            ;

            foreach ($companies as $company) {
                $ids[] = $company->id;
            }

            foreach ($users as $user) {
                $idsUsers[] = $user->id;
            }

            if (isset($reservations)) {
                foreach ($reservations as $reservation) {
                    $idsReservations[] = $reservation->id;
                }
            }

            if (isset($ids)) {
                Company::whereIn('id', $ids)
                    ->update(array(
                        $options[$request->input('category')] => DB::raw('(regexp_replace('.$options[$request->input('category')].', "'.$request->input('old_value').'", "'.str_slug($request->input('name')).'"))')
                    ))
                ;
            }

            if (isset($idsUsers)) {
                Sentinel::getUserRepository()->whereIn('id', $idsUsers)
                    ->update(array(
                        $options[$request->input('category')] => DB::raw('(regexp_replace('.$options[$request->input('category')].', "'.$request->input('old_value').'", "'.str_slug($request->input('name')).'"))')
                    ))
                ;
            }

            if (isset($idsReservations)) {
                Reservation::whereIn('id', $idsReservations)
                    ->update(array(
                        $options[$request->input('category')] => DB::raw('(regexp_replace('.$options[$request->input('category')].', "'.$request->input('old_value').'", "'.str_slug($request->input('name')).'"))')
                    ))
                ;
            }
        }

        $data = Preference::find($id);

         if ($request->hasFile('photo')) {
            $data->clearMediaCollection(); // All media will be deleted
            $data->addMedia($request->file('photo'))->toMediaLibrary();
        }

        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->no_frontpage = $request->input('no_frontpage');
        $data->category_id = $request->input('category');
        $data->save();
        
        Alert::success('Deze voorkeur is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }
    
    public function deleteAction(Request $request)
    {
        if ($request->input('id') != '') {
            foreach ($request->input('id') as $id) {
                $data = Preference::find($id);

                if ($data != '') {
                    $data->delete();
                }
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }
}