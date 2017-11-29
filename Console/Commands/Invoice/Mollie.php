<?php

namespace App\Console\Commands\Invoice;

use Illuminate\Console\Command;
use Request;
use App\User;
use App\Models\Invoice;
use App\Models\MailTemplate;
use DateTime;
use DatePeriod;
use DateInterval;
use Mollie_API_Client;
use App;
use anlutro\cURL\cURL;
use Setting;
use Sentinel;
use URL;

class Mollie extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mollie:invoice';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get all failed mollie direct debit payments';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    public function handle()
    {
        if (Setting::get('cronjobs.mollie_invoice') == NULL) {
            echo 'This command is not working right now. Please activate this command.';
        } else {

            // $mollie = new Mollie_API_Client;
            // $mollie->setApiKey(App::environment('production') ? getenv('MOLLIE_PRODKEY') : getenv('MOLLIE_TESTKEY'));

            // $payments = $mollie->payments->all();

            // $invoices = Invoice::select(
            //     'invoices.id',
            //     'invoice_number',
            //     'companies.user_id',
            //     'companies.financial_email',
            //     'companies.id as company_id',
            //     'companies.contact_name'
            // )
            //     ->leftJoin('companies', 'invoices.company_id', '=', 'companies.id')
            //     ->where('paid', '=', 3)
            //     ->where('debit_credit', '=', 'debit')
            //     ->where('payment_method', '=', 'directdebit')
            //     ->get()
            // ;

            // foreach ($invoices as $invoice) {
            //     $invoiceArray[$invoice->invoice_number] = array(
            //         'invoiceNumber' => $invoice->invoice_number,
            //         'companyId' => $invoice->company_id,
            //         'financialEmail' => $invoice->financial_email,
            //         'ownerId' => $invoice->user_id,
            //         'ownerName' => $invoice->contact_name,
            //     );
            // }

            // foreach ($payments as $payment) {
            //     if (isset($payment->metadata->invoice_number) && isset($invoiceArray[$payment->metadata->invoice_number])) {
            //         $invoiceNumber = $payment->metadata->invoice_number;
                    
            //         $mailtemplate = new MailTemplate(); // Send mail to company owner
            //         $mailtemplate->sendMail(array(
            //             'email' => $invoiceArray[$invoiceNumber]['financialEmail'],
            //             'template_id' => 'pay-invoice-failed',
            //             'company_id' => $invoiceArray[$invoiceNumber]['companyId'],
            //             'replacements' => array(
            //                 '%name%' => $invoiceArray[$invoiceNumber]['ownerName'],
            //                 '%cname%' => $invoiceArray[$invoiceNumber]['ownerName'],
            //                 '%url%' => URL::to('payment/charge')
            //             )
            //         ));

            //         $user = Sentinel::findById($invoiceArray[$invoiceNumber]['ownerId']);
            //         $user->saldo = $user->saldo - $payment->amount;
            //         $user->save();
            //     }
            // }

            // dd($invoiceArray);
        }
    }

}
