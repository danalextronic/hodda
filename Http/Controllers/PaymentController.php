<?php
namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Invoice;
use App\Models\MailTemplate;
use Illuminate\Http\Request;
use Cartalyst\Sentinel\Checkpoints\ThrottlingException;
use Cartalyst\Sentinel\Checkpoints\NotActivatedException;
use Sentinel;
use Reminder;
use Activation;
use Alert;
use Redirect;
use App\User;
use Mail;
use App;
use DB;
use Config;
use URL;
use Mollie_API_Client;
use Mollie_API_Exception;
use Setting;

class PaymentController extends Controller 
{

    private $mollie;

    public function __construct() 
    {
        $websiteSettings = json_decode(json_encode(Setting::get('website')), true);

        $privateKey = isset($websiteSettings['mollie_live_key']) ? $websiteSettings['mollie_live_key'] : getenv('MOLLIE_TESTKEY');
        $prodKey = isset($websiteSettings['mollie_private_key']) ? $websiteSettings['mollie_private_key'] : getenv('MOLLIE_TESTKEY');

        try {
            echo 'hddoi';
            $this->mollie = new Mollie_API_Client;
            $this->mollie->setApiKey(App::environment('production') ? $prodKey : $privateKey);
        } catch (Mollie_API_Exception $e) {
           alert('Er is een fout opgetreden: '.htmlspecialchars($e->getMessage()))->persistent('Sluiten');
        }
    }

    public function updateDirectory() 
    {
        $coreCommunicator = new \CoreCommunicator(\Configuration::getDefault());
        $diRes = $coreCommunicator->Directory();

        if ($diRes->IsError) {
            dd($diRes->Error);
        } else {
            // handle response: display list of issuing banks
        }
    }

    public function initiateIdealPayment(Request $request) 
    {
        $this->validate($request, [
            'amount' => 'required'
        ]);

        if ($request->amount <= 0.1) {
            alert()->error('', 'Het bedrag is te laag om verder te gaan')->persistent('Sluiten');
            return Redirect::to('payment/charge');
        }

        if (!is_numeric($request->amount)) {
            if (preg_match('/[0-9]{1,3},[0-9]{1,2}/', $request->amount) 
                || preg_match('/[0-9]{1,3}.[0-9]{1,2}/', $request->amount)
            ) {
                if(preg_match('/[0-9]{1,3},[0-9]{1,2}/', $request->amount)) {
                    $request->amount = preg_replace('/,/','.',$request->amount);
                }
            } else {
                return view('payments/charge')->with('error','Graag een geldig bedrag invoeren');
            }
        }

        $payment = $this->mollie->payments->create(array(
            'amount' => $request->amount,
            'description' => 'Saldo ophogen uwvoordeelpas met '.$request->amount,
            'redirectUrl' => ($request->has('buy') && $request->input('buy') == 'voordeelpas' ? URL::to('payment/success?voordeelpas=1') : URL::to('payment/success'))
        ));

        $oPayment = new Payment();
        $oPayment->type = 'mollie';
        $oPayment->mollie_id = $payment->id;
        $oPayment->user_id = Sentinel::getUser()->id;
        $oPayment->status = $payment->status;
        $oPayment->amount = $request->amount;
        $oPayment->save();

        return Redirect::to($payment->links->paymentUrl);
    }

