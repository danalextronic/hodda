<?php

namespace App\Console\Commands\Appointment;

use Illuminate\Console\Command;
use App\Models\Appointment;
use App\Models\MailTemplate;
use App\Models\TemporaryAuth;
use Carbon\Carbon;
use Exception;
use Setting;
use Mail;

class Reminder extends Command
{
    /**
     * Thd name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'reminder:appointment';

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

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function sendReminder()
    {
        $appointments = Appointment::select(
            'appointments.id',
            'appointments.last_reminder_at',
            'appointments.appointment_at',
            'users.email as callerEmail',
            'companies.id as companyId',
            'appointments.email as appointmentEmail',
            'appointments.place as appointmentPlace',
            'companies.slug as companySlug',
            'companies.contact_name as companyContactName',
            'companies.user_id as ownerId',
            'companies.signature_url as companySignature'
        )
            ->where('appointments.send_reminder', '=', 1)
            ->where('companies.signature_url', '=', NULL)
            ->where('companies.user_id', '>', 0)
            ->leftJoin('users', 'users.id', '=', 'appointments.caller_id')
            ->leftJoin('companies_callcenter', 'companies_callcenter.id', '=', 'appointments.company_id')
            ->leftJoin('companies', 'companies_callcenter.company_id', '=', 'companies.id')
            ->get()
        ;

       $nowDate = Carbon::create(
                    date('Y'),
                    date('m'),
                    date('d'),
                    date('H'),
                    date('i'),
                    0
                );
                    
        foreach ($appointments as $appointment) {
            if ($appointment->last_reminder_at != NULL) {
                $lastReminderDate = Carbon::create(
                    date('Y', strtotime($appointment->last_reminder_at)),
                    date('m', strtotime($appointment->last_reminder_at)),
                    date('d', strtotime($appointment->last_reminder_at)),
                    date('H', strtotime($appointment->last_reminder_at)),
                    date('i', strtotime($appointment->last_reminder_at)),
                    0
                );
                    
                $nextReminderDate = $lastReminderDate->addHours(Setting::get('settings.callcenter_reminder') != NULL ? Setting::get('settings.callcenter_reminder') : 48);
            }

            $mailtemplate = new MailTemplate();
            
            // Reminder after 48 hours
            if (isset($nextReminderDate) && $nextReminderDate == $nowDate) {
                $temporaryAuth = new TemporaryAuth();
                $createAuthSignup = $temporaryAuth->createCode($appointment->ownerId, 'admin/companies/update/'.$appointment->companyId.'/'.$appointment->companySlug.'?step=1');
                
                // Send a company sign up mail
                $mailtemplate->sendMailSite(array(
                    'email' =>  $appointment->appointmentEmail,
                    'template_id' => 'appointment_mail',
                    'fromEmail' => $appointment->callerEmail,
                    'replacements' => array(
                        '%name%' => $appointment->companyContactName,
                        '%cname%' => $appointment->companyContactName,
                        '%date%' => date('d-m-Y', strtotime($appointment->appointment_at)),
                        '%time%' => date('H:i', strtotime($appointment->appointment_at)),
                        '%email%' => $appointment->appointmentEmail,
                        '%place%' => $appointment->appointmentPlace,
                        '%url%' => url('auth/set/'.$createAuthSignup)
                    )
                ));

                $appointment->last_reminder_at = date('Y-m-d H:i:s');
            }

            $appointment->save();
        }
    }

    public function handle()
    {
        $commandName = 'reminder_appointment';

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
