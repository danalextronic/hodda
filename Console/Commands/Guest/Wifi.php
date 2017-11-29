<?php

namespace App\Console\Commands\Guest;

use Illuminate\Console\Command;
use Exception;
use App\Models\Company;
use App\Models\Guest;
use App\Models\WifiGuest;
use Sentinel;
use Setting;
use Mail;

class Wifi extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wifi:guest';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->headers = array(
            'sn-apikey: 1b84414577b805456af2380fe96317c4'
        );
    }

    public function isJson($string)
    {
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    public function checkEmailAndDomain($_email)
    {
        $exp = "/^(.*)@(.*)$/";
        preg_match($exp, $_email, $matches);

        if (!empty($matches[1]) and (!filter_var($_email, FILTER_VALIDATE_EMAIL)))
            return (false);

        return (checkdnsrr($matches[2], 'MX'));
    }

    public function getLocations()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.hotspotsystem.com/v2.0/locations');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CAINFO, base_path('cacert.pem'));
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ($info['http_code'] === 200) {
            $json = json_decode($response, true);

            return $json['items'];
        }

        curl_close($ch);
    }

    public function getCustomers($locationId, $i)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.hotspotsystem.com/v2.0/locations/'.$locationId.'/customers?limit=100&offset='.$i);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CAINFO, base_path('cacert.pem'));
        curl_setopt($ch, CURLOPT_VERBOSE, true);

        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ($info['http_code'] === 200) {
            $json = json_decode($response, true);
            return $json['items'];
        }

        curl_close($ch);
    }

    public function addGuests()
    {
        $zipcodesArray = array();
        $zipcodeLocation = array();

        // Get locations
        foreach ($this->getLocations() as $location) {
            $zipcode = preg_replace('/\s+/', '', $location['zip']);

            $zipcodesArray[] = $zipcode;
            $zipcodeLocation[$zipcode][strtolower($location['address'])] = (int) $location['id'];
        }

        // Get company by zipcode
        if (count($zipcodesArray) >= 1) {
            $zipcodesImplode = implode("', '", $zipcodesArray);

            $companies = Company::whereRaw("REPLACE(zipcode, ' ', '') IN('".$zipcodesImplode."')")
                ->where('no_show', 0)
                ->get()
            ;

            foreach ($companies as $company) {
                $zipcode = preg_replace('/\s+/', '', $company->zipcode);

                if (isset($zipcodeLocation[$zipcode][strtolower($company->address)])) {
                    $locationCompanies[] = array(
                        'regio' => $company->regio,
                        'name' => $company->name,
                        'id' => $company->id,
                        'locationId' => $zipcodeLocation[$zipcode][strtolower($company->address)]
                    );
                }
            }

            Setting::set('last_wifi_row', Setting::get('last_wifi_row') + 25);
            Setting::save();

            $lastPage = Setting::get('last_wifi_row');

            foreach ($locationCompanies as $locationArrayId => $locationArray) {
                $locationId = $locationArray['locationId'];

                for ($i = ($lastPage == 25 ? 0 : $lastPage); $i < ($lastPage == 25 ? 25 : $lastPage + 25); $i++) { 
                    if (count($this->getCustomers($locationId, $i)) > 0) {
                        foreach ($this->getCustomers($locationId, $i) as $customer) {
                            if (
                                $customer['email'] != null
                                && $customer['name'] != null
                                && $this->checkEmailAndDomain($customer['email']) == TRUE
                            ) {
                                $jsonRegio = (is_array(json_decode($locationArray['regio'])) ? $locationArray['regio'] : json_encode((array) $locationArray['regio']));

                                $customerArray[$locationId][str_slug($customer['name'])] = array(
                                    'name' => ucwords($customer['name']),
                                    'email' => strtolower($customer['email']),
                                    'phone' => $customer['phone'],
                                    'regio' => $jsonRegio,
                                    'com' => $locationArray['name']
                                );
                            }
                        }
                    }
                }

                if (isset($customerArray[$locationId])) {
                    foreach ($customerArray[$locationId] as $customer) {
                        $userCheck = Sentinel::findByCredentials(array('login' => $customer['email']));
               
                        $randomPassword = str_random(20);

                        // Add new User
                        if (count($userCheck) == 0) {
                            $user = Sentinel::registerAndActivate(array(
                                'email' => $customer['email'],
                                'password' => $randomPassword
                            ));

                            $user->name = $customer['name'];
                            $user->phone = $customer['phone'];
                            $user->expire_code = str_random(64);
                            $user->terms_active = 1;

                            if (trim($customer['regio']) != '') {
                                $user->city = json_encode((array) $customer['regio']);
                            }

                            $user->save();

                            $userId = $user->id;
                        } else {
                            $userId = $userCheck->id;
                        }

                        $guest = new Guest();

                        $guest->addGuest(array(
                            'user_id' => $userId,
                            'company_id' => $locationArray['id']
                        ));

                        // Add as wifi guest
                        $wifiGuestCheck = WifiGuest::where('email', $customer['email'])
                            ->where('company_id', $locationArray['id'])
                            ->get()
                        ;

                        if (count($wifiGuestCheck) == 0) {
                            $wifiguest = new WifiGuest();
                            $wifiguest->name = $customer['name'];
                            $wifiguest->email = $customer['email'];
                            $wifiguest->phone = $customer['phone'];
                            $wifiguest->company_id = $locationArray['id'];
                            $wifiguest->save();
                        }
                    }
                }
            }
        } 
    }

    public function handle()
    {
        $commandName = 'wifi_guest';

        if (Setting::get('cronjobs.'.$commandName) == NULL) {
            echo 'This command is not working right now. Please activate this command.';
        } else {
            if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
                // Start cronjob
                $this->line('Start '.$this->signature);
                Setting::set('cronjobs.active.'.$commandName, 1);
                Setting::save();

                // Processing
                try {
                    $this->addGuests();
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