    public function validatePaymentInvoice(Request $request, $slug) 
    {
        $userPayments = Payment::where('mollie_id', '!=', '')
            ->where('type', 'LIKE', '%invoice_'.$slug.'%')
            ->where('status', 'open')
            ->orderBy('created_at','desc')
        ;

        if (Sentinel::inRole('bedrijf')) {
            $userPayments = $userPayments->where('user_id', Sentinel::getUser()->id);
        }

        $userPayments = $userPayments->first();

        if ($userPayments) {
            $payment = $this->mollie->payments->get($userPayments['mollie_id']);

            $userPayments->payment_type = $payment->method;
            $userPayments->status = $payment->status;
            $userPayments->save();

            if ($payment->status == 'paid') {
                if (count($userPayments) >= 1) {
                    preg_match('/(\d+)/', $userPayments->type, $matches, PREG_OFFSET_CAPTURE);
                    
                    $invoice = Invoice::select(
                        'invoices.id',
                        'invoices.invoice_number',
                        'invoices.start_date',
                        'invoices.products',
                        'invoices.type',
                        'invoices.debit_credit',
                        'invoices.total_persons as totalPersons',
                        'invoices.total_saldo as totalSaldo',
                        'companies.slug as companySlug',
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
                        ->where('paid', '=', 0)
                        ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
                    ;

                    if (Sentinel::inRole('admin') == FALSE) {
                        $invoice = $invoice->where('companies.user_id', '=', Sentinel::getUser()->id);
                    }

                    $invoice = $invoice->where('invoices.invoice_number', $matches[0][0])->first();
                    $invoice->paid = 1;
                    $invoice->save();
                }

                alert()->success('', 'Uw factuur is succesvol betaald.')->persistent('Sluiten');

                return Redirect::to('admin/invoices/overview/'.$invoice->companySlug);
            } elseif ($payment->status == 'cancelled') {
                alert()->error('', 'Uw betaling is succesvol geannuleerd')->persistent('Sluiten');

                return Redirect::to('payment/pay-invoice/pay/'.$slug);
            } else {
                alert()->error('', 'Er is een fout opgetreden, probeert u het alstublieft opnieuw')->persistent('Sluiten');

                return Redirect::to('payment/pay-invoice/pay/'.$slug);
            }
        } else {
            alert()->error('', 'U heeft niet genoeg rechten om deze pagina te bezoeken.')->persistent('Sluiten');
            return Redirect::to('/');
        }
    }

    public function validatePayment(Request $request) 
    {
        $userPayments = Payment::where(
            'user_id', Sentinel::getUser()->id
        )
            ->where('mollie_id', '!=', '')
            ->where('type', '=', 'mollie')
            ->where('status', 'open')
            ->orderBy('created_at','desc')
            ->first()
        ;

        if ($userPayments == null) {
            Alert::error(
                'Er is een fout opgetreden, probeert u het alstublieft opnieuw'
            )
                ->persistent('Sluiten')
            ;

            return Redirect::to('payments/charge');
        }

        $payment = $this->mollie->payments->get($userPayments['mollie_id']);
   
        $userPayments->payment_type = $payment->method;
        $userPayments->status = $payment->status;
        $userPayments->save();

        if ($payment->status == 'paid') {
            if (count($userPayments) >= 1) {
                $mailtemplate = new MailTemplate();
                $mailtemplate->sendMailSite(array(
                    'email' => $oUser->email,
                    'template_id' => 'saldo_charge',
                    'replacements' => array(
                        '%name%' => $oUser->name,
                        '%email%' => $oUser->email,
                        '%euro%' => $userPayments['amount']
                    )
                ));
            }

            if($request->has('voordeelpas')) {
                return Redirect::to('voordeelpas/buy/direct');
            } else {
                Alert::success('U heeft succesvol uw saldo opgewaardeerd.')->persistent('Sluiten');
                return Redirect::to('account/reservations/saldo');
            }

        } elseif($payment->status == 'cancelled') {
            Alert::error(
                'U heeft de transactie geannuleerd'
            )
                ->persistent('Sluiten')
            ;

            return Redirect::to('payments/charge');
        } else {
            Alert::error(
                'Er is een fout opgetreden, probeert u het alstublieft opnieuw'
            )
                ->persistent('Sluiten')
            ;

            return Redirect::to('payments/charge');
        }
    }

    public function charge(Request $request)
    {
        $selectedUser = new User();
        $user = Sentinel::getUser();

        if ($request->input('buy') == 'voordeelpas') {   
            $error = 'Uw saldo is te laag om een voordeelpas te kopen. Waardeer uw saldo op om verder te gaan met het aanschaffen van een voordeelpas.';
            $restAmount = ($selectedUser->getSaldo($user->id) < 14.95 ? (14.95 - $selectedUser->getSaldo($user->id)) : 14.95);
        }

        return view('pages/payments/charge', array(
            'error' => isset($error) ? $error : '',
            'restAmount' => (isset($restAmount) ? $restAmount : ($request->has('min') ? $request->input('min') : ''))
        ));
    }

    public function invoiceToPayment($invoicenumber) 
    {
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
            ->where('invoices.paid', '=', 0)
            ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            $invoice = $invoice->where('companies.user_id', '=', Sentinel::getUser()->id);
        }

        $invoice = $invoice->where('invoices.invoice_number', $invoicenumber)->first();

        if (count($invoice) == 1) {
            // Products
            $productsArray = array();

            if (isset(json_decode($invoice->products, true)[0])) {
                $productsArray = json_decode($invoice->products);
            } else {
                array_push($productsArray, (object) json_decode($invoice->products));
            }

            $totalTax = 0;
            $totalPriceExTax = 0;
            $totalPrice = 0;

            foreach ($productsArray as $product) {
                if (isset($product->amount, $product->price, $product->tax)) { 
                    $totalTax = $product->tax; 
                    $totalPriceExTax += $product->amount * $product->price; 
                    $totalPrice += $product->amount * $product->price * ($product->tax / 100 + 1); 
                }
            }

            return view('pages/payments/invoice', array(
                'totalPriceProducts' => $totalPrice,
                'invoice' => $invoice
            ));
        } else {
            alert()->error('', 'Deze factuur met factuurnummer #'.$invoicenumber.' is al betaald of bestaat niet')->persistent('Sluiten');
            return Redirect::to('/');
        }
    }

