<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\BarcodeUser;
use App\Models\Payment;
use App\Models\Company;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;
use Sentinel;
use DB;
use Mollie_API_Client;
use URL;

class BarcodesController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'barcodes';
        $this->section = 'Bioscoopkaartjes';
        $this->companies = Company::lists('name', 'id');
        $this->companiesList = Company::all();
    }

    public function index(Request $request)
    {
        $data = Barcode::select(
            DB::raw('count(barcodes.id) as amount'),
            'barcodes.id',
            'barcodes_users.user_id',
            'barcodes.expire_date',
            'barcodes.code',
            'barcodes_users.is_active',
            'barcodes_users.created_at',
            'company_services.name as serviceName',
            'companies.name as companyName'
        )
            ->leftJoin('barcodes_users', 'barcodes.id', '=', 'barcodes_users.barcode_id')
            ->leftJoin('company_services', 'barcodes.service_id', '=', 'company_services.id')
            ->leftJoin('companies', 'companies.id', '=', 'barcodes.company_id')
            ->groupBy(array('companies.name', 'company_services.name'))
        ;

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('barcodes.id', 'desc');
        }

        $data = $data->paginate($request->input('limit', 15));
        $data->setPath('barcodes');

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
            'slugController' => $this->slugController,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'companies' => $this->companiesList, 
            'currentPage' => 'Overzicht'
        ]);
    }

    public function company(Request $request, $slug)
    {
        $userCompanyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        $data = Barcode::select(
            'barcodes_users.company_id',
            'barcodes.id',
            'barcodes_users.user_id',
            'barcodes_users.code',
            'barcodes_users.is_active',
            'barcodes_users.created_at',
            'barcodes.expire_date',
            'users.name',
            'users.phone',
            'users.email',
            'companies.name as companyName'
        )
            ->leftJoin('barcodes_users', 'barcodes.id', '=', 'barcodes_users.barcode_id')
            ->leftJoin('users', 'barcodes_users.user_id', '=', 'users.id')
            ->leftJoin('companies', 'companies.id', '=', 'barcodes.company_id')
            ->groupBy('barcodes_users.code')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('companies.user_id', Sentinel::getUser()->id);
        }

        if ($request->has('user')) {
            $data = $data->where('barcodes_users.user_id', '=', $request->input('user'));
        } else {
            $data = $data
                ->where('companies.slug', $slug)
                ->where('barcodes_users.company_id', '!=', 0)
            ;
        }

        if ($request->has('status')) {
            switch ($request->input('status')) {
                case 1:
                    $data = $data->where('barcodes_users.is_active', '=', $request->input('status'));
                    break;

                case 0:
                    $data = $data->where('barcodes_users.is_active', '=', NULL);
                    break;
            }
        }

        if ($request->has('q')) {
            $data = $data
                ->where('users.name', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('barcodes_users.code', 'LIKE', '%'.$request->input('q').'%')
            ;
        }

        if ($request->has('sort') && $request->has('order')) {
            switch ($request->input('sort')) {
                case 'is_active':
                    $data = $data->orderBy('barcodes_users.is_active', $request->input('order'));
                    break;

                case 'code':
                    $data = $data->orderBy('barcodes_users.code', $request->input('order'));
                    break;

                case 'name':
                    $data = $data->orderBy('users.name', $request->input('order'));
                    break;

                case 'email':
                    $data = $data->orderBy('users.email', $request->input('order'));
                    break;

                case 'phone':
                    $data = $data->orderBy('users.phone', $request->input('order'));
                    break;

                case 'created_at':
                    $data = $data->orderBy('barcodes_users.created_at', $request->input('order'));
                    break;

                case 'company_id':
                    $data = $data->orderBy('barcodes_users.company_id', $request->input('order'));
                    break;

                default:
                    $data = $data->orderBy($request->input('sort'), $request->input('order'));
                    break;
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('barcodes_users.id', 'asc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));

        $queryString = $request->query();
        unset($queryString['limit']);

        if ($userCompanyOwner != FALSE) {
            return view('admin/'.$this->slugController.'/company', [
                'data' => $data, 
                'countItems' => $dataCount, 
                'slug' => $slug, 
                'slugController' => $this->slugController.'/'.$slug,
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $request->input('limit', 15),
                'section' => $this->section, 
                'currentPage' => 'Overzicht',
                'companies' => $this->companiesList, 
            ]);
        } else{
            App::abort(404);
        }
    }

    public function create()
    {
        return view('admin/'.$this->slugController.'/create', [
            'companies' => $this->companies,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe bioskoopkaarten'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'service_id' => 'required',
            'code' => 'unique:barcodes',
            'pdf' => 'required',
            'company' => 'required'.$request->input('company') != 0 ? '|exists:companies,id' : ''
        ]);

        if ($request->hasFile('pdf')) {
            foreach ($request->file('pdf') as $pdf) {
                $data = new Barcode();
                $data->code = $request->input('code');
                $data->company_id = $request->input('company');
                $data->is_active = $request->input('is_active', 0);
                $data->expire_date = $request->input('expire_date');       
                $data->service_id = $request->input('service_id');       
                $data->save();

                $data
                    ->addMedia($pdf)
                    ->toCollection('documents')
                ;
            }
        }

        Alert::success('Deze vouchers zijn succesvol aangemaakt.')->persistent('Sluiten');

        return Redirect::to('admin/'.$this->slugController);
    }

    public function update($id)
    {
        $data = Barcode::leftJoin('companies', 'companies.id', '=', 'barcodes.company_id');

        if (Sentinel::inRole('admin') == FALSE) {
            $data = $data->where('companies.user_id', Sentinel::getUser()->id);
        }

        $data = $data->find($id);

        return view('admin/'.$this->slugController.'/update', [
            'data' => $data,
            'companies' => $this->companies,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig barcode'
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $this->validate($request, [
            'code' => 'required|unique:barcodes,code,'.$id,
        ]);

        $data = Barcode::find($id);
        $data->code = $request->input('code');
        $data->company_id = $request->input('company');
        $data->is_active = $request->input('is_active');
        $data->expire_date = $request->input('expire_date');
        $data->save();

        Alert::success('Deze barcode is succesvol gewijzigd.')->persistent('Sluiten');

        return Redirect::to('admin/'.$this->slugController.'/update/'.$data->id);
    }

    public function deleteAction(Request $request)
    {
        if ($request->has('id')) {
            $dataUser = BarcodeUser::select(
                'barcodes_users.id'
            )
                ->leftJoin('companies', 'companies.id', '=', 'barcodes_users.company_id')
                ->whereIn('barcodes_users.id', $request->input('id'));
            ;

            $dataBarcode = Barcode::select(
                'barcodes.id'
            )
                ->leftJoin('companies', 'companies.id', '=', 'barcodes.company_id')
                ->whereIn('barcodes.id', $request->input('id'));
            ;

            if (Sentinel::inRole('admin') == FALSE) {
                $dataBarcode = $dataBarcode->where('companies.user_id', Sentinel::getUser()->id);
                $dataUser = $dataUser->where('companies.user_id', Sentinel::getUser()->id);
            }

            if ($dataUser->count() >= 1)  {               
                $dataUser->delete();
            }

            if ($dataBarcode->count() >= 1)  {               
                $dataBarcode->delete();
            }
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");

        return Redirect::to('admin/'.$this->slugController);
    }

    public function buyAction(Request $request)
    {
        $this->validate($request, [
            'amount_tickets' => 'required|min:1|numeric'
        ]);

        $data = Barcode::select(
            DB::raw('count(barcodes.id) as amount'),
                'barcodes.id',
                'barcodes.expire_date',
                'barcodes.code',
                'company_services.price',
                'company_services.tax',
                'companies.name as companyName'
            )
            ->leftJoin('company_services', 'barcodes.service_id', '=', 'company_services.id')
            ->leftJoin('companies', 'companies.id', '=', 'barcodes.company_id')
            ->groupBy(array('companies.name', 'company_services.name'))
            ->where('barcodes.company_id', '=', $request->input('company_id'))
            ->where('barcodes.service_id', '=', $request->input('service_id'))
            ->first()
        ;

        if ($data) {
            $totalPrice = $request->input('amount_tickets') * $data->price * ('1.'.($data->tax < 10 ? '0'.$data->tax : $data->tax));

            $mollie = new Mollie_API_Client;
            $mollie->setApiKey(App::environment('production') ? getenv('MOLLIE_PRODKEY') : getenv('MOLLIE_TESTKEY'));

            $molliePayment = $mollie->payments->create(array(
                'amount' => $totalPrice,
                'description' => $request->input('amount_tickets').'x '.$request->input('service_name').' E-tickets - '.$request->input('company_name'),
                'redirectUrl' => URL::to('admin/barcodes/success')
            ));

            $payment = new Payment();
            $payment->mollie_id = $molliePayment->id;
            $payment->user_id = Sentinel::getUser()->id;
            $payment->status = $molliePayment->status;
            $payment->amount = $totalPrice;
            $payment->type = 'tickets';
            $payment->save();

            return Redirect::to($molliePayment->links->paymentUrl);
        }
    }

    public function validatePayment(Request $request)
    {
        $userPayments = Payment::where('user_id', Sentinel::getUser()->id)
            ->where('mollie_id', '!=', '')
            ->where('type', '=',' tickets')
            ->where('status', 'open')
            ->orderBy('created_at', 'desc')
            ->first()
        ;

        if ($userPayments == null) {
            alert()->error('', 'Er is een fout opgetreden, probeert u het alstublieft opnieuw')->persistent('Sluiten');

            return Redirect::to('/');
        }

        $payment = $this->mollie->payments->get($userPayments->mollie_id);
        $userPayments->payment_type = $payment->method;
        $userPayments->status = $payment->status;
        $userPayments->save();

        if ($payment->status == 'paid') {
            alert()->success('', 'Uw E-Tickets zijn succesvol toegevoegd aan uw account.')->persistent('Sluiten');
            return Redirect::to('/');
        } elseif ($payment->status == 'cancelled') {
            alert()->error('', 'U heeft de transactie geannuleerd')->persistent('Sluiten');

            return Redirect::to('/');
        } else {
            alert()->error('', 'Er is een fout opgetreden, probeert u het alstublieft opnieuw')->persistent('Sluiten');

            return Redirect::to('/');
        }
    }
}