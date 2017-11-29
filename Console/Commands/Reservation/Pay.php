<?php

namespace App\Console\Commands\Reservation;

use App\Models\Reservation;
use App\Models\MailTemplate;
use Illuminate\Console\Command;
use Exception;
use Setting;
use Mail;

class Pay extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'pay:reservation';

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


                $this->reservations = Reservation::select(
                    'reservations.*',
                    'companies.id as companyId',
                    'companies.email as companyEmail',
                    'companies.contact_name as companyCName'
                )
                    ->leftJoin('companies', 'reservations.company_id', '=', 'companies.id')
                    ->where('reservations.saldo', '>', 0)
                    ->where('reservations.is_cancelled', 0)
                    ->where('reservations.restaurant_is_paid', 0)
                    ->where('reservations.user_is_paid_back', 0)
                    ->whereIn('reservations.status', array('reserved', 'present'))
                    ->where('reservations.date', '<=', ''.date('Y-m-d').'')
                    ->where('reservations.time', '<=', ''.date('H:i').':00')
                    ->get()
                ;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function setAsPaid()
    {

                foreach ($this->reservations as $reservation) {
                    $reservation->restaurant_is_paid = 1;
                    $reservation->save();

                    if (
                        $reservation->allergies != 'null' 
                        && $reservation->allergies != NULL 
                        && $reservation->allergies != '[""]'
                    ) {
                        $allergies = implode(",", json_decode($reservation->allergies));
                    }
                    
                    if (
                        $reservation->preferences != 'null' 
                        && $reservation->preferences != NULL 
                        && $reservation->preferences != '[""]'
                    ) {
                        $preferences = implode(",", json_decode($reservation->preferences));
                    }

                    $mailtemplate = new MailTemplate();
                    
                    $mailtemplate->sendMail(array(
                        'email' => $reservation->companyEmail,
                        'template_id' => 'reminder-reservation-company',
                        'company_id' => $reservation->companyId,
                        'replacements' => array(
                            '%name%' => $reservation->name,
                            '%cname%' =>$reservation->companyCName,
                            '%saldo%' => $reservation->saldo,
                            '%phone%' => $reservation->phone,
                            '%email%' => $reservation->email,
                            '%date%' => date('d-m-Y', strtotime($reservation->date)),
                            '%time%' => date('H:i', strtotime($reservation->time)),
                            '%persons%' => $reservation->persons,
                            '%comment%' => $reservation->comment,
                            '%allergies%' => (count(json_decode($reservation->allergies)) >= 1 ? implode(",", json_decode($reservation->allergies)) : ''),
                            '%preferences%' => (count(json_decode($reservation->preferences)) >= 1 ? implode(",", json_decode($reservation->preferences)) : '')
                        )
                    ));          
                }
                
    }

    public function handle()
    {
        $commandName = 'handle';

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
                    $this->setAsPaid(); 
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
