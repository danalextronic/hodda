<?php

namespace App\Console\Commands\Transaction;

use Illuminate\Console\Command;
use Request;
use App\Models\Transaction;
use App\User;
use Config;
use Setting;
use DateTime;
use Mail;
use Exception;

class Tradedoubler extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tradedoubler:transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $affiliate_network = 'tradedoubler';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        define('COOKIES_BASE_DIR', realpath ( dirname ( __FILE__ ) ));

        $this->transaction = Transaction::select(
            'external_id'
        )
            ->where('affiliate_network', $this->affiliate_network)
            ->get()
            ->toArray()
        ; 
    }

    public function addTransactions()
    {
  $network = new \Oara\Network\Publisher\TradeDoubler();
            $credentialsNeeded = $network->getNeededCredentials();

            $credentials = array(
                'user' => Setting::get('settings.tradedoubler_name'),
                'password' => Setting::get('settings.tradedoubler_pw')
            );

            $network->login($credentials);

            if ($network->checkConnection()) {
                $startDate = new DateTime(date('Y').'-01-01');
                $endDate = new DateTime();

                $status = Config::get('preferences.transactionStatus');
                $merchantList = $network->getMerchantList();
                $transactions = $network->getTransactionList($merchantList, $startDate, $endDate);

                foreach ($transactions as $transaction) {
                    if (!in_array($transaction['unique_id'], array_flatten($this->transaction))) {
                        $insertTransaction[] = array(
                            'program_id' => $transaction['merchantId'],
                            'ip' => '',
                            'external_id' => $transaction['unique_id'],
                            'user_id' => (isset($transaction['custom_id']) ? $transaction['custom_id'] : 0),
                            'status' => $status[$transaction['status']],
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

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'tradedoubler_transaction';

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
