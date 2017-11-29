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
use Lang;
use Zanox\ApiClient;
use Zanox\Api\Constants;
use Setting;

class Zanox extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zanox:affiliate';

    /**
     * @var string
     */
    protected $affiliate_network = 'zanox';


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
                
        $this->affiliatesExists = Affiliate::where('affiliate_network', $this->affiliate_network)
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

        $this->flattenCategories = array_flatten($this->categories);
        $this->lastId = count($this->lastAffiliates) >= 1 ? $this->lastAffiliates->last()->id : 0;
    }

    public function checkConnection() 
    {
        try {
            ini_set('soap.wsdl_cache_enabled', 0);
            set_time_limit(0);

            $this->client = ApiClient::factory(array('xml'), '2011-03-01');
            $this->client->setConnectId(Setting::get('settings.zanox_name'));
            $this->client->setSecretKey(Setting::get('settings.zanox_pw'));

            return $this->client;
        } catch (SoapFault $fault) {
            echo $fault->faultstring;
        }
    }

    public function getCampaigns($number)
    {
        $campaigns = $this->checkConnection()->getProgramApplications($this->adspaceId, null, 'confirmed', $number, 50);

        if (isset($campaigns->programApplicationItems->programApplicationItem)) {
            $campaigns = $campaigns->programApplicationItems->programApplicationItem;
            
            $programs = array();
            foreach ($campaigns as $campaign) {    
                $getProgram = $this->client->getProgram($campaign->program->id);
                
                if (
                    isset($getProgram->programItem)
                    && !in_array($getProgram->programItem->name, array_flatten($this->lastAffiliates)) 
                    && !in_array($campaign->program->id, array_flatten($this->affiliates)) 
                    && isset($getProgram->programItem)
                    && $this->affiliateHelper->domainRestriction($getProgram->programItem->url) == 1
                ) {
                    $this->lastId++;

                    $getTrackingLink = $this->client->getAdmedia(
                        $campaign->program->id, 
                        'NL', 
                        null, 
                        null, 
                        'startPage', 
                        'text', 
                        null, 
                        $this->adspaceId
                    );

                    foreach ($getTrackingLink->admediumItems as $key => $admediumItems) {
                        foreach ($admediumItems as $key => $admediumItem) {
                            $trackingURL = $admediumItem->trackingLinks->trackingLink[0]->ppc;
                        }
                    }

                    $programs[] = array(
                        'affiliateId' => $this->lastId,
                        'affiliateCreated' => date('now'),
                        'programId' => $campaign->program->id,
                        'programUrl' => $getProgram->programItem->url,
                        'programSlug' => str_slug($getProgram->programItem->name),
                        'programName' => $getProgram->programItem->name,
                        'programTrackingLink' => $trackingURL,
                        'programTrackingLink' => $trackingURL,
                        'programNetwork' => $this->affiliate_network,
                        'programCommissions' => $this->getCommission($campaign->program->id),
                        'programTrackingDuration' => '0 dagen',
                        'programInfo' => $getProgram->programItem->categories,
                        'programImage' => $getProgram->programItem->image
                    );
                }
            }
        } else {
            $programs = null;
        }

        return $programs;  
    }

    public function generateCategories() 
    {
        $this->programCategories = $this->client->getProgramCategories(); 

        foreach ($this->programCategories as $feedCategory) {
            foreach ($feedCategory as $listCategories) {
                foreach ($listCategories as $listCategory) {
                    $this->parentsArray[$listCategory->id] = array(
                        'id' =>  $listCategory->id,
                        'name' => Lang::get('zanox.'.str_slug($listCategory->_)),
                        'slug' => str_slug(Lang::get('zanox.'.str_slug($listCategory->_)))
                    );
                }
            }
        }

        if (isset($this->parentsArray)) {
            // - Parents -
            foreach ($this->parentsArray as $parentResult) {
                // Check if category name constains name of categories
                $duplicatedCategories = $this->affiliateHelper->categoryDuplicates(
                    $parentResult['name'], 
                    $this->flattenCategories
                );
            
                // This category isn't in the database
                if (!in_array($parentResult['name'], $this->flattenCategories) && count($duplicatedCategories) == 0) {
                    $createCategory = Category::newItem($parentResult['name']);
                    $this->temporaryParents[$createCategory->slug] = $createCategory->id;
                }

                // This category is in the database
                foreach($this->categories as $parent) {
                    $this->temporaryParents[$parent['slug']] = $parent['id'];
                }

                // This category contains words of a category that already exists
                if (!in_array($parentResult['name'], $this->flattenCategories) && count($duplicatedCategories) >= 1) {
                    foreach ($duplicatedCategories as $duplicatedCategoriesKey => $duplicatedCategoriesName) {
                        foreach ($duplicatedCategoriesName as $duplicatedCategoryKey => $duplicatedCategoryName) {
                            if(isset($this->temporaryParents[str_slug($duplicatedCategoryName)])) {
                                $this->temporaryParents[str_slug($duplicatedCategoriesKey)] = $this->temporaryParents[str_slug($duplicatedCategoryName)];
                            }
                        }
                    }
                }
            }
        }
    }

    public function getCommission($campaignId)
    {
        $getTrackingCategories = $this->client->getTrackingCategories($this->adspaceId, $campaignId);

        foreach ($getTrackingCategories->trackingCategoryItem as $key => $trackings) {
            foreach ($trackings as $key => $tracking) {
                if(isset($tracking->saleFixed) && $tracking->saleFixed > 0) {
                    $commissions[] = array(
                        'name' => $tracking->description == 'default' ? 'Algemeen' : $tracking->description,
                        'unit' => '&euro;',
                        'value' => $tracking->saleFixed
                    );
                }

                if(isset($tracking->leadFixed) && $tracking->leadFixed > 0) {
                    $commissions[] = array(
                        'name' => $tracking->description == 'default' ? 'Algemeen' : $tracking->description,
                        'unit' => '&euro;',
                        'value' => $tracking->leadFixed
                    );
                }

                if(isset($tracking->salePercent) && $tracking->salePercent > 0) {
                    $commissions[] = array(
                        'name' => $tracking->description == 'default' ? 'Algemeen' : $tracking->description,
                        'unit' => '%',
                        'value' => $tracking->salePercent
                    );
                }
            }
        }

        return isset($commissions) ? json_encode(array_unique($commissions, SORT_REGULAR)): '';
    }

    public function addCampaigns()
    {
        $categories = $this->generateCategories();
        
        for ($i = 0; $i < 10; $i++) { 
            $campaigns = $this->getCampaigns($i);  // Get all accepted programs
                        
            if ($campaigns != null) {
                foreach ($campaigns as $key => $campaign) {
                    $insertAffiliate[] = array(
                            'id' => $campaign['affiliateId'],
                            'created_at' => $campaign['affiliateCreated'],
                            'program_id' => $campaign['programId'],
                            'slug' => $campaign['programSlug'],
                            'name' => $campaign['programName'],
                            'link' => $campaign['programUrl'],
                            'tracking_link' => $campaign['programTrackingLink'],
                            'affiliate_network' => $campaign['programNetwork'],
                            'tracking_duration' => $campaign['programTrackingDuration'],
                            'compensations' => $campaign['programCommissions']
                    );

                    # Connect categories and subcategories with eachother
                    foreach ($campaign['programInfo'] as $programCategoryIds) {
                        foreach ($programCategoryIds as $key => $category) {
                            if (isset($this->parentsArray[$category->id])) {
                                $connectParentKey = $this->temporaryParents[$this->parentsArray[$category->id]['slug']];

                                if (is_array($connectParentKey)) {
                                    foreach ($connectParentKey as $connectParentId) {
                                        $insertAffiliateCategory[] = array(
                                            'affiliate_id' => $campaign['affiliateId'],
                                            'category_id' => $connectParentId
                                        );
                                    }
                                } else {
                                    $insertAffiliateCategory[] = array(
                                        'affiliate_id' => $campaign['affiliateId'],
                                        'category_id' => $connectParentKey
                                    );
                                }
                            }   
                        }
                    }

                    // Add an image
                    try {
                        ImageManagerStatic::make($campaign['programImage'])
                            ->save(public_path('images/affiliates/'.$campaign['programNetwork']).'/'. $campaign['programId'].'.jpg');
                    } catch (NotReadableException $e) {
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
        foreach ($this->affiliatesExists as $campaign) {  
            $programs[] = array(
                'programId' => $campaign->program_id,
                'programCommissions' => $campaign->compensations
            );
        }

        if (isset($programs)) {
            foreach ($programs as $key => $campaign) {
                if ($campaign['programId'] != NULL) {
                    if (is_array(json_decode($this->getCommission($campaign['programId'])))) {
                        foreach (json_decode($this->getCommission($campaign['programId'])) as $key => $commission) {
                            $commissionArray[$campaign['programId']][$key.'-'.str_slug($commission->name).'-'.$commission->value] = array(
                                'name' => $commission->name,
                                'unit' => $commission->unit,
                                'value' => $commission->value
                            );
                        }
                    }

                     $this->line($campaign['programId']);
                }
            }


             if (isset($commissionArray)) {
                $affiliates = Affiliate::whereIn('program_id', array_keys($commissionArray))
                    ->where('affiliate_network', 'zanox')
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
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'zanox_affiliate';

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