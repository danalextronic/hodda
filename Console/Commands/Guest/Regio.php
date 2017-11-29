<?php

namespace App\Console\Commands\Guest;

use Sentinel;
use Illuminate\Console\Command;
use DB;
use Exception;
use Mail;

class Regio extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'regio:guest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function regioUpdate() 
    {
        $users = Sentinel::getUserRepository()
            ->select(
                'users.id as userId',
                'users.name',
                'companies.regio',
                'users.city',
                'guests_third_party.company_id'
            )
            ->leftJoin('guests_third_party', 'guests_third_party.email', '=', 'users.email')
            ->leftJoin('companies', 'guests_third_party.company_id', '=', 'companies.id')
            ->whereNull('users.city')
            ->whereNotNull('guests_third_party.company_id')
            ->get()
        ;

        foreach ($users as $key => $user) {
            $userArray[$user->company_id][] = array(
                'userId' => $user->userId,
                'name' => $user->name,
                'companyId' => $user->company_id,
                'regio' => $user->regio
            );
        }

        if (isset($userArray)) {
            foreach ($userArray as $userArrayKey => $userArrayFetch) {
                foreach ($userArrayFetch as $userArrayKeyChild => $userArrayFetchChild) {
                    Sentinel::getUserRepository()
                        ->where('id', $userArrayFetchChild['userId'])
                        ->update(['city' => $userArrayFetchChild['regio']])
                    ;
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
        $commandName = 'regio_guests';

        if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
            // Start cronjob
            $this->line(' Start '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 1);
            Setting::save();

            // Processing
            try {
                $this->regioUpdate();
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
