<?php

namespace App\Console\Commands\Invoice;

use Illuminate\Console\Command;
use App;
use App\Models\Invoice;
use App\Models\MailTemplate;
use App\Models\Payment;
use Exception;
use URL;
use Mollie_API_Client;
use Setting;
use Mail;

class Product extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'product:invoice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    private $mollie;

    public function __construct() 
    {
        parent::__construct();

        $this->mollie = new Mollie_API_Client;
        $this->mollie->setApiKey(App::environment('production') ? getenv('MOLLIE_PRODKEY') : getenv('MOLLIE_TESTKEY'));
    
        $this->getLastId = Invoice::select(
            'invoice_number'
        )
            ->orderBy('invoice_number', 'desc')
            ->limit(1)
            ->first()
        ;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function sendReminder()
    {
        $invoices = Invoice::select(
                'invoices.*',
                'companies.id as companyId',
                'companies.user_id as companyOwnerId',
                'companies.contact_name',
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
                ->where('invoices.type', 'products')
                ->where('invoices.debit_credit', 'debit')
                ->where('invoices.paid', 0)
                ->where('invoices.end_date', '=', '0000-00-00')
                ->orWhere('invoices.end_date', '>=', date('Y-m-d'))
                ->get()
            ;  

            foreach ($invoices as $key => $invoice) {
                // Add the next invoice 
                if (
                    $invoice->next_invoice_at == date('Y-m-d')
                    && $invoice->getMeta('next_invoice_send') == NULL
                ) {
                    $newInvoice = new Invoice();
                    $newInvoice->invoice_number = $this->getLastId->invoice_number + 1;
                    $newInvoice->products = $invoice->products;
                    $newInvoice->period = $invoice->period;
                    $newInvoice->company_id = $invoice->companyId;
                    $newInvoice->type = $invoice->type;
                    $newInvoice->start_date = date('Y-m-d', strtotime($invoice->next_invoice_at));
                    $newInvoice->next_invoice_at = date('Y-m-d', strtotime($invoice->next_invoice_at.' +'.$invoice->period.' days'));
                    $newInvoice->save();

                    $invoice->addMeta(
                        'next_invoice_send', 
                        1
                    );
                }

                // send invoice to the company owner when the start date is today
                if (
                    $invoice->start_date == date('Y-m-d')
                    && $invoice->getMeta('invoice_send') == NULL
                ) {

                    $productsArray = array();

                    if (isset(json_decode($invoice->products, true)[0])) {
                        $productsArray = json_decode($invoice->products);
                    } else {
                        array_push($productsArray, (object) json_decode($invoice->products));
                    }

                    if ($invoice->payment_method == 'directdebit') {
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

                        $payment = $this->mollie->payments->create(
                            array(
                                'amount' => $totalPrice,
                                'metadata'    => array(
                                    'invoice_number' => $invoice->invoice_number
                                ),
                                'description' => 'Factuur '.$invoice->invoice_number,
                                'redirectUrl' => URL::to('payment/success/invoice'),
                                'method' => 'directdebit',
                                'consumerAccount' => $invoice->companyFinancialIban,
                                'consumerName' => $invoice->companyFinancialIbantnv,
                            )
                        );

                        $oPayment = new Payment();
                        $oPayment->mollie_id = $payment->id;
                        $oPayment->user_id = $invoice->companyOwnerId;
                        $oPayment->status = $payment->status;
                        $oPayment->amount = $totalPrice;
                        $oPayment->type = 'invoice_'.$invoice->invoice_number;
                        $oPayment->payment_type = $invoice->payment_method;
                        $oPayment->save();

                        $invoice->paid = 3;
                    }

                    if ($invoice->period > 0 && $invoice->next_invoice_at == NULL) {
                        $invoice->next_invoice_at = date('Y-m-d', strtotime($invoice->start_date.' +'.$invoice->period.' days'));
                    }
                    
                    $invoice->save();

                    $this->info('Invoice #'.$invoice->invoice_number.' has been sent.');

                    $invoiceModel = new Invoice();
                    $mailtemplateModel = new MailTemplate();

                    $startDate = date('Y-m-d', strtotime($invoice->start_date));
                    $expireDate = date('Y-m-d', strtotime($startDate.' +14 days'));
            
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
                    
                    // Send mail to company owner
                    switch ($invoice->payment_method) {
                        case 'ideal':
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
                }
            }
    }

    public function handle()
    {
        $commandName = 'product_invoice';

        if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
            // Start cronjob
            $this->line(' Start '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 1);
            Setting::save();

            // Processing
            try {
                $this->sendReminder(); 
            } catch (Exception $e) {
                $this->line('Er is een fout opgetreden. '.$this->signature);
               
                Mail::raw('Er is een fout opgetreden:<br /><br /> '.$e, function ($message) {
                    $message->to(getenv('DEVELOPER_EMAIL'))->subject('Fout opgetreden: '.$this->signature);
                });
            } 
            
            // End cronjob
            $this->line('Finished '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 0);
            Setting::save();
        } else {
            // Don't run a task mutiple times, when the first task hasnt been finished
            $this->line('This task is busy at the moment.');
        }
    }
}
