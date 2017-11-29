<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\AffiliateCategory;
use App\Models\SubCategory;
use Illuminate\Support\Facades\Response;
use Sentinel;
use Illuminate\Http\Request;
use DB;
use Redirect;

class SubCategoryController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'subcategories';
        $this->section = 'Subrubrieken';
        $this->admin = Sentinel::inRole('admin');
    }

    public function index(Request $request)
    {
        $categories = Category::all();
        $categoriesNames = $categories
            ->keyBy('id')
            ->map(function ($category) {
                return $category->name;
            })
        ;

        $data = Category::select(
            'categories.id',
            'categories.slug',
            'categories.name',
            'categories.subcategory_id',
            'categories.extra_categories_id',
            DB::raw('(SELECT count(affiliates_categories.id) FROM affiliates_categories WHERE affiliates_categories.category_id = categories.id) as programCount')
        )
            ->where('categories.subcategory_id', '!=', 0)
        ;

        if($request->has('q')) {
            $data = $data->where('categories.name', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            switch ($request->input('sort')) {
                case 'parentName':
                    $data = $data->orderByRaw('(SELECT parents.name FROM categories as parents WHERE parents.id = categories.subcategory_id) '.$request->input('order'));
                    break;
                    
                case 'programCount':
                    $data = $data->orderByRaw('(SELECT count(affiliates_categories.id) FROM affiliates_categories WHERE affiliates_categories.category_id = categories.id) '.$request->input('order'));
                    break;
                
                default:
                    $data = $data->orderBy($request->input('sort'), $request->input('order'));
                    break;
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('categories.id', 'desc');
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
            'categoriesNames' => $categoriesNames, 
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
            'admin' => $this->admin,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe subrubriek',
            'categories' => Category::where('subcategory_id', '=', 0)->lists('name', 'id')
        ]);
    }

    public function update($id)
    {
        $data = Category::find($id);

        $categories = array();

        if ($data->subcategory_id != null) {
            $categories[] = $data->subcategory_id;
        }

        if ($data->extra_categories_id != null) {
            foreach (json_decode($data->extra_categories_id) as $extraCategory) {
                $categories[] = (int)$extraCategory;
            }
        }

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'subcatCategories' => $categories,
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig subrubriek',
            'categories' => Category::where('subcategory_id', '=', 0)->lists('name', 'id')
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $this->validate($request, [
            'name' => 'required',
            'category' => 'required'
        ]);

        $categories = $request->input('category');
        unset($categories[0]);
        
        $data = Category::find($id);
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->no_show = $request->input('no_show');
        $data->subcategory_id = $request->input('category')[0];
        $data->extra_categories_id = json_encode(array_values($categories));
        $data->save();

        Alert::success('Deze subrubriek is succesvol aangepast.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:categories',
            'category' => 'required'
        ]);

        $data = new Category;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->no_show = $request->input('no_show');
        $data->subcategory_id = $request->input('category');
        $data->save();

        Alert::success('Deze subrubriek is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if ($request->input('id') != '') {
            foreach ($request->input('id') as $id) {
                $data = Category::find($id);

                if ($data != '') {
                    $data->delete();
                }
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }

    public function merge()
    {
        $categories = Category::select(
            'categories.*'
        )
            ->whereRaw('(SELECT count(*) FROM affiliates_categories WHERE affiliates_categories.category_id = categories.id) >= 1')
            ->where('subcategory_id', '>', 0)
            ->orderBy('name', 'asc')
            ->lists('name', 'id')
        ;

        $parentCategories = Category::select(
            'categories.*'
        )
            ->whereRaw('(SELECT count(*) FROM affiliates_categories WHERE affiliates_categories.category_id = categories.id) >= 1')
            ->where('subcategory_id', '=', 0)
            ->orderBy('name', 'asc')
            ->lists('name', 'id')
        ;

        return view('admin/'.$this->slugController.'/merge', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'parentCategories' => $parentCategories,
            'categories' => $categories,
            'currentPage' => 'Subcategorieen samenvoegen'
        ]);
    }

    public function mergeAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'unique:categories'
        ]);

        if ($request->has('category')) {
            foreach ($request->input('category') as $category) {
               $categoryIds[] = $category;
            }

            if (isset($categoryIds)) {
                $categories = Category::whereIn('id', $categoryIds)->get();
                $affiliateCategories = AffiliateCategory::whereIn('category_id', $categoryIds)->get();

                foreach ($affiliateCategories as $affiliateCategory) {
                    $transferIds[] = $affiliateCategory->id;
                }

                if (isset($transferIds)) {
                    if ($request->has('name')) {
                        $newCategory = new Category();
                        $newCategory->slug = str_slug($request->input('name'));
                        $newCategory->name = $request->input('name');
                        $newCategory->subcategory_id = $request->input('categoryId');
                        $newCategory->save();
                    } else {
                        $newCategory = Category::find($request->input('categoryExist'));
                    }

                    AffiliateCategory::whereIn('id', $transferIds)
                        ->update(array(
                            'category_id' => $newCategory->id
                        ))
                    ;

                    return Redirect::to('admin/'.$this->slugController);
                }
            }
        }
    }
}