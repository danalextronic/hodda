<?php
namespace App\Http\Controllers\Admin;

use App;
use Alert;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Preference;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;

class TransactionsController extends Controller 
{

    public function __construct(Request $request)
    {
        $this->slugController = 'transactions';
        $this->section = 'Transacties';
    }

    public function index(Request $request)
    {   
        $limit = $request->input('limit', 15);

        $data = Transaction::select(
            'affiliates_transactions.*',
            'users.name',
            'affiliates.name as programName',
            'affiliates.slug as programSlug'
        )
            ->leftJoin('users', 'affiliates_transactions.user_id', '=', 'users.id')
            ->leftJoin('affiliates', function ($join) {
                $join
                    ->on('affiliates_transactions.program_id', '=', 'affiliates.program_id')
                    ->on('affiliates_transactions.affiliate_network', '=', 'affiliates.affiliate_network')
                ;
            })
        ;

        if ($request->has('source')) {
            switch ($request->input('source')) {
                case 'wifi':
                    $data = $data
                        ->leftJoin('guests_wifi', 'guests_wifi.email', '=', 'users.email') 
                        ->whereNotNull('guests_wifi.email')
                        ->whereNotNull('guests_wifi.name')
                    ;
                    break;
                
                default:
                     $data = $data
                        ->leftJoin('reservations', 'reservations.user_id', '=', 'users.id')  
                        ->where('reservations.source', '=', $request->input('source'))
                    ;
                    break;
            }
        }

        if ($request->has('city')) {
            $preferences = new Preference();
            $regio = $preferences->getRegio();

            $regioName = $request->input('city');

            if (isset($regio['regioNumber'][$regioName])) {
                $data = $data->whereNotNull(
                    'users.city'
                )
                    ->where('users.city', 'REGEXP', '"([^"]*)'.$regio['regioNumber'][$regioName].'([^"]*)"')
                ;
            }
        }

        if ($request->has('shop')) {
            $data = $data->where('affiliates.slug', '=', $request->input('shop'));
        }

        if ($request->has('network')) {
            $data = $data->where('affiliates_transactions.affiliate_network', '=', $request->input('network'));
        }

        if ($request->has('status')) {
            $data = $data->where('affiliates_transactions.status', '=', $request->input('status'));
        }

        if ($request->has('q')) {
            $data = $data
                ->where('users.name', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('affiliates_transactions.affiliate_network', 'LIKE', '%'.$request->input('q').'%')
            ;
        }

        if ($request->has('sort') && $request->has('order')) {
            $companiesColumn = array(
                'program_id',
                'created_at'
            );

            if(in_array($request->input('sort'), $companiesColumn)) {
                $data = $data->orderBy('transactions.'.$request->input('sort'), $request->input('order'));
            } else {
                $data = $data->orderBy($request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('affiliates_transactions.id', 'desc');
        }

        $dataCount = $data->count();

        $data = $data->groupBy('affiliates_transactions.id')->paginate($limit);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['source']);
        unset($queryString['status']);
        unset($queryString['city']);
        unset($queryString['network']);
        unset($queryString['limit']);
        
        if ($data) {
            return view('admin/'.$this->slugController.'/index', [
                'data' => $data, 
                'countItems' => $dataCount,
                'slugController' =>  'transactions',
                'section' => $this->section,
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $limit,
                'currentPage' => 'Overzicht'        
            ]);
        } else  {
            App::abort(404);
        }
    }

    public function indexAction(Request $request)
    {
        if ($request->has('id')) {
            Transaction::whereIn('id', $request->input('id'))->delete();
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController);
    }

    public function create()
    {
        $statusArray = array(
            'accepted' => 'Goedgekeurd',
            'open' => 'In behandeling (open)',
            'rejected' => 'Afgekeurd',
        );

        return view('admin/'.$this->slugController.'/create', [
            'statusArray' => $statusArray,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Transactie toevoegen',
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, [
            'status' => 'required',
            'user_id' => 'required',
            'network' => 'required',
            'amount' => 'required',
        ]);

        $transaction = new Transaction;
        $transaction->status = $request->input('status');
        $transaction->program_id = $request->input('program_id');
        $transaction->user_id = $request->input('user_id');
        $transaction->amount = $request->input('amount');
        $transaction->affiliate_network = $request->input('network');

        if ($request->input('status') == 'accepted') {
            $transaction->processed = date('Y-m-d H:i:s');
        }

        $transaction->save();
          
        Alert::success('Deze transactie is succesvol aangepast.')->persistent('Sluiten');   

        return Redirect::to('admin/'.$this->slugController);
    }

    public function update($id)
    {
        $data = Transaction::select(
            'transactions.*',
            'users.name',
            'affiliates.name as programName',
            'affiliates.slug as programSlug'
        )
            ->leftJoin('users', 'affiliates_transactions.user_id', '=', 'users.id')
            ->leftJoin('affiliates', function ($join) {
                $join
                    ->on('affiliates_transactions.program_id', '=', 'affiliates.program_id')
                    ->on('affiliates_transactions.affiliate_network', '=', 'affiliates.affiliate_network')
                ;
            })
            ->where('affiliates_transactions.id', '=', $id)
            ->first()
        ;

        $statusArray = array(
            'accepted' => 'Goedgekeurd',
            'open' => 'In behandeling (open)',
            'rejected' => 'Afgekeurd',
        );

        return view('admin/'.$this->slugController.'/update', [
            'statusArray' => $statusArray,
            'data' => $data,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Wijzig transactie',
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $this->validate($request, [
            'status' => 'required',
        ]);

        $transaction = Transaction::find($id);

        if ($request->input('status') != $transaction->status) {
            $transaction->addMeta('transaction_stop_changes', 1);
        }
        
        $transaction->status = $request->input('status');
        $transaction->program_id = $request->input('program_id');
        $transaction->user_id = $request->input('user_id');
        $transaction->amount = $request->input('amount');
        
        if ($transaction->status != 'accepted' && $request->input('status') == 'accepted') {
            $transaction->processed = date('Y-m-d H:i:s');
        }

        $transaction->save();
          
        Alert::success('Deze transactie is succesvol aangepast.')->persistent('Sluiten');   

        return Redirect::to('admin/'.$this->slugController);
    }

}