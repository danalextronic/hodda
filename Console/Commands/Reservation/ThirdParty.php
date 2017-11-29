<?php

namespace App\Console\Commands\Reservation;

use App\Models\Reservation;
use App\Models\GuestThirdParty;
use App\Models\TemporaryAuth;
use App\Models\CompanyReservation;
use App\Models\Guest;
use App\Models\MailTemplate;
use Illuminate\Console\Command;
use Exception;
use URL;
use Setting;
use Sentinel;
use Mail;

class ThirdParty extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'thirdparty:reservation';

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
    public function setAsGuest()
    {
        $reservations = Reservation::all();
        
        foreach ($reservations as $reservation) {
            $guest = new Guest();

            $guest->addGuest(array(
                'user_id' => $reservation->user_id,
                'company_id' => $reservation->company_id
            ));
        }
    }

    public function removeDuplicates()
    {
        $reservations = Reservation::whereNotNull('custom_res_id')
            ->havingRaw('count(*) > 1')
            ->groupBy('custom_res_id')
            ->get()
        ;
        
        foreach ($reservations as $reservation) {
            $reservationArray[] = array(
                'customReservationId' => $reservation->custom_res_id,
                'reservationId' => $reservation->id
            );
        }

        if (isset($reservationArray)) {
            foreach ($reservationArray as $reservationFetch) {
                Reservation::where('custom_res_id', $reservationFetch['customReservationId'])
                    ->where('id', '!=', $reservationFetch['reservationId'])
                    ->delete()
                ;
            }
        }
    }

    public function handleReservations()
    {
        $thirdParty = GuestThirdParty::whereNotNull('restaurant_id')
            ->whereNotNull('restaurant_zipcode')
            ->where('reservation_status', '=', 'pending')
            ->get()
        ;

        foreach ($thirdParty as $network) {
            $restaurantId[] = $network->restaurant_id;
        }

        if (isset($restaurantId)) {
            $aReservationTimes = CompanyReservation::getReservationsCompaniesArray($restaurantId);

            foreach ($thirdParty as $network) {
                if (trim($network->email) != '') {
                    $user = Sentinel::findByCredentials(array(
                        'login' => $network->email
                    ));

                    if (!$user) {
                        $randomPassword = str_random(20);

                        $credentials = array(
                            'email' => $network->email,
                            'password' => $randomPassword
                        );

                        $user = Sentinel::registerAndActivate($credentials);

                        $user->name = $network->name;
                        $user->phone = $network->phone;
                        $user->expire_code = str_random(64);
                        $user->save();

                        // Create auth code for login
                        $temporaryAuth = new TemporaryAuth();
                        $createAuthRegister = $temporaryAuth->createCode($user->id, 'account#preferences');

                        $mailtemplate = new MailTemplate();
                    }
                }

                $date = date('Y-m-d', strtotime($network->reservation_date));
                $time = date('H:i', strtotime($network->reservation_date));
                
                // Update or add a new reservation
                if (isset($aReservationTimes[$network->restaurant_id][$date][$time])) {
                    $isManual = $aReservationTimes[$network->restaurant_id][$date][$time]['isManual'];
                    $reservationId = $aReservationTimes[$network->restaurant_id][$date][$time]['reservationId'];
                    
                    if ($network->network_status == 'confirmed') {
                        $data = new Reservation;
                        $data->date = date('Y-m-d', strtotime($network->reservation_date));
                        $data->time = date('H:i:s', strtotime($network->reservation_date));
                        $data->persons = $network->persons;
                        $data->company_id = $network->restaurant_id;
                        $data->reservation_id = $reservationId;
                        $data->name = $network->name;
                        $data->custom_res_id = $network->reservation_number;

                        if (trim($network->email) != '') {
                            $data->email = $network->email;
                        }

                        if (trim($network->phone) != '') {
                            $data->phone = $network->phone;
                        }

                        $data->comment = $network->comment;
                        $data->user_id = isset($user) ? $user->id : '';
                        $data->source = $network->network;
                        $data->status = ($isManual == 1 ? 'iframe-pending' : 'iframe');
                        $data->save();

                        // Set as succeeded
                        $network->reservation_status = 'success';
                        $network->save();
                        
                        // Send mail to user
                        $mailtemplate = new MailTemplate();

                        if (Setting::get('cronjobs.thirdparty_mail') != NULL) {
                            if ($isManual == 0) {
                                // $mailtemplate->sendMail(array(
                                //     'email' => 'sseymor@roc-dev.com',
                                //     'reservation_id' => $data->id,
                                //     'template_id' => 'new-reservation-client',
                                //     'company_id' => $network->restaurant_id,
                                //     'replacements' => array(
                                //         '%name%' => $network->name,
                                //         '%phone%' => $network->phone,
                                //         '%email%' => $network->email,
                                //         '%date%' => date('d-m-Y', strtotime($network->reservation_date)),
                                //         '%time%' => date('H:i', strtotime($network->reservation_date)),
                                //         '%persons%' => $network->persons,
                                //         '%comment%' => $network->comment,
                                //         '%saldo%' => ''
                                //     )
                                // ));
                            } else {
                                // $mailtemplate->sendMail(array(
                                //     'email' => 'sseymor@roc-dev.com',
                                //     'reservation_id' => $data->id,
                                //     'template_id' => 'reservation-pending-client',
                                //     'company_id' => $network->restaurant_id,
                                //     'replacements' => array(
                                //         '%name%' => $network->name,
                                //         '%phone%' => $network->phone,
                                //         '%email%' => $network->email,
                                //         '%date%' => date('d-m-Y', strtotime($network->reservation_date)),
                                //         '%time%' => date('H:i', strtotime($network->reservation_date)),
                                //         '%persons%' => $network->persons,
                                //         '%comment%' => $network->comment,
                                //         '%saldo%' => ''
                                //     )
                                // ));       
                            }
                        }
                    } 

                    // Update a reservation
                    if ($network->network_status == 'updated') {
                        $updateRes = Reservation::where('custom_res_id', '=', $network->reservation_number)->first();

                        if (count($updateRes) == 1) {
                            $updateRes->date = date('Y-m-d', strtotime($network->reservation_date));
                            $updateRes->time = date('H:i:s', strtotime($network->reservation_date));
                            $updateRes->persons = $network->persons;
                            $updateRes->company_id = $network->restaurant_id;
                            $updateRes->reservation_id = $reservationId;
                            $updateRes->name = $network->name;
                            $updateRes->custom_res_id = $network->reservation_number;

                            if (trim($network->email) != '') {
                                $updateRes->email = $network->email;
                            }

                            if (trim($network->phone) != '') {
                                $updateRes->phone = $network->phone;
                            }

                            $updateRes->comment = $network->comment;
                            $updateRes->user_id = isset($user) ? $user->id : '';
                            $updateRes->source = $network->network;
                            $updateRes->status = ($isManual == 1 ? 'iframe-pending' : 'iframe');
                            $updateRes->save();

                            // Set as succeeded
                            $network->reservation_status = 'success';
                            $network->save();

                            $mailtemplate = new MailTemplate();

                            if (\Setting::get('cronjobs.thirdparty_mail') != NULL) {
                                // Send mail to client
                                $mailtemplate->sendMail(array(
                                    'email' => $network->email,
                                    'template_id' => 'updated-reservation-client',
                                    'company_id' => $network->restaurant_id,
                                    'replacements' => array(
                                        '%name%' => $network->name,
                                        '%phone%' => $network->phone,
                                        '%email%' => $network->email,
                                        '%date%' => date('d-m-Y', strtotime($network->reservation_date)),
                                        '%time%' => date('H:i', strtotime($network->reservation_date)),
                                        '%persons%' => $network->persons,
                                        '%comment%' => $network->comment,
                                        '%saldo%' => ''
                                    )
                                ));
                            
                                // Send mail to owner
                                $mailtemplate->sendMail(array(
                                    'email' => $network->email,
                                    'template_id' => 'updated-reservation-client-company',
                                    'company_id' => $network->restaurant_id,
                                    'replacements' => array(
                                        '%name%' => $network->name,
                                        '%phone%' => $network->phone,
                                        '%email%' => $network->email,
                                        '%date%' => date('d-m-Y', strtotime($network->reservation_date)),
                                        '%time%' => date('H:i', strtotime($network->reservation_date)),
                                        '%persons%' => $network->persons,
                                        '%comment%' => $network->comment,
                                        '%saldo%' => $user->saldo
                                    )
                                ));
                            }
                        }
                    }
                }

                // Set reservation as cancelled
                if ($network->network_status == 'cancelled') {
                    $cancelRes = Reservation::where('custom_res_id', '=', $network->reservation_number)
                        ->where('is_cancelled', '=', 0)
                        ->first()
                    ;

                    if (count($cancelRes) == 1) {
                        $cancelRes->is_cancelled = 1;
                        $cancelRes->save();

                        // Set as succeeded
                        $network->reservation_status = 'success';
                        $network->save();

                        $mailtemplate = new MailTemplate();
                        
                        if (Setting::get('cronjobs.thirdparty_mail') != NULL) {
                            // sendMail mail to client
                            $mailtemplate->sendMail(array(
                                'email' => $network->email,
                                'template_id' => 'cancelled-reservation-client',
                                'company_id' => $network->restaurant_id,
                                'replacements' => array(
                                    '%name%' => $network->name,
                                    '%phone%' => $network->phone,
                                    '%email%' => $network->email,
                                    '%date%' => date('d-m-Y', strtotime($network->reservation_date)),
                                    '%time%' => date('H:i', strtotime($network->reservation_date)),
                                    '%persons%' => $network->persons,
                                    '%comment%' => $network->comment,
                                    '%saldo%' => $user->saldo
                                )
                            ));

                            // Send mail to owner
                            $mailtemplate->sendMail(array(
                                'email' => $network->email,
                                'template_id' => 'cancelled-reservation-client-company',
                                'company_id' => $network->restaurant_id,
                                'replacements' => array(
                                    '%name%' => $network->name,
                                    '%phone%' => $network->phone,
                                    '%email%' => $network->email,
                                    '%date%' => date('d-m-Y', strtotime($network->reservation_date)),
                                    '%time%' => date('H:i', strtotime($network->reservation_date)),
                                    '%persons%' => $network->persons,
                                    '%comment%' => $network->comment,
                                    '%saldo%' => $user->saldo
                                )
                            ));
                        }
                    } 
                } 
            }
        }
    }

    public function handle()
    {
        $commandName = 'third_party';

        if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
            // Start cronjob
            $this->line(' Start '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 1);
            Setting::save();

            // Processing
            try {
                $this->removeDuplicates();
                $this->handleReservations();
                $this->setAsGuest();  
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