    public function directInvoiceToPayment(Request $request) 
    {

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
            ->where('paid', '=', 0)
            ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
        ;

        if (Sentinel::inRole('admin') == FALSE) {
            $invoice = $invoice->where('companies.user_id', '=', Sentinel::getUser()->id);
        }

        $invoice = $invoice->where('invoices.invoice_number', $request->input('invoicenumber'))->first();

        if (count($invoice) == 1) {
            switch ($invoice->type) {
                case 'products':
                    $productsArray = array();

                    if (isset(json_decode($invoice->products, true)[0])) {
                        $productsArray = json_decode($invoice->products);
                    } else {
                        array_push($productsArray, (object) json_decode($invoice->products));
                    }

                    $totalTax = 0;
                    $totalPriceExTax = 0;
                    $totalPrice = 0;

                    foreach ($productsArray as $product) {
                        if (isset($product->amount, $product->price, $product->tax)) { 
                            $totalTax = $product->tax; 
                            $totalPriceExTax += $product->amount * $product->price; 
                            $totalPrice += $product->amount * $product->price * ($product->tax / 100 + 1); 
                        }
                    }
                break;
            }

            if ($totalPrice <= 0.1) {
                alert()->error('', 'Het bedrag is te laag om verder te gaan')->persistent('Sluiten');
                return Redirect::to('payment/pay-invoice/pay/'.$request->input('invoicenumber'));
            }

            $payment = $this->mollie->payments->create(array(
                'amount' => $totalPrice,
                'description' => 'Factuurnummer: '.$invoice->invoice_number,
                'redirectUrl' => URL::to('payment/status/'.$invoice->invoice_number)
            ));

            $oPayment = new Payment();
            $oPayment->mollie_id = $payment->id;
            $oPayment->user_id = Sentinel::getUser()->id;
            $oPayment->status = $payment->status;
            $oPayment->amount = $totalPrice;
            $oPayment->type = 'invoice_'.$invoice->invoice_number;
            $oPayment->payment_type = 'ideal';
            $oPayment->save();

            return Redirect::to($payment->links->paymentUrl);
        } else {
            return Redirect::to('/');
        }
    }

}