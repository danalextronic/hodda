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
use Request;
use Lang;
use anlutro\cURL\cURL;
use Setting;

class Daisycon extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'daisycon:affiliate';

    /**
     * @var string
     */
    protected $affiliate_network = 'daisycon';

    /**
     * @var string
     */
    protected $fieldsPrograms = 'id,name,url,logo,tracking_duration,primary_category_id,category_ids,status,cashback,commission';

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

        $this->lastAffiliates = Affiliate::select(
            'id',
            'name'
        )
            ->get()
        ; 
                
        $this->affiliates = Affiliate::select(
            'program_id'
        )
            ->where('affiliate_network', $this->affiliate_network)
            ->get()
            ->toArray()
        ; 

        $this->categories = Category::select(
            'id',
            'slug',
            'name'
        )
            ->where('subcategory_id', 0)
            ->get()
            ->toArray()
        ;

        $this->adspaceId = 2121484;

        $this->lastId = count($this->lastAffiliates) >= 1 ? $this->lastAffiliates->last()->id : 0;
    }

    public function checkConnection() 
    {
        try {
            set_time_limit(0);

            $curl = new cURL;

            $this->feedCategories = $curl->newRequest(
                'GET', 'https://services.daisycon.com/categories?page=1&per_page=100'
            )
                ->setUser(Setting::get('settings.daisycon_name'))
                ->setPass(Setting::get('settings.daisycon_pw'))
                ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
                ->send()
            ;

            for ($i = 1; $i < 5; $i++) { 
                $this->feedCommissions[] = $curl->newRequest(
                    'GET', 'https://services.daisycon.com/publishers/370506/media/246340/commissions?page='.$i.'&per_page=1000'
                )
                    ->setUser(Setting::get('settings.daisycon_name'))
                    ->setPass(Setting::get('settings.daisycon_pw'))
                    ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
                    ->send()
                ;
            }

            for ($i = 1; $i < 5; $i++) { 

                $this->client[] = $curl->newRequest(
                    'GET', 'https://services.daisycon.com/publishers/370506/programs?page='.$i.'&locale_id=1&per_page=1000&type=affiliatemarketing&fields='.$this->fieldsPrograms
                )
                    ->setUser(Setting::get('settings.daisycon_name'))
                    ->setPass(Setting::get('settings.daisycon_pw'))
                    ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
                    ->send()
                ;        
            }

            return $this->client;
        } catch (SoapFault $fault) {
            echo $fault->faultstring;
        }
    }

    public function getCampaigns()
    {
        $programs = array();

        foreach ($this->client as $client) {
            $campaigns = json_decode($client->body);

            if (count($campaigns) >= 1) {
                foreach ($campaigns as $campaign) {
                    $commission = $this->getCommission($campaign->id);
                    
                    if (
                        $campaign->cashback == 'true'
                        OR $campaign->cashback == 'partial'
                        && $commission != null
                        && !in_array($campaign->name, array_flatten($this->lastAffiliates)) 
                        && $this->affiliateHelper->domainRestriction($campaign->name) == 1
                        && $this->affiliateHelper->domainRestriction($campaign->url) == 1
                    ) {
                        if ($campaign->status == 'active') {
                            if (!in_array($campaign->id, array_flatten($this->affiliates))) {
                                $this->lastId++;
                               
                                $programs[$campaign->id] = array(
                                    'affiliateId' => $this->lastId,
                                    'affiliateCreated' => date('now'),
                                    'programId' => $campaign->id,
                                    'programUrl' => $campaign->url,
                                    'programSlug' => str_slug($campaign->name),
                                    'programName' => $campaign->name,
                                    'programTrackingLink' => $campaign->url,
                                    'programTrackingDuration' => $campaign->tracking_duration.' dagen',
                                    'programNetwork' => $this->affiliate_network,
                                    'programCategory' => $campaign->primary_category_id,
                                    'programCategories' => $campaign->category_ids,
                                    'programCommissions' => $commission,
                                    'programImage' => $campaign->logo
                                );

                                try {
                                    ImageManagerStatic::make(
                                        'http:'.$campaign->logo
                                    )
                                        ->save(public_path('images/affiliates/'.$this->affiliate_network).'/'. $campaign->id.'.jpg');
                                } catch (NotReadableException $e) {
                                    echo $e;
                                }
                            }
                        }
                    }
                }
            }
        }

        $categories = $this->generateCategories();

        return array_unique($programs, SORT_REGULAR);
    }

    public function getCampaignsToUpdate()
    {
        $programs = array();

        foreach ($this->client as $client) {
            $campaigns = json_decode($client->body);

            if (count($campaigns) >= 1) {
                foreach ($campaigns as $campaign) {
                    $commission = $this->getCommission($campaign->id);

                    if (
                        $campaign->cashback == 'true'
                        OR $campaign->cashback == 'partial'
                        && $commission != null
                        && $this->affiliateHelper->domainRestriction($campaign->name) == 1
                        && $this->affiliateHelper->domainRestriction($campaign->url) == 1
                    ) {
                        if ($campaign->status == 'active') {
                            if (in_array($campaign->id, array_flatten($this->affiliates))) {
                                $this->lastId++;
                               
                                $programs[$campaign->id] = array(
                                    'affiliateCreated' => date('now'),
                                    'programId' => $campaign->id,
                                    'programUrl' => $campaign->url,
                                    'programSlug' => str_slug($campaign->name),
                                    'programName' => $campaign->name,
                                    'programCommissions' => $commission
                                );
                            }
                        }
                    }
                }
            }
        }

        return array_unique($programs, SORT_REGULAR);
    }

    public function generateCategories() 
    {
        $this->programCategories = json_decode($this->feedCategories->body);

        if (count($this->programCategories) >= 1) {
            foreach ($this->programCategories as $feedCategory) {
                foreach ($this->programCategories as $feedCategory) {
                    if (Lang::has('daisycon.'.str_slug($feedCategory->name))) {
                        $this->parentsArray[$feedCategory->id] =  array(
                            'id' => $feedCategory->id,
                            'name' => Lang::get('daisycon.'.str_slug($feedCategory->name)),
                            'slug' => str_slug(Lang::get('daisycon.'.str_slug($feedCategory->name)))
                        );
                    }
                }
            }
        }
        
        $flattenCategories = array_flatten($this->categories);

        if (isset($this->parentsArray)) {
            // - Parents -
            foreach ($this->parentsArray as $parentResult) {
                // Check if category name constains name of categories
                $duplicatedCategories = $this->affiliateHelper->categoryDuplicates(
                    $parentResult['name'], 
                    $flattenCategories
                );
            
                // This category isn't in the database
                if (!in_array($parentResult['name'], $flattenCategories) && count($duplicatedCategories) == 0) {
                    $createCategory = Category::newItem($parentResult['name']);
                    $this->temporaryParents[$createCategory->slug] = $createCategory->id;
                }

                // This category is in the database
                foreach($this->categories as $parent) {
                    $this->temporaryParents[$parent['slug']] = $parent['id'];
                }

                // This category contains words of a category that already exists
                if (!in_array($parentResult['name'], $flattenCategories)  && count($duplicatedCategories) >= 1) {
                    foreach ($duplicatedCategories as $duplicatedCategoriesKey => $duplicatedCategoriesName) {
                        foreach ($duplicatedCategoriesName as $duplicatedCategoryKey => $duplicatedCategoryName) {
                            if(isset($this->temporaryParents[str_slug($duplicatedCategoryName)])) {
                                $this->temporaryParents[str_slug($duplicatedCategoriesKey)][] = $this->temporaryParents[str_slug($duplicatedCategoryName)];
                            }
                        }
                    }
                }
            }
        }
    }

    public function getCommission($campaignId)
    {
        foreach ($this->feedCommissions as $feed) {
            $commissionsFeed = json_decode($feed->body);
        
            if (count($commissionsFeed) > 0) {
                foreach ($commissionsFeed as $commission) {
                    foreach ($commission->compensations as $key => $compensations) {
                        if ($commission->program_id == $campaignId) {
                            if ($compensations->amount > 0) {
                                $commissions[] = array(
                                    'name' => $compensations->name,
                                    'unit' => '&euro;',
                                    'value' => $compensations->amount
                                );
                            }

                            if ($compensations->percentage > 0) {
                                $commissions[] = array(
                                    'name' => $compensations->name,
                                    'unit' => '%',
                                    'value' => $compensations->percentage
                                );
                            }
                        }
                    }
                }
            }
        }

        return isset($commissions) ? json_encode($commissions): null;
    }

    public function addCampaigns()
    {
        $campaigns = $this->getCampaigns();  // Get all accepted programs

        foreach ($campaigns as $campaign) {
            $insertAffiliate[$campaign['programName']] = array(
                'id' => $campaign['affiliateId'],
                'created_at' => $campaign['affiliateCreated'],
                'program_id' => $campaign['programId'],
                'slug' => $campaign['programSlug'],
                'name' => $campaign['programName'],
                'link' => $campaign['programUrl'],
                'tracking_link' => $campaign['programTrackingLink'],
                'tracking_duration' => $campaign['programTrackingDuration'],
                'affiliate_network' => $campaign['programNetwork'],
                'compensations' => $campaign['programCommissions']
            );

            # Connect categories and subcategories with eachother
            if (isset($campaign['programCategory']) && $campaign['programCategory'] >= 1) {
                if (isset($this->parentsArray[$campaign['programCategory']])) {
                    $subcategorySlug = str_slug($this->parentsArray[$campaign['programCategory']]['name']);
                                        
                    // Don't add a new category if the primary category_id is the same as a subcategory id
                    if (isset($this->temporaryParents[$subcategorySlug])) {
                        $insertAffiliateCategory[] = array(
                            'affiliate_id' => $campaign['affiliateId'],
                            'category_id' => $this->temporaryParents[$subcategorySlug]
                        );
                    }
                }
            }

            if (isset($campaign['programCategories'])) {
                foreach ($campaign['programCategories'] as $categoryId) {
                    if (isset($this->parentsArray[$categoryId])) {
                        $subcategorySlug = str_slug($this->parentsArray[$categoryId]['name']);
                                
                        // Don't add a new category if the primary category_id is the same as a subcategory id
                        if (isset($this->temporaryParents[$subcategorySlug])) {
                            $insertAffiliateCategory[] = array(
                                'affiliate_id' => $campaign['affiliateId'],
                                'category_id' => $this->temporaryParents[$subcategorySlug]
                            );
                        }
                    }
                }
            }
        }

        // Add affiliate in database
        if (isset($insertAffiliate)) {
            Affiliate::insert($insertAffiliate);
        }

        // Add affiliate connections in database
        if (isset($insertAffiliateCategory)) {
            AffiliateCategory::insert($insertAffiliateCategory);
        }
    }

    public function updateCampaigns()
    {
        $campaigns = $this->getCampaignsToUpdate();

        foreach ($campaigns as $key => $campaign) {
            if (in_array($campaign['programId'], array_flatten($this->affiliates))) {
                if (is_array(json_decode($this->getCommission($campaign['programId'])))) {
                    foreach (json_decode($this->getCommission($campaign['programId'])) as $key => $commission) {
                        $commissionArray[$campaign['programId']][$key.'-'.str_slug($commission->name).'-'.$commission->value] = array(
                            'name' => $commission->name,
                            'unit' => $commission->unit,
                            'value' => $commission->value
                        );
                    }
                }
            }
        }
        
        if (isset($commissionArray)) {
            $affiliates = Affiliate::whereIn('program_id', array_keys($commissionArray))
                ->where('affiliate_network', 'daisycon')
                ->get()
            ;

            foreach ($affiliates as $key => $affiliate) {
                if (is_array(json_decode($affiliate->compensations))) {
                    foreach (json_decode($affiliate->compensations) as $key => $commission) {
                        $affiliateCommissionArray[$affiliate->program_id][$key.'-'.str_slug($commission->name).'-'.$commission->value] = array(
                            'name' => $commission->name,
                            'unit' => (isset($commissionArray[$affiliate->program_id][$key.'-'.str_slug($commission->name).'-'.$commission->value]) ? $commissionArray[$affiliate->program_id][$key.'-'.str_slug($commission->name).'-'.$commission->value]['unit'] : $commission->unit),
                            'value' => $commission->value
                        );

                        $this->line('Updating affliate #'.$affiliate->program_id.' - '.$affiliate->name);
                    }

                    foreach ($commissionArray as $programId => $commissions) {
                        foreach ($commissions as $commisionKey => $commission) {
                            $newaffiliateCommissionArray[$programId][$commisionKey] = array(
                                'name' => $commission['name'],
                                'unit' => (isset($commissionArray[$affiliate->program_id][$commisionKey.'-'.str_slug($commission['name']).'-'.$commission['value']]) ? $commissionArray[$affiliate->program_id][$commisionKey.'-'.str_slug($commission['name']).'-'.$commission['value']]['unit'] : $commission['unit']),
                                'value' => $commission['value']
                            );
                        }
                    }
                }

                if (isset($newaffiliateCommissionArray[$affiliate->program_id])) {
                    $affiliate->compensations = json_encode(array_values($newaffiliateCommissionArray[$affiliate->program_id]));
                    $affiliate->save();
                }
            }
        }
    }

    public function removeCampaigns()
    {
        $curl = new cURL;

        for ($i = 1; $i < 5; $i++) { 
            $inactives = $curl
                ->newRequest('GET', 'https://services.daisycon.com/publishers/370506/media/246340/subscriptions?page='.$i.'&per_page=1000')
                ->setUser(getenv('DAISYCON_EMAIL'))
                ->setPass(getenv('DAISYCON_PASS'))
                ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
                ->send()
            ;

             if (trim($inactives->body) != '') {
                foreach (json_decode($inactives) as $inactive) {
                    if ($inactive->status == 'approved') {
                        foreach ($inactive->program_ids as $programId) {
                            $approved[] = $programId;
                        }
                    }
                }
            }
        }
   
        if (isset($approved)) {
            $affiliates = Affiliate::whereNotIn('program_id', $approved)
                ->where('affiliate_network', $this->affiliate_network)
                ->get()
            ;

            foreach ($affiliates as $affiliate) {
                $affiliateIds[] = $affiliate->id;

                $this->line('Remove affliate #'.$affiliate->program_id.' - '.$affiliate->name);
            }

            if (isset($affiliateIds)) {
                $affiliates = Affiliate::whereIn('id', $affiliateIds)->where('affiliate_network', $this->affiliate_network)->delete();
                $affiliates = AffiliateCategory::whereIn('affiliate_id', $affiliateIds)->delete();
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
        $commandName = 'daisycon_affiliate';

        if (Setting::get('cronjobs.'.$commandName) == NULL) {
            echo 'This command is not working right now. Please activate this command.';
        } else {
            $getClient = $this->checkConnection();

            try {
                if ($getClient) {
                    if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
                        // Start cronjob
                        $this->line(' Start '.$this->signature);
                        Setting::set('cronjobs.active.'.$commandName, 1);
                        Setting::save();

                        // Processing
                        $this->updateCampaigns();  // Update Campaigns
                        $this->removeCampaigns(); // Remove Campaigns
                        $this->addCampaigns(); // Add Campaigns

                        // End cronjob
                        $this->line('Finished '.$this->signature);
                        Setting::set('cronjobs.active.'.$commandName, 0);
                        Setting::save();
                    } else {
                        // Don't run a task mutiple times, when the first task hasnt been finished
                        $this->line('This task is busy at the moment.');
                    }
                } else {
                    $this->line('This task is not available, because there is no connection.');
                }
            } catch (Exception $e) {
                $this->line('Er is een fout opgetreden. '.$this->signature);
               
                Mail::raw('Er is een fout opgetreden:<br /><br /> '.$e, function ($message) {
                    $message->to(getenv('DEVELOPER_EMAIL'))->subject('Fout opgetreden: '.$this->signature);
                });
            }
        }
    }

}