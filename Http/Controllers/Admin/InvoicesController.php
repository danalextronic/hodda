<?php
namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App;
use App\Http\Requests;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Company;
use App\Models\MailTemplate;
use App\User;
use App\Http\Controllers\Controller;
use App\Models\Content;
use URL;
use Alert;
use Config;
use DB;
use Sentinel;
Use PDF;
use Redirect;
use DateTime;
use DateTimeImmutable;
use Mollie_API_Client;

class InvoicesController extends Controller 
{
    
    public function __construct(Request $request)
    {
        $this->months = Config::get('preferences.months');
        $this->paginationQueryString = $request->query();
        $this->queryString = $request->query();
        unset($this->queryString['limit']);
    }

    public function index(Request $request, $slug = null)
    {
        if (Sentinel::inRole('admin') == FALSE) {
            $company = Company::where('slug', '=', $slug)
                ->where('companies.user_id', '=', Sentinel::getUser()->id)
                ->whereNull('companies.signature_url')
                ->first()
            ;

            if ($company) {
                alert()->error('', 'U kunt deze pagina niet bezoeken omdat u nog niet bent ingeschreven.')->persistent('Sluiten');
                return Redirect::to('admin/companies/update/'.$company->id.'/'.$company->slug.'?step=1');
            }
        }

        $invoices = Invoice::select(
            DB::raw('month(invoices.start_date) as month'),
            DB::raw('year(invoices.start_date) as year'),
            'invoices.id',
            'invoices.invoice_number',
            'invoices.week',
            'invoices.total_saldo',
            'invoices.total_persons',
            'invoices.type',
            'invoices.debit_credit',
            'invoices.start_date',
            'invoices.paid',
            'invoices.payment_method',
            'invoices.products',
            'invoices.company_id',
            'companies.name',
            'companies.financial_email'
        )
            ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
        ;

        if ($request->has('q')) {
            $invoices = $invoices->where('invoice_number', 'LIKE', '%'.$request->input('q').'%');

            if (Sentinel::inRole('admin')) {
                $invoices = $invoices->orWhere('companies.name', 'LIKE', '%'.$request->input('q').'%');
            }
        }

        if ($request->has('paid') && $request->input('paid') != 3) {
            $invoices = $invoices->where('invoices.paid', '=',$request->input('paid'));
        }

        if ($request->has('sale_purchase')) {
            $invoices = $invoices->where('invoices.sale_purchase', '=',$request->input('sale_purchase'));
        }

        if ($request->has('paid') && $request->input('paid') == 3) {
            $invoices = $invoices->where('invoices.is_debit', '=', 1);
        }

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            $invoices = $invoices->orderBy($request->input('sort'), $request->input('order'));
            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $invoices = $invoices->orderBy('invoices.start_date', 'desc');
        }

        # Filter by company
        if ($slug != null) {
            $invoices = $invoices->where('companies.slug', '=', $slug);

            if (Sentinel::inRole('admin') == FALSE) {
                $invoices = $invoices->where('companies.user_id', '=', Sentinel::getUser()->id);
            }
        }

        # Get all months and years
        foreach ($invoices->get() as $dateParts) {
            if ($dateParts->start_date != '0000-00-00') {
                $selectMonths[$dateParts['month']] = $this->months[$dateParts['month']];
                $selectYears[$dateParts['year']] = $dateParts['year'];
            }
        }

        # Filter by month and year
        if ($request->has('month') && $request->has('year')){
            $invoices = $invoices
                ->whereMonth('invoices.start_date', '=', $request->input('month'))
                ->whereYear('invoices.start_date', '=', $request->input('year'))
            ;
        }

