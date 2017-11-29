<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\Company;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class ReviewsController extends Controller 
{
    public function __construct()
    {
        $this->slugController = 'reviews';
        $this->section = 'Recencies';
        $this->companies = Company::all();
    }

    public function index(Request $request, $slug = NULL)
    {
        $data = Review::select(
            'users.name', 
            'reviews.*', 
            'companies.name as company'
        )
            ->leftJoin('users', 'users.id', '=', 'reviews.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'reviews.company_id')
        ;
        
        if ($slug != null) {
            $data = $data->where('companies.slug', $slug);
        }

         if (!Sentinel::inRole('admin'))  {
            if (Sentinel::inRole('bedrijf'))  {
                $data = $data->where('companies.user_id', Sentinel::getUser()->id);
            } elseif (Sentinel::inRole('bediening'))  {
                $data = $data->where('companies.waiter_user_id', Sentinel::getUser()->id);
            }
        }

        if ($request->has('q'))  {
            $data = $data->where('content', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy(($request->has('sort') == 'company' ? 'companies.name' : $request->input('sort')), $request->input('order'));
            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('reviews.id', 'desc');
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
            'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'companies' => $this->companies, 
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function updateAction(Request $request, $slug = NULL)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);
       
        if($companyOwner['is_owner'] == TRUE || Sentinel::inRole('admin')) {
            switch ($request->get('action')) {
                case 'accept':
                    if($request->has('id'))  {
                        foreach($request->get('id') as $key => $value) {
                            $update = Review::find($value);
                            $update->is_approved = 1;
                            $update->save();
                        }
                    }
                    break;

                case 'decline':
                    if($request->has('id'))
                    {
                        foreach($request->get('id') as $key => $value) 
                        {
                            $update = Review::find($value);
                            $update->is_approved = 0;
                            $update->save();
                        }
                    }
                    break;

                case 'remove':
                    if($request->has('id'))
                    {
                        foreach($request->get('id') as $key => $value) 
                        {
                            $delete = Review::find($value);
                            $delete->delete();
                        }
                    }
                    break;
            }
        }

       return Redirect::to('admin/'.$this->slugController.($companyOwner['is_owner'] == TRUE ? '/'.$slug : ''));
    }
}