<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\AffiliateCategory;
use App\Models\Affiliate;
use App\Models\Category;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Intervention\Image\ImageManagerStatic;
use Intervention\Image\Exception\NotReadableException;
use Illuminate\Http\Request;
use Redirect;
use DB;
use URL;

class AffiliatesController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'affiliates';
        $this->section = 'Affiliaties';

        $this->categoriesAll = Category::all();
        $this->categories = Category::select(
            'categories.id as catId',
            'categories.slug as catSlug',
            'categories.name as catName',
            'subcategories.id as subcatId',
            'subcategories.extra_categories_id as subExtra',
            'subcategories.slug as subcatSlug',
            'subcategories.name as subcatName',
            DB::raw('(SELECT count(*) FROM affiliates_categories WHERE affiliates_categories.category_id = catId) as countCategoryPrograms'),
            DB::raw('(SELECT count(*) FROM affiliates_categories WHERE affiliates_categories.category_id = subcatId) as countSubCategoryPrograms')
        )
            ->leftJoin('categories as subcategories', 'subcategories.subcategory_id', '=', 'categories.id')
            ->where('categories.subcategory_id', 0)
            ->orderBy('categories.name', 'asc')
            ->get()
        ;

        $this->categoriesArray = array();

        foreach ($this->categories as $categoriesFetch) {
            $categories[$categoriesFetch->catId] = array(
                'name' => $categoriesFetch->catName,
                'slug' => str_slug($categoriesFetch->catName),
                'name' => $categoriesFetch->catName,
                'countCategoryPrograms' => $categoriesFetch->countCategoryPrograms
            );
        }

        foreach ($this->categories as $categoriesFetch) {
            $subcategories[$categoriesFetch->catName][$categoriesFetch->subcatName] = array(
                'id' => $categoriesFetch->subcatId,
                'slug' => str_slug($categoriesFetch->subcatName),
                'name' => $categoriesFetch->subcatName,
                'countSubCategoryPrograms' => $categoriesFetch->countSubCategoryPrograms
            );

            // Extra subcategories connected to categories
            if (count(json_decode($categoriesFetch->subExtra)) >= 1) {
                foreach (json_decode($categoriesFetch->subExtra) as $subExtra) {
                    if (isset( $categories[$subExtra])) {
                        $catName = $categories[$subExtra]['name'];

                        $subcategories[$catName][$categoriesFetch->subcatName] = array(
                            'id' => $categoriesFetch->subcatId,
                            'slug' => str_slug($categoriesFetch->subcatName),
                            'name' => $categoriesFetch->subcatName,
                            'countSubCategoryPrograms' => $categoriesFetch->countSubCategoryPrograms
                        );
                    }
                }
            }
        }

        foreach ($this->categories as $categoriesFetch) {
            $this->categoriesArray[$categoriesFetch->catName] = array(
                'id' => $categoriesFetch->catId,
                'slug' => str_slug($categoriesFetch->catName),
                'name' => $categoriesFetch->catName,
                'subcategories' => $subcategories[$categoriesFetch->catName],
                'countCategoryPrograms' => $categoriesFetch->countCategoryPrograms
            );
        }
    }

    public function index(Request $request)
    {
        $data = Affiliate::select(
            'affiliates.id',
            'affiliates.name',
            'affiliates.clicks',
            'affiliates.no_show',
            'affiliates.compensations',
            'affiliates.updated_at',
            'categories.name as catName'
        )
            ->leftJoin('affiliates_categories', function ($join) {
                $join
                    ->on('affiliates_categories.affiliate_id', '=', 'affiliates.id')
                ;
            })
            ->leftJoin('categories', function ($join) {
                $join
                    ->on('affiliates_categories.category_id', '=', 'categories.id')
                ;
            })
            ->groupBy('affiliates.id')
        ;

        if ($request->has('network')) {
            $data = $data->where('affiliates.affiliate_network', '=', $request->input('network'));
        }

        if ($request->has('q')) {
            $data = $data
                ->where('affiliates.name', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('affiliates.compensations', 'REGEXP', '"([^"]*)'.$request->input('q').'([^"]*)"')
            ;
        }

        if ($request->has('id')) {
            $data = $data
                ->where('categories.id', '=', $request->input('id'))
                ->orWhere('categories.extra_categories_id', 'REGEXP', DB::raw("concat('[[:<:]]', ".$request->input('id').", '[[:>:]]')"))
            ;
        }

        if ($request->has('network')) {
            $data = $data->where('affiliates.affiliate_network', '=', $request->input('network'));
        }

        if ($request->has('sort') && $request->has('order')) {
            $companiesColumn = array(
                'catName'
            );

            if (in_array($request->input('sort'), $companiesColumn)) {
                $data = $data->orderBy('categories.name', $request->input('order'));
            } else {
                $data = $data->orderBy('affiliates.'.$request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        
        } else  {
            $data = $data->orderBy('affiliates.id', 'desc');
        }
        
        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));
        $data->setPath($this->slugController);

        # Redirct to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['network']);
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
            'currentPage' => 'Overzicht',
            'categories' => $this->categoriesArray
        ]);
    }

    public function create()
    {
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe partner',
            'categories' => Category::where('subcategory_id', '=', 0)->lists('name', 'id')
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, array(
            'name' => 'required|unique:affiliates'
        ));
        
        $data = new Affiliate;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->terms = $request->input('terms');
        $data->link = $request->input('link');
        $data->clicks = $request->input('clicks');
        $data->affiliate_network = strtolower($request->input('affiliate_network'));

        if ($request->has('commission')) {
            $data->compensations = json_encode($request->input('commission'));
        }

        if ($request->has('no_show')) {
            $data->no_show = $request->input('no_show') == 1 ? 1 : 0;
        }
        
        if ($request->has('program_id') && $request->input('affiliate_network') == 'tradedoubler') {
            $data->program_id = $request->input('program_id');
            $data->tracking_link = 'http://clk.tradedoubler.com/click?p='.$request->input('program_id').'&a=#SITEID#&g=0&epi=#SUB_ID#';
        }
        
        if ($request->hasFile('logo')) {
            $data->image_extension = $request->file('logo')->getClientOriginalExtension();
            
            try {
                ImageManagerStatic::make(
                    $request->file('logo')
                )
                    ->save(public_path('images/affiliates/'.$data->affiliate_network).'/'.$request->input('program_id').'.'.$request->file('logo')->getClientOriginalExtension())
                ;
            } catch (NotReadableException $e) {
            }
        }

        $data->save();

        if (!empty($request->input('category')))  {
            foreach ($request->input('category') as $value) {
                AffiliateCategory::create(['category_id' => $value, 'affiliate_id' => $data->id]);
            }
        }

        Alert::success('Deze partner is succesvol aangemaakt.')->persistent('Sluiten');

        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function update($id)
    {
        $data = Affiliate::find($id);

        $affiliateCategoriesQuery = AffiliateCategory::where('affiliate_id', $id)->get();
        $affiliateCategoriesArray = array();
       
        foreach ($affiliateCategoriesQuery as $affiliateCategoriesFetch) {
            $affiliateCategoriesArray[] = $affiliateCategoriesFetch->category_id;
        }

        $affiliateCategories = implode(',', $affiliateCategoriesArray);

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'section' => $this->section, 
            'currentPage' => 'Wijzig partner',
            'affiliateCategories'=> $affiliateCategories,
            'categories' => $this->categoriesArray,
            'slugController' => $this->slugController
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $this->validate($request, [
            'name' => 'required|unique:affiliates,name,'.$id,
            'link' => 'required'
        ]);

        $data = Affiliate::find($id);
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->terms = $request->input('terms');
        $data->program_id = $request->input('program_id');
        $data->link = $request->input('link');
        $data->tracking_duration = $request->input('tracking_duration');
        $data->percent_sales = $request->input('percent_sales');
        $data->time_duration_confirmed = $request->input('time_duration_confirmed');
        $data->clicks = $request->input('clicks');

        if ($request->has('commission')) {
            $data->compensations = json_encode($request->input('commission'));
        }

        $data->no_show = $request->input('no_show') == 1 ? 1 : 0;

        if ($request->has('program_id') && $request->input('affiliate_network') == 'tradedoubler') {
            $data->program_id = $request->input('program_id');
            $data->tracking_link = 'http://clk.tradedoubler.com/click?p='.$request->input('program_id').'&a=#SITEID#&g=0&epi=#SUB_ID#';
        }
        
        if ($request->hasFile('logo')) {
            $data->image_extension = $request->file('logo')->getClientOriginalExtension();
            
            try {
                ImageManagerStatic::make(
                    $request->file('logo')
                )
                    ->save(public_path('images/affiliates/'.$data->affiliate_network).'/'.$data->program_id.'.'.$request->file('logo')->getClientOriginalExtension())
                ;
            } catch (NotReadableException $e) {
            }
        }

        $data->save();

        if ($request->has('categories'))  {
            AffiliateCategory::where('affiliate_id', $id)->delete();

            $explodeIds = explode(',', $request->input('categories'));

            foreach ($explodeIds as $categoryId) {
                $affiliateCategory = new AffiliateCategory();
                $affiliateCategory->category_id = $categoryId;
                $affiliateCategory->affiliate_id = $id;
                $affiliateCategory->save();
            }
        }

        Alert::success('Deze partner is succesvol gewijzigd.')->persistent('Sluiten');

        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        $ids = $request->input('id');

        echo $request->input('action');
        switch ($request->input('action')) {
            case 'noshow':
                Affiliate::whereIn('id', $ids)->update(array(
                    'no_show' => 1
                ));

                Alert::success('De gekozen selectie is succesvol op no show gezet.')->persistent("Sluiten");

                break;

            case 'show':
                Affiliate::whereIn('id', $ids)->update(array(
                    'no_show' => 0
                ));

                Alert::success('De gekozen selectie is succesvol op show gezet.')->persistent("Sluiten");

                break;
            
            default:
                if ($request->input('id') != '')  {
                    Affiliate::whereIn('id', $ids)->delete();
                }

                Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
                break;
        }
        
        return Redirect::to('admin/'.$this->slugController);
    }

}