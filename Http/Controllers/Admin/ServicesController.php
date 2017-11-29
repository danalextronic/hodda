<?php
namespace App\Http\Controllers\Admin;

use App;
use Alert;
use App\Http\Controllers\Controller;
use App\Models\CompanyService;
use App\Models\Company;
use App\Models\Invoice;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class ServicesController extends Controller 
{

    public function __construct(Request $request)
    {
       	$this->slugController = 'services';
       	$this->section = 'Diensten';
        $this->companies = Company::all();
    }

    public function index(Request $request, $slug = NULL)
    {   
        $limit = $request->input('limit', 15);

        $dropdownData = CompanyService::select(
            'company_services.id',
            'company_services.name'
        )
            ->groupBy('company_services.name')
            ->get()
        ;

        $data =  CompanyService::select(
            'company_services.*',
            'companies.name as company'
        )
            ->leftJoin('companies', 'companies.id', '=', 'company_services.company_id')
        ;

        if ($request->has('q')) {
            $data = $data->where('company_services.name', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($slug != NULL) {
            $data = $data->where('companies.slug', '=', $slug);

            if (Sentinel::inRole('admin') == FALSE) {
                $data = $data->where('companies.user_id', Sentinel::getUser()->id);
            }
        }

        if ($request->has('sort') && $request->has('order'))  {
            $companiesColumn = array(
                'company',
                'end_date'
            );

            if ($request->input('sort') == 'price') {
                $data = $data->orderBy('company_services.price', $request->input('order'));
            } else if (in_array($request->input('sort'), $companiesColumn)) {
                $data = $data->orderBy('companies.name', $request->input('order'));
            } else {
                $data = $data->orderBy('companies.'.$request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('company_services.id', 'desc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($limit);
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
            'dropdownData' => $dropdownData, 
            'countItems' => $dataCount,
            'slugController' => $this->slugController,
            'section' => $this->section,
            'companies' => $this->companies,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $limit,
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function create($slug = null)
    {
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe dienst',
            'companies' => Company::lists('name', 'id')
        ]);
    }

    public function createAction(Request $request)
    {
        $getLastId = Invoice::select(
            'invoice_number'
        )
            ->orderBy('invoice_number', 'desc')
            ->limit(1)
            ->first()
        ;

        $rules = [
            'name' => 'required',
            'price' => 'required',
            'period' => 'required',
            'company' => 'required',
            'content' => 'required'
        ];

        // Add new service
        $data = new CompanyService();
        $data->name = $request->input('name');
        $data->price = $request->input('price');
        $data->company_id = $request->input('company');
        $data->period = $request->input('period');
        $data->content = $request->input('content');
        $data->tax = $request->input('tax');
        $data->start_date = $request->input('start_date');
        $data->end_date = $request->input('end_date');
        $data->save();

        $product = array(
            'name' => $request->input('name'),
            'description' => $request->input('content'),
            'price' => $request->input('price'),
            'amount' => 1,
            'tax' => $request->input('tax')
        );

        // Add new invoice
        $invoice = new Invoice();
        $invoice->invoice_number = isset($getLastId->invoice_number) ? $getLastId->invoice_number + 1 : date('Y').'00' + 1;
        $invoice->start_date = $request->input('start_date');
        $invoice->end_date = $request->input('end_date');
        $invoice->period = $request->input('period');
        $invoice->company_id = $request->input('company');
        $invoice->products = json_encode($product);
        $invoice->type = 'products';
        $invoice->save();

        Alert::success('Deze dienst is succesvol aangemaakt.')->persistent('Sluiten');   

        return Redirect::to('admin/services');
    }

    public function update($id)
    {
        $data = CompanyService::find($id);

        if(count($data) >= 1) {
            return view('admin/'.$this->slugController.'/update', [
                'data' => $data,
                'section' => $this->section, 
                'slugController' => $this->slugController,
                'currentPage' => 'Wijzig dienst',
                'companies' => Company::lists('name', 'id')
            ]);
	    } else {
            App::abort(404);
        }
    }

    public function updateAction(Request $request, $id)
    {
        $rules = [
            'name' => 'required',
            'price' => 'required',
            'period' => 'required',
            'company' => 'required',
            'content' => 'required'
        ];

        $data = CompanyService::find($id);
        $data->name = $request->input('name');
        $data->price = $request->input('price');
        $data->company_id = $request->input('company');
        $data->period = $request->input('period');
        $data->content = $request->input('content');
        $data->tax = $request->input('tax');
        $data->save();

        return Redirect::to('admin/services/update/'.$id);
    }

    public function deleteAction(Request $request)
    {
        if($request->has('id')) {
            $data = CompanyService::whereIn('id', $request->input('id'));

            if($data->count() >= 1) {
                $data->delete();
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/services');
    }
}