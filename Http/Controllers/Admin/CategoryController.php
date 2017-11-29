<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Page;
use App\Models\AffiliateCategory;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use DB;
use Redirect;

class CategoryController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'categories';
        $this->section = 'Hoofdrubrieken';
    }

    public function index(Request $request)
    {
        $data = Category::select(   
            'categories.id',
            'categories.name',
            'categories.slug',
            DB::raw('count(affiliates.name) as programCount')
        )
            ->leftJoin('categories as extra', function ($join) {
                $join
                    ->on('extra.extra_categories_id', 'REGEXP', DB::raw('concat(\'[[:<:]]\', categories.id, \'[[:>:]]\')'))
                ;
            })
            ->leftJoin('affiliates_categories', function ($join) {
                $join
                    ->on('affiliates_categories.category_id', '=', 'categories.id')
                    ->orOn('affiliates_categories.category_id', '=', 'extra.id')
                ;
            })
            ->leftJoin('affiliates', function ($join) {
                $join
                    ->on('affiliates.id', '=', 'affiliates_categories.affiliate_id')
                ;
            })
            ->where('categories.subcategory_id', 0)
            ->groupBy('categories.name')
        ;

        if ($request->has('q')) {
            $data = $data->where('categories.name', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            switch ($request->input('sort')) {
                case 'programCount':
                    $data = $data->orderByRaw('(SELECT count(id) FROM affiliates_categories WHERE affiliates_categories.category_id = categories.id) '.$request->input('order'));
                    break;

                case 'name':
                    $data = $data->orderBy('categories.name', $request->input('order'));
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
            'data' => $data, 
            'countItems' => $dataCount, 
            'slugController' => $this->slugController,
            'sessionSort' => $request->session()->get('sort'),
            'sessionOrder' => $request->session()->get('order'),
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'currentPage' => 'Overzicht'
        ]);
    }

    public function merge()
    {
        $categories = Category::select(
            'categories.*'
        )
            ->whereRaw('(SELECT count(*) FROM affiliates_categories WHERE affiliates_categories.category_id = categories.id) >= 1')
            ->where('subcategory_id', 0)
            ->orderBy('name', 'asc')
            ->lists('name', 'id')
        ;

        return view('admin/'.$this->slugController.'/merge', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'categories' => $categories,
            'currentPage' => 'Categorieen samenvoegen'
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
                $subCategories = Category::whereIn('subcategory_id', $categoryIds)->get();
                $affiliateCategories = AffiliateCategory::whereIn('category_id', $categoryIds)->get();

                foreach ($affiliateCategories as $affiliateCategory) {
                    $transferIds[] = $affiliateCategory->id;
                }

                foreach ($subCategories as $subCategory) {
                    $transferSubIds[] = $subCategory->id;
                }

                if (isset($transferIds)) {
                    if ($request->has('name')) {
                        $newCategory = new Category();
                        $newCategory->slug = str_slug($request->input('name'));
                        $newCategory->name = $request->input('name');
                        $newCategory->save();
                    } else {
                        $newCategory = Category::find($request->input('categoryExist'));
                    }

                    if (isset($transferSubIds) && $request->input('subcategory') == 1) {
                        Category::whereIn('id', $transferSubIds)
                            ->update(array(
                                'subcategory_id' => $newCategory->id
                            ))
                        ;
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

    public function create()
    {
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe rubriek'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:categories'
        ]);

        $data = new Category;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->no_show = $request->input('no_show');
        $data->save();

        Alert::success('Dit hoofdrubriek is succesvol aangemaakt.')->persistent('Sluiten');

        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function update($id)
    {
        $data = Category::with('media')
            ->find($id)
        ;

        $mediaItems = $data->getMedia();

        $pages = Page::lists('title', 'id');

        return view('admin/'.$this->slugController.'/update', [
            'mediaItems' => $mediaItems,
            'pages' => $pages,
            'data' => $data,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig rubriek'
        ]);
    }

    public function updateAction($id, Request $request)
    {
        $this->validate($request, [
            'name' => 'required|unique:categories,name,'.$id
        ]);

        $data = Category::find($id);
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->no_show = $request->input('no_show');
        $data->ad_page_id = $request->input('ad_page_id');
        $data->save();

        if ($request->hasFile('ad')) {
            $data->clearMediaCollection(); 

            $data
                ->addMedia($request->file('ad'))
                ->toMediaLibrary()
            ;
        }

        Alert::success('Dit hoofdrubriek is succesvol aangepast.')->persistent('Sluiten');

        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if ($request->input('id') != '') {
            foreach ($request->input('id') as $id) {
                $data = Category::find($id);

                if($data != '') {
                    $data->delete();
                }
            }
        }

        return Redirect::to('admin/'.$this->slugController);
    }

    public function deleteImage(Request $request, $id, $image)
    {
        $data = Category::with('media')
            ->find($id)
        ;

        if ($data) {
           
            $mediaItems = $data->getMedia();
            
            if (isset($mediaItems[$image])) {
                $mediaItems[$image]->delete();
            }

            return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
        } else {
            return Redirect::to('admin/'.$this->slugController);
        }
    }

}