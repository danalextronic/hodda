<?php

namespace App\Console\Commands\Invoice;

use Illuminate\Console\Command;
use App;
use App\Models\Reservation as ReservationModel;
use App\Models\MailTemplate;
use App\Models\Invoice;
use App\Models\Payment;
use DB;
use Exception;
use URL;
use Mollie_API_Client;
use Setting;
use Mail;

class Reservation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'reservation:invoice';

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
                'companies.price_per_guest',
                'companies.id as companyId',
                'companies.contact_name',
                'companies.name as companyName',
                'companies.kvk as companyKVK',
                'companies.address as companyAddress',
                'companies.city as companyCity',
                'companies.financial_email as companyFinancialEmail',
                'companies.email as companyEmail',
                'companies.btw as companyBTW',
                'companies.financial_iban as companyFinancialIban',
                'companies.financial_iban as companyFinancialIban',
                'companies.financial_iban_tnv as companyFinancialIbantnv',
                'companies.zipcode as companyZipcode'
            )
                ->leftJoin('companies', 'companies.id', '=', 'invoices.company_id')
                ->where('invoices.type', 'reservation')
                ->where('invoices.debit_credit', 'debit')
                ->where('invoices.paid', 0)
                ->where('invoices.end_date', '=', '0000-00-00')
                ->orWhere('invoices.end_date', '>=', date('Y-m-d'))
                ->get()
            ;  

            foreach ($invoices as $invoice) {
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

                    $invoice->addMeta('next_invoice_send', 1);
                }

                // send invoice to the company owner when the start date is today
                if (
                    $invoice->start_date == date('Y-m-d')
                    && $invoice->getMeta('invoice_send') == NULL
                ) {
                    $invoiceModel = new Invoice();
                    $mailtemplateModel = new MailTemplate();

                    if ($invoice->payment_method == 'directdebit') {
                        $payment = $this->mollie->payments->create(
                            array(
                                'amount' => ($totalPersons * $invoice->price_per_guest * 1.21),
                                'metadata' => array(
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

                    $weeksAgo = date('Y-m-d', strtotime($invoice->start_date.' -'.$invoice->period.' days'));
                    $persons = 0;
                    $saldo = 0;

                    $reservations = ReservationModel::select(
                        'reservations.date',
                        'reservations.persons',
                        'reservations.saldo'
                    )
                        ->whereBetween('reservations.date', array($weeksAgo, date('Y-m-d', strtotime('-1 days'))))
                        ->where('reservations.is_cancelled', 0)
                        ->where('reservations.company_id', $invoice->companyId)
                        ->whereIn('reservations.status', array('reserved', 'present'))
                        ->get()
                    ;

                    foreach ($reservations as $reservation) {
                         $persons += $reservation->persons;
                         $saldo += $reservation->saldo;
                    }

                    $invoice->total_saldo = $saldo;
                    $invoice->total_persons = $persons;
                    $invoice->save();

                    $expireDate = $invoice->end_date != '0000-00-00' ? date('d-m-Y', strtotime($invoice->start_date.' +14 days')) : date('d-m-Y', strtotime($invoice->end_date));

                    $getInvoice = $invoiceModel->getInvoice(
                        array(
                            'debitCredit' => $invoice->debit_credit,
                            'type' => $invoice->type, 
                            'pricePerGuest' => $invoice->price_per_guest, 
                            'totalSaldo' => $invoice->total_saldo, 
                            'totalPersons' => $invoice->total_persons, 
                            'invoiceNumber' => $invoice->invoice_number, 
                            'footer' => (isset($contentBlock[17]) ? $contentBlock[17] : ''), 
                            'footer_2' => (isset($contentBlock[18]) ? $contentBlock[18] : ''), 
                            'totalPrice' => 0,
                            'invoiceDate' => array(
                                'startDate' => date('d-m-Y', strtotime($invoice->start_date)),
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
                                'financial_iban_tnv' => $invoice->companyFinancialIbantnv
                            )
                        ),
                        'output'
                    );

                    if ($saldo > 0 || $persons > 0) {
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
                    }

                    $invoice->addMeta('invoice_send', 1);
                }
            }
    }

    public function handle()
    {
        $commandName = 'reservation_invoice';

        if (Setting::get('cronjobs.'.$commandName) == NULL) {
            echo 'This command is not working right now. Please activate this command.';
        } else {
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

}
