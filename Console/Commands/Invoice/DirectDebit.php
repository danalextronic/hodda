<?php

namespace App\Console\Commands\Invoice;

use Illuminate\Console\Command;
use App;
use App\Models\Invoice;
use App\Models\Incasso;
use App\Models\MailTemplate;
use Alert;
use Redirect;
use Exception;
use URL;
use Setting;
use Mail;

class DirectDebit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'debit:invoice';

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
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function directDebit()
    {
           $invoicesArray = array();
            $amountArray = array();

            $invoiceModel = new Invoice();

            $invoices = Invoice::where('invoices.paid', 0)
                ->where('invoices.start_date', '<=', date('Y-m-d').'"')
                ->get()
            ;  

            foreach ($invoices as $key => $invoice) {
                if ($invoice->getMeta('invoice_direct_debit') == NULL) {
                     // Products invoices and persons are direct debit
                    if ($invoice->type == 'products') {
                        $invoicesArray['debit'][] = $invoice;
                        $amountArray['debit'][] = $invoiceModel->getTotalProductsSaldo($invoice->products, 1);
                    }

                    if ($invoice->total_persons > 0) {
                        $invoicesArray['debit'][] = $invoice;
                        $amountArray['debit'][] = ($invoice->total_persons * 1 * 1.21);
                    }

                    if ($invoice->total_saldo > 0) {
                        $invoicesArray['payments'][] = $invoice;
                        $amountArray['payments'][] = $invoice->total_saldo;
                    }
                }
            }
            
            foreach ($invoicesArray as $key => $invoicesFetch) {
                if (isset($invoicesArray[$key])) {
                    $debit = new Incasso();

                    $xml = $debit->generateDirectDebitXml($invoicesArray[$key], $key);

                    $debit->no_of_invoices = count($invoicesArray[$key]);
                    $debit->amount = array_sum($amountArray[$key]);
                    $debit->xml = $xml;
                    $debit->type = $key;
                    $debit->save();

                    foreach ($invoicesArray[$key] as $invoices) {
                        $invoices->is_debit = 1;
                        $invoices->save();
                        
                        $invoices->addMeta('invoice_direct_debit', 1);
                    }
                }
            }
    }

    public function handle()
    {
        $commandName = 'debit_invoice';

        if (Setting::get('cronjobs.'.$commandName) == NULL) {
            echo 'This command is not working right now. Please activate this command.';
        } else {
            $getClient = $this->checkConnection();

            if ($getClient) {
                if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
                    // Start cronjob
                    $this->line(' Start '.$this->signature);
                    Setting::set('cronjobs.active.'.$commandName, 1);
                    Setting::save();

                    // Processing
                    try {
                        $this->directDebit();  
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
            } else {
                $this->line('This task is not available, because there is no connection.');
            }
        }
    }
}
