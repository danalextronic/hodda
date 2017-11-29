<?php

namespace App\Console\Commands\Transaction;

use Illuminate\Console\Command;
use anlutro\cURL\cURL;
use App\Models\Transaction;
use App\User;
use Sentinel;
use Exception;
use SoapClient;
use Setting;
use Mail;

class Affilinet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */   
    protected $signature = 'affilinet:transaction';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * The affiliate network
     *
     * @var string
     */
    protected $affiliate_network = 'affilinet';

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
    public function handle()
    {
        // // Set webservice endpoints
        // define("WSDL_LOGON", "https://api.affili.net/V2.0/Logon.svc?wsdl");
        // define("WSDL_STATS", "https://api.affili.net/V2.0/PublisherStatistics.svc?wsdl");
         
        // // Set credentials
        // $username = ''; // the publisher ID
        // $password = ''; // the publisher web services password
         
        // // Send a request to the Logon Service to get an authentication token
        // $soapLogon = new SoapClient(WSDL_LOGON);
        // $token = $soapLogon->Logon(array(
        //     'Username' => Setting::get('settings.affilinet_name'),
        //     'Password' => Setting::get('settings.affilinet_pw'),
        //     'WebServiceType' => 'Publisher'
        // ));
         
        // // Set page setting parameters
        // $pageSettings = array(
        //     'CurrentPage' => 1,
        //     'PageSize' => 5,
        // );
         
        // // Set transaction query parameters
        // $startDate = strtotime("-2 weeks");
        // $endDate = strtotime("today");
        
        // $rateFilter = array(
        //     'RateMode' => 'PayPerSale',
        //     'RateNumber' => 1
        // );

        // $transactionQuery = array(
        //     'StartDate' => $startDate,
        //     'EndDate' => $endDate,
        //     'RateFilter' => $rateFilter,
        //     'TransactionStatus' => 'All',
        //     'ValuationType' => 'DateOfRegistration'
        // );
         
        // // Send a request to the Publisher Statistics Service
        // $soapRequest = new SoapClient(WSDL_STATS);
        // $response = $soapRequest->GetTransactions(array(
        //     'CredentialToken' => $token,
        //     'PageSettings' => $pageSettings,
        //     'TransactionQuery' => $transactionQuery
        // ));
         
        // // Show response
        // print_r($response);
    }
}