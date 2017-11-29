<?php

namespace App\Console\Commands\Transaction;

use Illuminate\Console\Command;
use anlutro\cURL\cURL;
use App\Models\Transaction;
use App\User;
use Sentinel;
use Exception;
use Config;
use Setting;
use Mail;

class Daisycon extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */   
    protected $signature = 'daisycon:transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * The affiliate network
     *
     * @var string
     */
    protected $affiliate_network = 'daisycon';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function addTransactions()
    {
             $startDate =  date('Y').'-01-01';
                $endDate = date('Y-m-d', strtotime('today'));

                $curl = new cURL;
                
                $this->transaction = Transaction::select(
                    'external_id'
                )
                    ->where('affiliate_network', $this->affiliate_network)
                    ->get()
                    ->toArray()
                ; 
 
                $this->feedTransactions = $curl
                    ->newRequest(
                        'GET', 'https://services.daisycon.com/publishers/370506/transactions?page=1&per_page=1000&start='.$startDate.'&end='.$endDate
                    )
                    ->setUser(Setting::get('settings.daisycon_name'))
                    ->setPass(Setting::get('settings.daisycon_pw'))
                    ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
                    ->send()
                ;

                $this->feedTransactions = json_decode($this->feedTransactions->body);

                $status = Config::get('preferences.transactionStatus');
                
                if (isset($this->feedTransactions)) {
                    foreach ($this->feedTransactions as $transaction) {
                        if (!in_array($transaction->affiliatemarketing_id, array_flatten($this->transaction))) {
                            $insertTransaction[] = array(
                                'program_id' => $transaction->program_id,
                                'ip' => $transaction->anonymous_ip,
                                'external_id' => $transaction->affiliatemarketing_id,
                                'user_id' => ($transaction->parts[0]->subid == null ? 0 : $transaction->parts[0]->subid),
                                'status' => $status[$transaction->parts[0]->status],
                                'processed' => date('Y-m-d H:i:s', strtotime($transaction->parts[0]->date)),
                                'amount' => number_format($transaction->parts[0]->commission / 100 * 70, 2),
                                'affiliate_network' => $this->affiliate_network,
                                'created_at' => date('Y-m-d H:i:s'),
                            );
                        } else {
                            $transactionUpdate = Transaction::where('external_id', $transaction->affiliatemarketing_id)
                                ->where('affiliate_network', $this->affiliate_network)
                                ->first()
                            ;

                            if ($transactionUpdate->getMeta('transaction_stop_changes') == NULL) {
                                if ($transactionUpdate) {
                                    if ($transactionUpdate->status == 'open' && $status[$transaction->parts[0]->status] == 'accepted') {
                                        $transactionUpdate->processed = date('Y-m-d H:i:s');
                                    }

                                    $transactionUpdate->status = $status[$transaction->parts[0]->status];
                                    $transactionUpdate->save();
                                }
                            }
                        }
                    }
                }

                if (isset($insertTransaction)) {
                    Transaction::insert($insertTransaction);
                }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    public function handle()
    {
        $commandName = 'daisycon_transaction';

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