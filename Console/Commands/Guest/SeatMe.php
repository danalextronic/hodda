<?php

namespace App\Console\Commands\Guest;

use App\Models\Reservation;
use App\Models\Company;
use App\Models\CompanyReservation;
use App\Models\GuestThirdParty;
use App\Models\MailTemplate;
use Mail;
use Illuminate\Console\Command;
use Exception;
use Sunra\PhpSimple\HtmlDomParser;
use Config;
use DB;
use Request;

class SeatMe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'seatme:guest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $hostname = '{mail.uwvoordeelpas.nl:143/novalidate-cert}INBOX';

    protected $username = 'reserveren@uwvoordeelpas.nl';

    protected $password = 'YgakzR55QHY4Rx4hNDQN';

    protected $fromAddress = 'seatme.nl';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->companies = Company::select(
            'zipcode',
            'id'
        )
            ->where('no_show', 0)
            ->where('zipcode', '!=', '')
            ->get()
        ;
        
        $this->reservation = array();

        $this->thirdParty = GuestThirdParty::select(
           'mail_id'
        )
            ->get()
            ->toArray()
        ;

        $this->reservationModel = new Reservation();
    }

    public function convertMonth($name) 
    {
        $months = array();

        foreach (Config::get('preferences.months') as $key => $month) {
            $months[$month] = ($key < 10 ? 0 : '').$key;
        }

        return $months[$name];
    }

    public function removeGender($name)
    {
       return preg_replace('/(heer|mevrouw|meneer)/i', '', $name); 
    }

    public function addGuests() 
    {
        $reservationExists = array();
        $connection = imap_open($this->hostname, $this->username, $this->password) or die('Cannot connect to Gmail: ' . imap_last_error());
        $emailList = imap_search($connection, 'UNSEEN FROM "seatme.nl"');

        if ($emailList) {
            session(array('offset_add_seatme_command' => count(session('offset_add_seatme_command')) == 0 ? 0 : session('offset_add_seatme_command') + 41));

            $limit = 40; // define results limit
            $offset = session('offset_add_seatme_command'); // define offset
            $i = 0; // start line counter

            foreach ($emailList as $emailNumber) {
                if ($i <= count($emailList)) {
                    if ($i++ < $offset) continue;
                    if ($i > $offset + $limit) break;

                    $message = imap_body($connection, $emailNumber);

                    $messageBody = trim(preg_replace('/\s\s+/', ' ', strip_tags(base64_decode($message))));

                    if (base64_encode(base64_decode($messageBody, true)) === $messageBody) {
                        $messageBody = trim(preg_replace('/=\r\n|\r\n/', '', $message));
                    } else {
                        $messageBody = trim(preg_replace('/\s\s+/', ' ', strip_tags(base64_decode($message))));
                    }

                    // Name
                    preg_match('/Naam(:?)(heer|mevrouw)?(.*?) (Telefoonnummer|Telefoon:?)/i', $messageBody, $nameMatches, PREG_OFFSET_CAPTURE);

                    // Phone
                    preg_match('/(Telefoonnummer|Telefoon) (.*?) E-mail/i', $messageBody, $phoneMatches, PREG_OFFSET_CAPTURE);

                    // Email
                    preg_match('/(E-mail|Email:|Email|E-mail:?) (.*?) (Notities|Eventuele promotie|RESTAURANT GEGEVENS)/i', $messageBody, $emailMatches, PREG_OFFSET_CAPTURE);
                        
                    // Persons
                    preg_match('/(Aantal personen|Aantal personen:) (\d+) Reserveringsnummer/i', $messageBody, $personsMatches, PREG_OFFSET_CAPTURE);
                        
                    // Reservation number
                    preg_match('/(Reserveringsnummer|Reserveringsnummer:) (\d+) (Gastgegevens|GAST GEGEVENS)/i', $messageBody, $numberMatches, PREG_OFFSET_CAPTURE);
                
                    // Date
                    preg_match('/(Datum reservering|Datum reservering:) (.*?) (Start|Aanvangstijd reservering)/i', $messageBody, $dateMatches, PREG_OFFSET_CAPTURE);
                 
                    // Time
                    preg_match('/(Starttijd reservering|Starttijd reservering:|Aanvangstijd reservering|Aanvangstijd reservering:) (.*?) Aantal personen/i', $messageBody, $timeMatches, PREG_OFFSET_CAPTURE);
   
                    // Company Name
                    preg_match('/voor (.*?) Reserveringsgegevens/i', $messageBody, $companyNamesMatches, PREG_OFFSET_CAPTURE);
                    preg_match('/RESERVERINGS GEGEVENS (Restaurant:?) (.*?) Datum/i', $messageBody, $companyNamesMatchesTwo, PREG_OFFSET_CAPTURE);

                    // Company Name
                    preg_match('/(annulering|gewijzigd)/i', $messageBody, $statusMatches, PREG_OFFSET_CAPTURE);
                    
                    // Comment
                    preg_match('/(Eventuele notitie|Eventuele notitie:|Notities:) (.*?) (Eventuele promotie|Eventuele Cadeaukaart)/i', $messageBody, $commentMatches, PREG_OFFSET_CAPTURE);
                   
                    if (isset($statusMatches[0][0])) {
                        switch ($statusMatches[0][0]) {
                            case 'annulering':
                                $networkStatus = 'cancelled';
                                break;

                            case 'gewijzigd':
                                $networkStatus = 'updated';
                                break;
                        }
                    } else {
                        $networkStatus = 'confirmed';
                    }

                    $dates = (isset($dateMatches[2][0]) ? explode(' ', $dateMatches[2][0]) : '');

                    if (!in_array($emailNumber, array_flatten($this->thirdParty))) {
                        $reservationsArray[] = array(
                            'reservation_date' => isset($dateMatches[1][0]) ? ($dates[2].'-'.$this->convertMonth($dates[1]).'-'.$dates[0]).' '.(isset($timeMatches[2][0]) ? $timeMatches[2][0].':00' : '') : '',
                            'network_status' => $networkStatus,
                            'reservation_status' => 'pending',
                            'restaurant_name' => isset($companyNamesMatches[1][0]) ? ucwords($companyNamesMatches[1][0]) : (isset($companyNamesMatchesTwo[2][0]) ? ucwords($companyNamesMatchesTwo[2][0]) : ''),   
                            'name' => isset($nameMatches[3][0]) ? $this->removeGender(ucwords($nameMatches[3][0])) : NULL,   
                            'email' => isset($emailMatches[2][0]) ? strtolower($emailMatches[2][0]) : NULL,   
                            'reservation_number' => isset($numberMatches[2][0]) ? $numberMatches[2][0] : NULL,   
                            'persons' => isset($personsMatches[2][0]) ? $personsMatches[2][0] : NULL,   
                            'phone' => isset($phoneMatches[2][0]) ? $phoneMatches[2][0] : NULL,   
                            'comment' => isset($commentMatches[2][0]) ? $commentMatches[2][0] : '',   
                            'created_at' => date('Y-m-d H:i:s'),
                            'network' => 'seatme',
                            'mail_id' => $emailNumber
                        ); 
                    }
                }
            }
        }

        if (isset($reservationsArray)) {
            GuestThirdParty::insert($reservationsArray);
        }

        imap_close($connection);
    }

    public function updateReservation() 
    {
        $reservationExists = array();
        $connection = imap_open($this->hostname, $this->username, $this->password) or die('Cannot connect to Gmail: ' . imap_last_error());
        $emailList = imap_search($connection, 'SEEN FROM "seatme.nl"');

        if ($emailList) {
            session(array('offset_update' => count(session('offset_update')) == 0 ? 0 : session('offset_update') + 41));

            $limit = 40; // define results limit
            $offset = session('offset_update'); // define offset
            $i = 0; // start line counter

            foreach ($emailList as $emailNumber) {
                if ($i <= count($emailList)) {
                    if ($i++ < $offset) continue;
                    if ($i > $offset + $limit) break;

                        $message = imap_body($connection, $emailNumber);

                        $messageBody = trim(preg_replace('/\s\s+/', ' ', strip_tags(base64_decode($message))));
                        if (base64_encode(base64_decode($messageBody, true)) === $messageBody) {
                            $messageBody = trim(preg_replace('/=\r\n|\r\n/', '', $message));
                        } else {
                            $messageBody = trim(preg_replace('/\s\s+/', ' ', strip_tags(base64_decode($message))));
                        }

                        // Phone
                        preg_match('/(Telefoonnummer|Telefoon) (.*?) E-mail/i', $messageBody, $phoneMatches, PREG_OFFSET_CAPTURE);

                        if (in_array($emailNumber, array_flatten($this->thirdParty)) && isset($phoneMatches[2][0])) {
                            $update = GuestThirdParty::where('mail_id', $emailNumber)->first();
                            $update->phone = $phoneMatches[2][0];
                            $update->save();

                            $this->line('Mail id: '.$emailNumber.' has been updated. #seatme');
                        }
                }
            }

            return (isset($reservationsArray) >= 1 ? $reservationsArray : NULL);
        }

        imap_close($connection);
    }

    /**
     * Set zipcodes for results with the same restaurant name
     *
     * @return mixed
     */
    public function updateGuests()
    {
        // Reservations with a zipcode
        $thirdPartyQuery = GuestThirdParty::select(
           'restaurant_id',
           'restaurant_name',
           'restaurant_address',
           'restaurant_zipcode'
        )
            ->whereNotNull('restaurant_zipcode')
            ->whereNotNull('restaurant_id')
            ->where('network', 'seatme')
            ->get()
        ;

        foreach ($thirdPartyQuery as $thirdPartyFetch) {
            $thirdPartyArray[$thirdPartyFetch->restaurant_name] = array(
                'restaurant_id' => $thirdPartyFetch->restaurant_id,
                'restaurant_address' => $thirdPartyFetch->restaurant_address,
                'zipcode' => $thirdPartyFetch->restaurant_zipcode
            );
        }

        // Set a zipcode for reservations with the same restaurant name
        if (isset($thirdPartyArray)) {
            foreach ($thirdPartyArray as $thirdPartyArrayName => $thirdPartyArrayFetch) {
                $thirdPartyReservationsQuery = GuestThirdParty::where('restaurant_name', $thirdPartyArrayName)
                    ->where('network', 'seatme')
                    ->where(function ($query) {
                        $query
                            ->whereNull('restaurant_id')
                            ->orWhereNull('restaurant_zipcode')
                        ;
                    })
                    ->update(array(
                        'restaurant_id' => $thirdPartyArrayFetch['restaurant_id'],
                        'restaurant_address' => $thirdPartyArrayFetch['restaurant_address'],
                        'restaurant_zipcode' => $thirdPartyArrayFetch['zipcode']
                    ))
                ;
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
        $commandName = 'seatme_guests';

        if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
            // Start cronjob
            $this->line(' Start '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 1);
            Setting::save();

            // Processing
            try  {
                $this->addGuests(); 
                $this->updateGuests();
                $this->updateReservation();
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
