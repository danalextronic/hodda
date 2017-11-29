<?php

namespace App\Console\Commands\Review;

use Illuminate\Console\Command;
use App\Models\Reservation;
use App\Models\MailTemplate;
use App\Models\WifiGuest;
use Carbon\Carbon;
use Exception;
use URL;
use Mail;

class Reminder extends Command
{
    /**
     * Thd name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'reminder:review';

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

    public function reservationReminder()
    {    
        $this->reservations = Reservation::where('is_cancelled', 0)
            ->whereIn('status', array('reserved', 'present'))
            ->whereRaw('date(date_add(date, interval 1 day)) = "'.date('Y-m-d').'"')
            ->get()
        ;

        foreach ($this->reservations as $reservation) {
            if ($reservation->getMeta('send_review_reminder_email') == NULL) {
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
                    'template_id' => 'reminder-review-client',
                    'company_id' => $reservation->company_id,
                    'replacements' => array(
                        '%name%' => $reservation->name,
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
                    'send_review_reminder_email', 
                    array(
                        date('Y-m-d H:i')
                    )
                );  
            }                
        }
    }

    public function wifiReminder()
    {    
        $guests = WifiGuest::select(
            'guests_wifi.name',
            'guests_wifi.phone',
            'guests_wifi.email',
            'guests_wifi.company_id',
            'companies.slug'
        )
        ->whereRaw('date(date_add(guests_wifi.created_at, interval 1 day)) = "'.date('Y-m-d').'"')
        ->leftJoin('companies', 'guests_wifi.company_id', '=', 'companies.id')
        ->get();

        foreach ($guests as $guest) {
            if ($guest->getMeta('send_review_reminder_guest_email') == NULL) {
                $mailtemplate = new MailTemplate();
                $mailtemplate->sendMail(array(
                    'email' => $guest->email,
                    'template_id' => 'reminder-review-client',
                    'company_id' => $guest->company_id,
                    'replacements' => array(
                        '%name%' => $guest->name,
                        '%phone%' => $guest->phone,
                        '%email%' => $guest->email,
                        '%url%' => URL::to('restaurant/'.$guest->slug)
                    )
                ));   

                # Add a meta
                $guest->addMeta(
                    'send_review_reminder_guest_email', 
                    array(
                        date('Y-m-d H:i')
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
        $commandName = 'reminder_review';

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
                    $this->reservationReminder();
                    $this->wifiReminder();
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
