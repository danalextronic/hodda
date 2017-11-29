<?php

namespace App\Console\Commands\Other;

use Illuminate\Console\Command;
use App;
use App\Models\CompanyCallcenter;
use Config;
use Exception;
use URL;
use Setting;
use Mail;

class EetNU extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'eetnu:other';

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

        $companies = CompanyCallcenter::select(
            'name'
        )
            ->get()
        ;

        $this->names = $companies
            ->map(function ($company) {
                return $company->name;
            })
            ->toArray()
        ;

        $this->cities = json_decode(Setting::get('filters.cities'));
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function addCompanies()
    {
                if (is_array(json_decode(Setting::get('filters.kitchens')))) {
                    $foodSeperated = json_decode(Setting::get('filters.kitchens'));


                for ($i = 1; $i < 5; $i++) { 
                    foreach ($foodSeperated as $kitchen) {
                            $locationCurl = curl_init();
                            curl_setopt($locationCurl, CURLOPT_URL, 'https://api.eet.nu/venues?page='.$i.'&tags='.$kitchen);
                            curl_setopt($locationCurl, CURLOPT_TIMEOUT, 30);
                            curl_setopt($locationCurl, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($locationCurl, CURLOPT_CAINFO, base_path('cacert.pem'));
                            curl_setopt($locationCurl, CURLOPT_VERBOSE, true);

                            $execCurl = json_decode(curl_exec($locationCurl))->results;

                            if (is_array($execCurl)) {
                                foreach ($execCurl as $company) {
                                    if ($this->cities == NULL OR !in_array(str_slug($company->address->city), json_decode(json_encode($this->cities, true)))) {
                                            if (!in_array($company->name, $this->names)) {
                                                $companies[] = array(
                                                    'slug' => str_slug($company->name),
                                                    'name' => $company->name,
                                                    'phone' => $company->telephone,
                                                    'contact_phone' => $company->telephone,
                                                    'address' => $company->address->street,
                                                    'zipcode' => $company->address->zipcode,
                                                    'city' => $company->address->city,
                                                    'created_at' => date('now')
                                                );

                                                $this->line('Add - '.$company->name.'; Kitchen: '.$kitchen);
                                            }
                                    }
                                }
                            }
                    }
                }
                }

                if (isset($companies)) {
                    CompanyCallcenter::insert(array_unique($companies, SORT_REGULAR));
                }
    }

    public function handle()
    {
        $commandName = 'call_list';

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
                    $this->addCompanies(); 
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
