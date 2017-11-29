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

class Couverts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'couverts:guest';

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

        $this->companies = Company::select(
            'zipcode',
            'id'
        )
            ->where('no_show', 0)
            ->where('zipcode', '!=', '')
            ->get()
        ;
        
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
            ->where('network', 'couverts')
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
                    ->where('network', 'couverts')
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

        $companiesArray = $this->companies
            ->keyBy(function ($company) {
                return $company->zipcode;
            })
            ->map(function ($company) {
                return $company->id;
            }
        );

        $hostname = '{mail.uwvoordeelpas.nl:143/novalidate-cert}INBOX';
        $username = 'reserveren@uwvoordeelpas.nl';
        $password = 'YgakzR55QHY4Rx4hNDQN';

        $connection = imap_open($hostname, $username, $password) or die('Cannot connect to Gmail: ' . imap_last_error());
        $emails = imap_search($connection, 'ALL');

        if ($emails) {
            rsort($emails);
            $reservationNumber = array();
            foreach ($emails as $emailNumber) {
                $message2 = strip_tags(quoted_printable_decode(imap_fetchbody($connection, $emailNumber, 2)), '<br /> ');

                if (trim($message2) != '') {
                    $messageHeader = imap_headerinfo($connection, $emailNumber);

                    $message2 = preg_replace('/&nbsp;/i', ' ', $message2);
                    $message2 = preg_replace('/(heer)\/(mevrouw) /i', ' ', $message2);
                    
                    // Network
                    preg_match('/Couverts|couverts/i', $message2, $networkMatches, PREG_OFFSET_CAPTURE);

                    // Name
                    preg_match('/(Beste|Geachte|Hallo) ?((heer|meneer|mevrouw))? (.*?),/i', $message2, $nameMatches, PREG_OFFSET_CAPTURE);

                    // Persons
                    preg_match('/Aantal personen:(\d+)/i', $message2, $personsMatches, PREG_OFFSET_CAPTURE);

                    // Date
                    preg_match('/Wanneer:.*?([0-9]{1,2} .*? [0-9]{4})/i', $message2, $dateMatches, PREG_OFFSET_CAPTURE);

                    // Time 
                    preg_match('/\b(24:00|2[0-3]:\d\d|[01]?\d((:\d\d)( ?(a|p)m?)?| ?(a|p)m?))\b uur/i', $message2, $timeMatches, PREG_OFFSET_CAPTURE);
                    
                    // Address + zipcode
                    preg_match('/Adres:(.*? [0-9]+) ([0-9]{4}\s?[A-Za-z]{2})/i', $message2, $addressMatch, PREG_OFFSET_CAPTURE);

                    // Restaurant name
                    preg_match('/Waar:(\w+\s?\w+)/i', $message2, $restaurantMatch, PREG_OFFSET_CAPTURE);

                    preg_match('/(gewijzigd|geannuleerd)/i', imap_utf8($messageHeader->subject), $subjectStatusMatches, PREG_OFFSET_CAPTURE);
                    
                    $dates = (isset($dateMatches[1][0]) ? explode(' ', $dateMatches[1][0]) : '');
                    $zipcode = (isset($addressMatch[2][0]) ? str_replace(' ', '', $addressMatch[2][0]) : '');

                    // Only couverts mails
                    
                    if (isset($networkMatches[0][0]) && strtolower($networkMatches[0][0]) == 'couverts') {
                        $message1 = quoted_printable_decode(imap_fetchbody($connection, $emailNumber, 2));

                        $html = new \DOMDocument();
                        @$html->loadHTML($message1);

                        $xpath = new \DOMXPath($html);

                        foreach($html->getElementsByTagName('meta') as $meta) {
                            if ($meta->getAttribute('itemprop') == 'reservationNumber') { 
                                $reservationNumber[$emailNumber] = $meta->getAttribute('content');
                            }
                        }

                        foreach($xpath->query('//*[@itemprop]') as $div) {
                            if ($div->getAttribute('itemprop') == 'underName') { 
                                foreach($div->getElementsByTagName('meta') as $meta) {
                                    if ($meta->getAttribute('itemprop') == 'name') { 
                                        $name = $meta->getAttribute('content');
                                    }
                                }
                            }

                            if ($div->getAttribute('itemprop') == 'reservationFor') { 
                                foreach($div->getElementsByTagName('meta') as $meta) {
                                    if ($meta->getAttribute('itemprop') == 'name') { 
                                        $companyName = $meta->getAttribute('content');
                                    }
                                }
                            }
                        }

                        foreach ($html->getElementsByTagName('link') as $link) {
                            if ($link->getAttribute('itemprop') == 'reservationStatus') { 
                                $networkStatus = $link->getAttribute('href');
                            }
                        }

                        if (isset($networkStatus)) {
                            switch ($networkStatus) {
                                case 'http://schema.org/Cancelled':
                                    $networkStatus = 'cancelled';
                                break;
                                        
                                case 'http://schema.org/Confirmed':
                                    $networkStatus = 'confirmed';
                                break;
                            }

                            if (isset($subjectStatusMatches[0][0])) {
                                switch ($subjectStatusMatches[0][0]) {
                                    case 'gewijzigd':
                                        $networkStatus = 'updated';
                                    break;

                                    case 'geannuleerd':
                                        $networkStatus = 'cancelled';
                                    break;
                                            
                                }
                            }

                            if (!in_array($emailNumber, array_flatten($this->thirdParty))) {
                                $reservationsArray[] = array(
                                    'network_status' => $networkStatus,
                                    'reservation_status' => 'pending',
                                    'reservation_number' => isset($reservationNumber[$emailNumber]) ? $reservationNumber[$emailNumber] : NULL,
                                    'reservation_date' => (isset($dateMatches[1][0]) ? $dates[2].'-'.$this->convertMonth($dates[1]).'-'.($dates[0] < 10 ? 0 : '').$dates[0] : '').' '.(isset($timeMatches[1][0]) ? $timeMatches[1][0].':00' : ''),
                                    'name' => isset($nameMatches[4][0]) ? ucwords($nameMatches[4][0]) : NULL,
                                    'persons' => isset($personsMatches[1][0]) ? $personsMatches[1][0] : NULL,
                                    'restaurant_id' => isset($companiesArray[$zipcode]) ? $companiesArray[$zipcode] : NULL,
                                    'restaurant_name' => isset($restaurantMatch[1][0]) ? $restaurantMatch[1][0] : NULL,
                                    'restaurant_zipcode' => isset($addressMatch[2][0]) ? $zipcode : NULL,
                                    'restaurant_address' => isset($addressMatch[1][0]) ? $addressMatch[1][0] : NULL,                            
                                    'created_at' => date('Y-m-d H:i:s'),
                                    'network' => 'couverts',
                                    'mail_id' => $emailNumber
                                ); 
                            }
                        }
                    }

                }
            }
        } 

        if (isset($reservationsArray)) {
            GuestThirdParty::insert($reservationsArray);
        }

        imap_close($connection);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'couverts_guests';

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
