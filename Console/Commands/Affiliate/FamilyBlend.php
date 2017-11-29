<?php

namespace App\Console\Commands\Affiliate;

use Illuminate\Console\Command;
use App\Models\Category;
use App\Models\Affiliate;
use App\Models\AffiliateCategory;
use App\Helpers\AffiliateHelper;
use Exception;
use Intervention\Image\ImageManagerStatic;
use Intervention\Image\Exception\NotReadableException;
use Mail;
use SoapClient;
use SoapFault;
use Request;
use Setting;
use anlutro\cURL\cURL;

class FamilyBlend extends Command
{

    /**
     * @var string
     */
    protected $signature = 'familyblend:affiliate';

    /**
     * @var string
     */
    protected $description = 'Import familyblend feed to database';

    /**
     * @var string
     */
    protected $affiliate_network = 'familyblend';

    /**
     * @var array
     */
    protected $parents = array();

    /**
     * @var array
     */
    protected $parentsChilds = array();

    /**
     * @var array
     */
    protected $temporaryParents = array();

    /**
     * @var array
     */
    protected $temporaryChilds = array();

    /**
     * @var array
     */
    protected $parentsArray = array();

    /**
     * @var array
     */
    protected $commissions = array();

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->affiliateHelper = new AffiliateHelper;
        $this->curl = new cURL;
        $this->login = 'marketing@uwvoordeelpas.nl';
        $this->apiKey = '74gkNmrDAaNyeGImhQXOn5cRjqdayDMG';
    }


    public function checkConnection()
    {
        $connection = $this->curl->newRequest(
            'GET', 'http://api.affiliate4you.nl/1.0/campagnes/all.csv?email='.$this->login.'&apikey='.$this->apiKey
        )
        //         ->setUser(Setting::get('settings.daisycon_name'))
        //         ->setPass(Setting::get('settings.daisycon_pw'))
            ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
            ->send()
        ;

        echo $connection->body;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $this->checkConnection();
        // if (Setting::get('cronjobs.affilinet_affiliate') == NULL) {
        //     echo 'This command is not working right now. Please activate this command.';
        // } else {
        //     $this->line(' Start '.$this->signature);

          
        // }
    }
}