        $invoices = $invoices->paginate(15);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $invoices->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $invoices->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }
        
        return view('admin/invoices/index', [
            'invoices' => $invoices,
            'currentPage' => 'Facturen',
            'selectYears' => isset($selectYears) ? $selectYears : array(),
            'selectMonths' => isset($selectMonths) ? $selectMonths : array(),
            'slug' => $slug,
            'queryString' => $this->queryString,
            'months' => $this->months,
            'slugController' => 'invoices/'.($slug != null ? 'overview/'.$slug : ''),
            'section' => 'Overzicht',
            'paginationQueryString' => $this->paginationQueryString
        ]);
    }

    public function taxReport(Request $request)
    {
        $invoices = Invoice::select(
            DB::raw('month(invoices.start_date) as month'),
            DB::raw('year(invoices.start_date) as year'),
            'invoices.id',
            'invoices.invoice_number',
            'invoices.week',
            'invoices.total_saldo',
            'invoices.total_persons',
            'invoices.type',
            'invoices.debit_credit',
            'invoices.start_date',
            'invoices.paid',
            'invoices.payment_method',
            'invoices.products',
            'invoices.company_id',
            'companies.name',
            'companies.financial_email'
        )
            ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
        ;

        if ($request->has('q')) {
            $invoices = $invoices->where('invoice_number', 'LIKE', '%'.$request->input('q').'%');

            if (Sentinel::inRole('admin')) {
                $invoices = $invoices->orWhere('companies.name', 'LIKE', '%'.$request->input('q').'%');
            }
        }

        if ($request->has('invoice_type')) {
            $invoices = $invoices->where('sale_purchase', '=', $request->input('invoice_type'));
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $invoices =  $invoices
                ->whereDate('invoices.start_date', '>=', $request->input('start_date'))
                ->whereDate('invoices.end_date', '<=', $request->input('end_date'))
            ;
        }

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            $invoices = $invoices->orderBy($request->input('sort'), $request->input('order'));
            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $invoices = $invoices->orderBy('invoices.start_date', 'desc');
        }

        $invoices = $invoices->get();

        $fileName = 'btwaangifte-'.date('d-m-Y', strtotime($request->input('start_date'))).'_'.date('d-m-Y', strtotime($request->input('end_date')));
       
        $excel = App::make('excel');
        $pdf = App::make('Vsmoraes\Pdf\Pdf');

        $templateVars = array(
                    'startDate' => ($request->has('start_date') ? date('d-m-Y', strtotime($request->input('start_date'))) : ''),
                    'endDate' => ($request->has('end_date') ?  date('d-m-Y', strtotime($request->input('end_date'))) : ''),
                    'totalTax' => 0,
                    'totalPriceExTax' => 0,
                    'totalPrice' => 0,
                    'invoices' => $invoices,
                    'invoiceModel' => new Invoice()
                );

        switch ($request->input('file_type')) {
            case 'csv':
                $excel
                    ->create($fileName, function($excel) use($templateVars) {
                        $excel->sheet('BTW aangifte', function($sheet) use($templateVars) {
                            $sheet->loadView('template.csv.tax-report', $templateVars);
                        });
                    })
                    ->export('csv')
                ;
                break;
            
            default:
                $pdf = $pdf
                    ->load(view('template.pdf.tax-report', $templateVars)->render())
                    ->filename($fileName.'.pdf')
                ;

                return $pdf->download();

                break;
        }
    }

    public function create()
    {
        $companies = Company::lists('name', 'id');
        $getLastId = Invoice::select(
            'invoice_number'
        )
            ->orderBy('invoice_number', 'desc')
            ->limit(1)
            ->first()
        ;

        return view('admin/invoices/create', [
            'slugController' => 'invoices',
            'getLastId' => $getLastId,
            'companies' => $companies,
            'section' => 'Facturen', 
            'currentPage' => 'Nieuwe factuur'
        ]);
    }

    public function createAction(Request $request)
    {
        $rules = [
            'invoice_number' => 'required|unique:invoices',
            'start_date' => 'required'
        ];

        $this->validate($request, $rules);
        
        $invoice = new Invoice();
        $invoice->payment_method = $request->input('payment_method');
        $invoice->invoice_number = $request->input('invoice_number');
        $invoice->sale_purchase = $request->input('sale_purchase');
        $invoice->start_date = $request->input('start_date');
        $invoice->period = $request->input('period');
        $invoice->company_id = $request->input('company');
        $invoice->debit_credit = $request->input('debit_credit');
        $invoice->products = json_encode($request->input('products'));
        $invoice->type = $request->input('type');
        $invoice->save();

        if ($request->hasFile('documents')) {
            foreach ($request->file('documents') as $pdf) {
                $invoice
                    ->addMedia($pdf)
                    ->toCollection('documents')
                ;
            }
        }


        Alert::success('Er is succesvol een factuur aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/invoices');
    }

    public function update($id)
    {
        $companies = Company::lists('name', 'id');
        $invoice = Invoice::where('id', $id)->first();

        if ($invoice) {
            $documentItems = $invoice->getMedia('documents');
            
            return view('admin/invoices/update', [
                'slugController' => 'invoices',
                'companies' => $companies,
                'documentItems' => $documentItems,
                'invoice' => $invoice,
                'section' => 'Facturen', 
                'currentPage' => 'Factuur bewerken: #'.$invoice->invoice_number
            ]);
        } else {
            alert()->error('', 'Dit factuur bestaat niet')->persistent('Sluiten');
            
            return Redirect::to('admin/invoices');
        }
    }

    public function updateAction(Request $request, $id)
    {
        $rules = [
            'invoice_number' => 'required|unique:invoices,invoice_number,'.$id,
            'start_date' => 'required'
        ];

        $this->validate($request, $rules);
        
        $invoice = Invoice::find($id);

        if ($invoice) {
            $productJson = array();

            $i = -1;
            
            $products = $request->input('products');
            foreach ($products as $key => $product) {
                $i++;

                if (!is_numeric($key) && isset($products['price'][$i])) {
                    $productJson[] = array(
                        'service' => isset($products['service'][$i]) ? $products['service'][$i]: '',
                        'price' => isset($products['price'][$i]) ? $products['price'][$i] : '',
                        'amount' => isset($products['amount'][$i]) ? $products['amount'][$i]: '',
                        'description' => isset($products['description'][$i]) ? $products['description'][$i] : '',
                        'tax' => isset($products['tax'][$i]) ? $products['tax'][$i] : '',
                        'total' => isset($products['description'][$i]) ? $products['description'][$i] : '',
                    );
                }

                if (is_numeric($key)) {
                    $productJson[] = array(
                        'service' => isset($products[$key]['service']) ? $products[$key]['service']: '',
                        'price' => isset($products[$key]['price']) ? $products[$key]['price'] : '',
                        'amount' => isset($products[$key]['amount']) ? $products[$key]['amount'] : '',
                        'description' => isset($products[$key]['description']) ? $products[$key]['description'] : '',
                        'tax' => isset($products[$key]['tax'][$i]) ? $products[$key]['tax']: '',
                        'total' => isset($products[$key]['description']) ? $products[$key]['description'] : '',
                    );
                }
            }

            // dd($productJson);

            $invoice->sale_purchase = $request->input('sale_purchase');
            $invoice->payment_method = $request->input('payment_method');
            $invoice->invoice_number = $request->input('invoice_number');
            $invoice->start_date = $request->input('start_date');
            $invoice->debit_credit = $request->input('debit_credit');
            $invoice->period = $request->input('period');
            $invoice->company_id = $request->input('company');
            $invoice->products = json_encode($productJson);
            $invoice->type = $request->input('type');
            $invoice->save();
            
            Alert::success('Deze factuur is succesvol gewijzigd.')->persistent('Sluiten');   

            return Redirect::to('admin/invoices/update/'.$id);
            
        }
    }
    
    public function sendInvoice($id)
    {
        $contentBlock = Content::getBlocks();
        
        $invoice = Invoice::select(
            'invoices.id',
            'invoices.invoice_number',
            'invoices.start_date',
            'invoices.products',
            'invoices.type',
            'invoices.payment_method',
            'invoices.debit_credit',
            'invoices.total_persons as totalPersons',
            'invoices.total_saldo as totalSaldo',
            'companies.id as companyId',
            'companies.name as companyName',
            'companies.kvk as companyKVK',
            'companies.address as companyAddress',
            'companies.city as companyCity',
            'companies.email as companyEmail',
            'companies.btw as companyBTW',
            'companies.financial_email as companyFinancialEmail',
            'companies.financial_iban as companyFinancialIban',
            'companies.financial_iban_tnv as companyFinancialIbantnv',
            'companies.zipcode as companyZipcode'
        )
            ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            $invoice = $invoice->where('companies.user_id', '=', Sentinel::getUser()->id);
        }

        $invoice = $invoice
            ->where('invoices.invoice_number', $id)
            ->first()
        ;

        if ($invoice) {   
            $startDate = date('Y-m-d', strtotime($invoice->start_date));
            $expireDate = date('Y-m-d', strtotime($startDate.' +14 days'));

            $invoiceModel = new Invoice();

            $productsArray = array();

             if (isset(json_decode($invoice->products, true)[0])) {
                $productsArray = json_decode($invoice->products);
            } else {
                array_push($productsArray, (object) json_decode($invoice->products));
            }
            
            $getInvoice = $invoiceModel->getInvoice(
                array(
                    'products' => (object) $productsArray, 
                    'type' => $invoice->type, 
                    'totalSaldo' => $invoice->totalSaldo, 
                    'totalPersons' => $invoice->totalPersons, 
                    'invoiceNumber' => $invoice->invoice_number, 
                    'debitCredit' => $invoice->debit_credit,
                    'footer' => (isset($contentBlock[17]) ? $contentBlock[17] : ''), 
                    'footer_2' => (isset($contentBlock[18]) ? $contentBlock[18] : ''), 
                    'totalPrice' => 0,
                    'totalPriceExTax' => 0,
                    'totalTax' => 21,
                    'invoiceDate' => array(
                        'startDate' => $startDate,
                        'expireDate' => $expireDate
                    ),
                    'company' => array(
                        'name' => $invoice->companyName,
                        'email' => $invoice->companyEmail,
                        'kvk' => $invoice->companyKVK,
                        'city' => $invoice->companyCity,
                        'btw' => $invoice->companyBTW,
                        'zipcode' => $invoice->companyZipcode,
                        'address' => $invoice->companyAddress,
                        'financial_iban' => $invoice->companyFinancialIban,
                        'debit_credit' => $invoice->debit_credit,
                        'financial_iban_tnv' => $invoice->companyFinancialIbantnv
                    )
                ),
                'output'
            );

            // Send invoice mail to company
            switch ($invoice->payment_method) {
                case 'ideal':
                    $mailtemplateModel = new MailTemplate();
                    $mailtemplateModel->sendMail(array(
                        'email' => $invoice->companyFinancialEmail,
                        'attach' => array(
                            'data' => $getInvoice,
                            'name' => 'Factuur '.$invoice->invoice_number.'.pdf'
                        ),
                        'template_id' => 'pay-invoice-company',
                        'company_id' => $invoice->companyId,
                        'invoice_url' => ($invoice->payment_method == 'ideal' ? URL::to('payment/pay-invoice/pay/'.$invoice->invoice_number) : URL::to('admin/invoices/overview/'.str_slug($invoice->companyName))),
                        'replacements' => array(
                            '%invoicenumber%' => $invoice->invoice_number,
                            '%url%' => $invoice->payment_method == 'ideal' ? URL::to('payment/pay-invoice/pay/'.$invoice->invoice_number) : 'Deze factuur gaat via automatisch incasso',
                            '%cname%' => $invoice->contact_name,
                            '%name%' => $invoice->contact_name,
                        )
                    ));
                    break;
                
                default:
                    $mailtemplateModel = new MailTemplate();
                    $mailtemplateModel->sendMail(array(
                        'email' => $invoice->companyFinancialEmail,
                        'attach' => array(
                            'data' => $getInvoice,
                            'name' => 'Factuur '.$invoice->invoice_number.'.pdf'
                        ),
                        'template_id' => 'pay-invoice-directdebit-company',
                        'company_id' => $invoice->companyId,
                        'invoice_url' => ($invoice->payment_method == 'ideal' ? URL::to('payment/pay-invoice/pay/'.$invoice->invoice_number) : URL::to('admin/invoices/overview/'.str_slug($invoice->companyName))),
                        'replacements' => array(
                            '%invoicenumber%' => $invoice->invoice_number,
                            '%url%' => $invoice->payment_method == 'ideal' ? URL::to('payment/pay-invoice/pay/'.$invoice->invoice_number) : 'Deze factuur gaat via automatisch incasso',
                            '%cname%' => $invoice->contact_name,
                            '%name%' => $invoice->contact_name,
                        )
                    ));
                    break;
            }

            $invoice->addMeta('invoice_send', 1);

            alert()->success('', 'Deze factuur is succesvol verstuurd naar het e-mailadres: '.$invoice->companyFinancialEmail)->persistent('Sluiten');
            return Redirect::to('admin/invoices');

        } else {
            App::abort(404);
        }
    }

    public function downloadInvoice($id)
    {
        $contentBlock = Content::getBlocks();
        
        $invoice = Invoice::select(
            'invoices.id',
            'invoices.invoice_number',
            'invoices.start_date',
            'invoices.products',
            'invoices.type',
            'invoices.debit_credit',
            'invoices.total_persons as totalPersons',
            'invoices.total_saldo as totalSaldo',
            'companies.name as companyName',
            'companies.kvk as companyKVK',
            'companies.address as companyAddress',
            'companies.city as companyCity',
            'companies.email as companyEmail',
            'companies.btw as companyBTW',
            'companies.financial_iban as companyFinancialIban',
            'companies.financial_iban_tnv as companyFinancialIbantnv',
            'companies.zipcode as companyZipcode'
        )
            ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            $invoice = $invoice->where('companies.user_id', '=', Sentinel::getUser()->id);
        }

        $invoice = $invoice
            ->where('invoices.invoice_number', $id)
            ->first()
        ;

        if ($invoice) {   
            $startDate = date('Y-m-d', strtotime($invoice->start_date));
            $expireDate = date('Y-m-d', strtotime($startDate.' +14 days'));

            $invoiceModel = new Invoice();

            $productsArray = array();

             if (isset(json_decode($invoice->products, true)[0])) {
                $productsArray = json_decode($invoice->products);
            } else {
                array_push($productsArray, (object) json_decode($invoice->products));
            }
            
            $getInvoice = $invoiceModel->getInvoice(
                array(
                    'products' => (object) $productsArray, 
                    'type' => $invoice->type, 
                    'totalSaldo' => $invoice->totalSaldo, 
                    'totalPersons' => $invoice->totalPersons, 
                    'invoiceNumber' => $invoice->invoice_number, 
                    'debitCredit' => $invoice->debit_credit,
                    'footer' => (isset($contentBlock[17]) ? $contentBlock[17] : ''), 
                    'footer_2' => (isset($contentBlock[18]) ? $contentBlock[18] : ''), 
                    'totalPrice' => 0,
                    'totalPriceExTax' => 0,
                    'totalTax' => 21,
                    'invoiceDate' => array(
                        'startDate' => $startDate,
                        'expireDate' => $expireDate
                    ),
                    'company' => array(
                        'name' => $invoice->companyName,
                        'email' => $invoice->companyEmail,
                        'kvk' => $invoice->companyKVK,
                        'city' => $invoice->companyCity,
                        'btw' => $invoice->companyBTW,
                        'zipcode' => $invoice->companyZipcode,
                        'address' => $invoice->companyAddress,
                        'financial_iban' => $invoice->companyFinancialIban,
                        'debit_credit' => $invoice->debit_credit,
                        'financial_iban_tnv' => $invoice->companyFinancialIbantnv
                    )
                ),
                'download'
            );
        } else {
            App::abort(404);
        }
    }

    public function setPaid(Request $request)
    {
        $invoice = Invoice::select(
            'invoices.id',
            'companies.slug'
        )
            ->leftJoin( 'companies', 'companies.id', '=', 'invoices.company_id')
            ->where('invoices.id', '=', $request->input('invoice_id'))
            ->first()
        ;

        if(count($invoice) == 1) {
            $invoice->paid = $request->input('paid');
            $invoice->save();
        }

        return Redirect::to('admin/invoices'.(trim($request->input('overview')) != '' ? '/overview/'.$invoice->slug : ''));
    }

    public function invoicesAction(Request $request)
    {
        $invoice = Invoice::select(
            'invoices.id',
            'companies.slug'
        )
            ->whereIn('invoices.id', $request->input('id'))
        ;

        if(count($invoice) == 1) {
           $invoice->delete();
        }

        return Redirect::to('admin/invoices'.(trim($request->input('overview')) != '' ? '/overview/'.$invoice->slug : ''));
    }

}
