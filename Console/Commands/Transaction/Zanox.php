<?php

namespace App\Console\Commands\Transaction;

use Illuminate\Console\Command;
use SoapClient;
use Request;
use App\Models\Transaction;
use App\User;
use DateTime;
use DatePeriod;
use DateInterval;
use Config;
use Setting;
use Exception;
use Mail;

class Zanox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zanox:transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
   
    protected $affiliate_network = 'zanox';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->transactionsList = Transaction::select(
            'external_id'
        )
            ->where('affiliate_network', $this->affiliate_network)
            ->get()
            ->toArray()
        ; 
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    public function addTransactions()
    {
              ini_set('soap.wsdl_cache_enabled', 0);

        $network = new \Oara\Network\Publisher\Zanox();
        $credentialsNeeded = $network->getNeededCredentials();

        $credentials = array(
            'connectid' => Setting::get('settings.zanox_name'),
            'secretkey' => Setting::get('settings.zanox_pw')
        );

        $network->login($credentials);

        if ($network->checkConnection()) {
            $startDate = new DateTime(date('Y').'-01-01');
            $endDate = new DateTime();

            $status = Config::get('preferences.transactionStatus');
            $merchantList = $network->getMerchantList();
            $transactions = $network->getTransactionList($merchantList, $startDate, $endDate);

            foreach ($transactions as $transaction) {
                if (!in_array($transaction['unique_id'], array_flatten($this->transactionsList))) {
                    $this->line($transaction['unique_id']);
                    $insertTransaction[] = array(
                        'program_id' => $transaction['merchantId'],
                        'ip' => '',
                        'external_id' => $transaction['unique_id'],
                        'user_id' => (isset($transaction['custom_id']) ? $transaction['custom_id'] : 0),
                        'status' => $transaction['approved'] == true ?  $status['approved'] : $status[$transaction['status']],
                        'processed' => date('Y-m-d H:i:s', strtotime($transaction['date'])),
                        'amount' => number_format($transaction['commission'] / 100 * 70, 2),
                        'affiliate_network' => $this->affiliate_network,
                        'created_at' => date('Y-m-d H:i:s')
                    );
                } else {
                    $transactionUpdate = Transaction::where('external_id', $transaction['unique_id'])
                        ->where('affiliate_network', $this->affiliate_network)
                        ->first()
                    ;
                    
                    if ($transactionUpdate->getMeta('transaction_stop_changes') == NULL) {
                        if ($transactionUpdate) {
                            if ($transactionUpdate->status == 'open' && $status[$transaction['status']] == 'accepted') {
                                $transactionUpdate->processed = date('Y-m-d H:i:s');
                            }

                            $transactionUpdate->status = $status[$transaction['status']];
                            $transactionUpdate->save();
                        }
                    }
                }
            }

            if (isset($insertTransaction)) {
                Transaction::insert($insertTransaction);
            }
        } else {
            echo 'Network credentials not valid';
        }
    }

    public function handle()
    {
        $commandName = 'zanox_transaction';

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
                    $this->addTransactions(); 
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
