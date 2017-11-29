<?php

namespace App\Console\Commands\Guest;

use App\Models\Reservation;
use App\Models\Company;
use App\Models\CompanyReservation;
use App\Models\GuestThirdParty;
use Mail;
use Illuminate\Console\Command;
use Exception;
use Config;
use DB;

class EetNu extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'eetnu:guest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    protected $hostname = '{mail.uwvoordeelpas.nl:143/novalidate-cert}INBOX';

    protected $username = 'reserveren@uwvoordeelpas.nl';

    protected $password = 'YgakzR55QHY4Rx4hNDQN';

    protected $fromAddress = 'eet.nu';

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
            ->where('network', 'eetnu')
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
                    ->where('network', 'eetnu')
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

    public function addGuests() 
    {
        $reservationExists = array();

        $connection = imap_open($this->hostname, $this->username, $this->password) or die('Cannot connect to Gmail: ' . imap_last_error());
        $emailList = imap_search($connection, 'ALL');

        if ($emailList) {
            foreach ($emailList as $emailNumber) {
                $messageHeader = imap_headerinfo($connection, $emailNumber);

                if ($messageHeader->sender[0]->host == $this->fromAddress) {
                    // Remove html and enters from message body
                    $messageBody =  imap_fetchbody($connection, $emailNumber, 1); 

                    // Name
                    preg_match('/Contactinformatie:(\r\n)(.*)/im', $messageBody, $nameMatches, PREG_OFFSET_CAPTURE);
                    
                    // Email
                    preg_match('/E-mailadres: ((((\w+)(_|\.))+)?(\w+)(@)(\w+\.)((\w+\.)+)?\w+)/im', $messageBody, $emailMatches, PREG_OFFSET_CAPTURE);

                    // Reservation number
                    preg_match('/(Reserveringsnummer: (\d+)|reserveringsnummer (\d+) op)/i', $messageBody, $numberMatches, PREG_OFFSET_CAPTURE);
                   
                    // Phone
                    preg_match('/(Telefoonnummer|Telefooonnummer): (.*)/i', $messageBody, $phoneMatches, PREG_OFFSET_CAPTURE);
                    
                    // Persons
                    preg_match('/((Aantal personen|Aantal personen:) (\d+)|voor (\d+) gasten)/i', $messageBody, $personsMatches, PREG_OFFSET_CAPTURE);
                    
                    // Satus
                    preg_match('/(geannuleerd|nieuwe reserveringsaanvraag)/i', $messageBody, $statusMatches, PREG_OFFSET_CAPTURE);
                    
                    // Comment
                    preg_match('/Opmerkingen:(\r\n)(.*)/im', $messageBody, $commentMatches, PREG_OFFSET_CAPTURE);

                    // Date
                    preg_match('/(Datum:.*?([0-9]{1,2} .*? [0-9]{4})|op.*?([0-9]{1,2} .*? [0-9]{4}) om)/i', $messageBody, $dateMatches, PREG_OFFSET_CAPTURE);

                    // Time 
                    preg_match('/(Tijd: \b(24:00|2[0-3]:\d\d|[01]?\d((:\d\d)( ?(a|p)m?)?| ?(a|p)m?))\b uur|om \b(24:00|2[0-3]:\d\d|[01]?\d((:\d\d)( ?(a|p)m?)?| ?(a|p)m?))\b voor)/i', $messageBody, $timeMatches, PREG_OFFSET_CAPTURE);

                    if (isset($statusMatches[0][0])) {
                        switch ($statusMatches[0][0]) {
                            case 'geannuleerd':
                                $networkStatus = 'cancelled';
                                break;

                            default:
                                $networkStatus = 'confirmed';
                                break;
                        }
                    } 

                    $dates = (isset($dateMatches[3][0]) ? explode(' ', $dateMatches[3][0]) : (isset($dateMatches[2][0]) ? explode(' ', $dateMatches[2][0]) : ''));
                    $time = (isset($timeMatches[8][0]) && trim($timeMatches[8][0]) != '' ? $timeMatches[8][0] : (isset($timeMatches[2][0]) && trim($timeMatches[2][0]) != '' ? $timeMatches[2][0] : ''));

                    $reservationNumber = isset($numberMatches[2][0]) && trim($numberMatches[2][0]) != '' ? $numberMatches[2][0] : (isset($numberMatches[3][0]) ? $numberMatches[3][0] : NULL);
                    
                    if (!in_array($emailNumber, array_flatten($this->thirdParty))) {
                        $reservationsArray[] = array(
                            'reservation_date' => (isset($dateMatches[1][0]) ? $dates[2].'-'.$this->convertMonth($dates[1]).'-'.sprintf('%02d', $dates[0]).' '.$time.':00' : ''),
                            'restaurant_name' => $messageHeader->to[0]->personal,
                            'network_status' => $networkStatus,
                            'reservation_status' => 'pending',
                            'name' => isset($nameMatches[2][0]) ? ucwords($nameMatches[2][0]) : NULL,   
                            'email' => isset($emailMatches[1][0]) ? strtolower($emailMatches[1][0]) : NULL,   
                            'phone' => isset($phoneMatches[2][0]) ? strtolower($phoneMatches[2][0]) : NULL,   
                            'reservation_number' => $reservationNumber,   
                            'persons' => isset($personsMatches[4][0]) && trim($personsMatches[4][0]) != '' ? $personsMatches[4][0] : (isset($personsMatches[3][0]) ? $personsMatches[3][0] : NULL),   
                            'comment' => isset($commentMatches[2][0]) ? $commentMatches[2][0] : '',   
                            'created_at' => date('Y-m-d H:i:s'),
                            'network' => 'eetnu',
                            'mail_id' => $emailNumber
                        ); 
                    }
                }
            }
            
            if (isset($reservationsArray)) {
                GuestThirdParty::insert($reservationsArray);
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
        $commandName = 'eetnu_guests';

        if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
            // Start cronjob
            $this->line(' Start '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 1);
            Setting::save();

            // Processing
            try {
                $this->addGuests(); 
                $this->updateGuests();
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
