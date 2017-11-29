<?php

namespace App\Console\Commands\Payment;

use Illuminate\Console\Command;
use App;
use App\Models\Payment;
use Exception;
use Sentinel;
use Mail;
use Mollie_API_Client;
use App\Models\MailTemplate;
use Setting;

class Validate extends Command
{

    /**
     * @var string
     */
    protected $signature = 'validate:payment';

    /**
     * @var string
     */
    protected $description = 'Command description';
    
    /**
     * @var string
     */
    private $mollie;
    
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function validatePayments()
    {
                $this->mollie = new Mollie_API_Client;
                $this->userPayments = Payment::where(
                    'status', 'open'
                )
                    ->where('mollie_id', '!=', '')
                    ->where('type', '=', 'mollie')
                    ->orderBy('created_at','desc')
                    ->get()
                ;

                $this->mollie->setApiKey(App::environment('production') ? getenv('MOLLIE_PRODKEY') : getenv('MOLLIE_TESTKEY'));
            
                if (count($this->userPayments) >= 1) {
                    foreach ($this->userPayments as $userPayments) {
                        $payment = $this->mollie->payments->get($userPayments['mollie_id']);

                        $userPayments->payment_type = $payment->method;
                        $userPayments->status = $payment->status;
                        $userPayments->save();

                        if ($payment->status == 'paid') {

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
                    }
                }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'validate_payment';

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
                    $this->validatePayments(); 
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
