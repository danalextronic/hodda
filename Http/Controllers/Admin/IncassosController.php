<?php

namespace App\Http\Controllers\Admin;

use Artisan;
use Alert;
use App\Models\Incasso;
use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Console\Commands\Invoice\DirectDebit;
use Config;
use DB;
use Illuminate\Http\Request;
use Redirect;
use Exception;

class IncassosController extends Controller
{

    public function index(Request $request) 
    {
        $incassos = new Incasso();

        # Filter by columns
        if ($request->has('sort') && $request->has('order')) {
            $incassos = $incassos->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $incassos = $incassos->orderBy('created_at', 'desc');
        }

        # Filter by month and year
        if ($request->has('month') && $request->has('year')) {  
            $incassos = $incassos
                ->whereMonth('created_at', '=', $request->input('month'))
                ->whereYear('created_at', '=', $request->input('year'))
            ;
        }

        # Filter by type
        if ($request->has('type')) {  
            $incassos = $incassos->where('type', '=', $request->input('type'));
        }

        $incassos = $incassos->paginate(15);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $incassos->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $incassos->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }
        
        $monthsYears = Incasso::select(
            DB::raw('month(created_at) as months, year(created_at) as years')
        )
            ->groupBy('years', 'months')
            ->orderBy('months', 'asc')
            ->get()
            ->toArray()
        ;

        $month = array();
        $years = array();
        $monthConvert = Config::get('preferences.months');

        foreach($monthsYears as $key => $monthYear) {
            $month[$monthYear['months']] = $monthConvert[$monthYear['months']];
            $years[$monthYear['years']] = $monthYear['years'];
        }
        
        return view('admin/incasso/index', array(
            'incassos' => $incassos, 
            'months' => isset($month) ? $month : '',
            'years' => isset($years) ? $years : '',
            'monthsYears' => $monthsYears,  
            'paginationQueryString' => $request->query(),
            'currentPage' => 'Overzicht',
            'section' => 'Incasso',
            'slugController' => '#',
        ));
    }

    public function downloadXml($incasso_id) 
    {
        $incasso = Incasso::find($incasso_id);

        header('Content-type: text/xml');
        header('Content-Disposition: attachment; filename="Incasso-'.$incasso->type.'-import-'.$incasso_id.'.xml"');

        echo $incasso->xml;

        exit;
    }

    public function generateIncasso(Request $request)
    {   
        $debit = new DirectDebit;

        try {
            $debit->handle();
            
            Alert::success('Er is succesvol een of meerdere incassos bestanden gegenereerd.')->persistent('Sluiten');
        } catch (Exception $e) {
            if ($e->getMessage() == 'Invalid Payment, error with: name is empty.') {
                Alert::error('U bent vergeten om bij een of meerdere bedrijven financiele gegevens toe te voegen.')->persistent('Sluiten');
            } else {
                Alert::error('Er is een fout opgetreden.')->persistent('Sluiten');
            }
        }
        
        return Redirect::to('admin/incassos');
    }

}
