<?php

namespace App\Console\Commands\Reservation;

use App\Models\Reservation;
use App\Models\MailTemplate;
use Mail;
use Illuminate\Console\Command;
use Exception;
use Setting;

class Reminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'reminder:reservation';

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

    public function sendRemider()
    {
 $this->reservations = Reservation::where(
                    'reservations.is_cancelled', 0
                )
                    ->leftJoin('company_reservations', 'company_reservations.id', '=', 'reservations.reservation_id')
                    ->whereIn('reservations.status', array('reserved', 'present'))
                    ->whereRaw('DATE_FORMAT(date_sub(concat(reservations.date, " ", reservations.time), interval company_reservations.reminder_before_date hour), "%Y-%m-%d %H") = "'.date('Y-m-d H').'"')
                    ->get()
                ;

                foreach ($this->reservations as $reservation) {
                    if ($reservation->getMeta('send_reservation_reminder_email') == NULL) {
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
                            'email' => $reservation->email,
                            'template_id' => 'reminder-reservation-client',
                            'company_id' => $reservation->company_id,
                            'replacements' => array(
                                '%name%' => $reservation->name,
                                '%cname%' => '',
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

                        # Add a meta
                        $reservation->addMeta(
                            'send_reservation_reminder_email', 
                            array(
                                date('Y-m-d H')
                            )
                        );
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
        $commandName = 'reminder_reservation';

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
                    $this->sendRemider(); 
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
