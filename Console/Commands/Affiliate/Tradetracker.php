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

class Tradetracker extends Command
{

    /**
     * @var string
     */
    protected $signature = 'tradetracker:affiliate';

    /**
     * @var string
     */
    protected $description = 'Import tradetracker feed to database';

    /**
     * @var string
     */
    protected $affiliate_network = 'tradetracker';

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

        $this->lastId = count($this->lastAffiliates) >= 1 ? $this->lastAffiliates->last()->id : 0;
    }

    public function checkConnection() 
    {
        $options =  array(
            'compression' => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP,
            'stream_context'=> stream_context_create(
                array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false, 
                        'allow_self_signed' => true 
                     )
                )
            )
        );

        try {
            ini_set('soap.wsdl_cache_enabled', 0);
            set_time_limit(0);

            $this->client = new SoapClient('http://ws.tradetracker.com/soap/affiliate?wsdl', $options);
            $this->client->authenticate(
                Setting::get('settings.tradetracker_name'), 
                Setting::get('settings.tradetracker_pw')
            );
            
            return $this->client;
        } catch (SoapFault $fault) {
            echo $fault->faultstring;
        }
    }

    public function getCampaigns()
    {
        $campaigns = $this->checkConnection()->getCampaigns(
            232507, 
            array(
                'assignmentStatus' => 'accepted'
            )
        );

        $programs = array();

        foreach ($campaigns as $campaign) {    
            if (
                isset($campaign->info->policyCashbackStatus, $campaign->info->deeplinkingSupported) 
                && $campaign->info->deeplinkingSupported === TRUE
                && !in_array($campaign->name, array_flatten($this->lastAffiliates)) 
                && !in_array($campaign->ID, array_flatten($this->affiliates)) 
                && $this->affiliateHelper->domainRestriction($campaign->URL) == 1
            ) {

                $this->lastId++;

                $trackingDuration = $this->getTrackingDays($campaign);
               
                $programs[] = array(
                    'affiliateId' => $this->lastId,
                    'affiliateCreated' => date('now'),
                    'programId' => $campaign->ID,
                    'programUrl' => $campaign->URL,
                    'programSlug' => str_slug($campaign->name),
                    'programName' => $campaign->name,
                    'programTrackingLink' => $campaign->info->trackingURL,
                    'programTrackingDuration' => $trackingDuration,
                    'programNetwork' => $this->affiliate_network,
                    'programInfo' => $campaign->info,
                    'programCommissions' => $this->getCommission($campaign->ID),
                    'programImage' => $campaign->info->imageURL
                );
            }
        }

        $categories = $this->generateCategories();

        return $programs;
    }

    public function getTrackingDays($campaign)
    {
        $saleCommissionVariable = $campaign->info->commission->saleCommissionVariable;

        $trackingDuration = '0 dagen';

        if (isset($saleCommissionVariable) && $saleCommissionVariable > 0) {
            if (isset($campaign->info->commission->saleMaximumAssessmentInterval)) {
                preg_match('/([0-9]{1,3})D/', $campaign->info->commission->saleMaximumAssessmentInterval, $matches);
                                  
                if (isset($matches[0])) {
                    $trackingDuration = str_replace('D', '', $matches[0]).' dagen';
                }

                preg_match('/([0-9]{1,3})W/', $campaign->info->commission->saleMaximumAssessmentInterval, $matches);
                                    
                if (isset($matches[0])) {
                    $trackingDuration = (intval(str_replace('W', '', $matches[0])) * 7).' dagen';
                }

                preg_match( '/([0-9]{1,3})M/', $campaign->info->commission->saleMaximumAssessmentInterval, $matches);
                                    
                if (isset($matches[0])) {
                    $trackingDuration = (intval(str_replace('W', '', $matches[0])) * 30.5).' dagen';
                }
            }
        }
        
        if (isset($campaign->info->commission->leadCommission) && $campaign->info->commission->leadCommission > 0 ) {
            if (isset($campaign->info->leadMaximumAssessmentInterval)) {
                preg_match('/([0-9]{1,3})D/', $campaign->info->leadMaximumAssessmentInterval, $matches);
                                
                if (isset($matches[0])) {
                    $trackingDuration = str_replace('D', '', $matches[0]) .' dagen';
                }

                preg_match('/([0-9]{1,3})W/', $campaign->info->leadMaximumAssessmentInterval, $matches);
                                
                if (isset($matches[0])) {
                    $trackingDuration = (intval(str_replace('W', '', $matches[0])) * 7).' dagen';
                }

                preg_match('/([0-9]{1,3})M/', $campaign->info->leadMaximumAssessmentInterval, $matches);
                                
                if (isset($matches[0])) {
                    $trackingDuration = (intval(str_replace('W', '', $matches[0])) * 30).' dagen';
                }
            }
        }

        return $trackingDuration;
    }

    public function generateCategories() 
    {
        foreach ($this->client->getCampaignCategories() as $category) {
            $this->parentsArray[$category->ID] = array(
                'id' =>  $category->ID,
                'name' => $category->name,
                'slug' => str_slug($category->name)
            );

            if (!empty($category->categories)) {
                foreach ($category->categories as $subCategory) {
                    $this->parentsChilds[$category->ID][$subCategory->name] = array(
                        'categoryId' => $category->ID,
                        'name' => $subCategory->name,
                        'slug' => str_slug($subCategory->name)
                    );
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

            // - Children -
            foreach ($this->parentsChilds as $parentSlug => $parrentChildsItems) {  
                $parentKey = str_slug($this->parentsArray[$parentSlug]['name']);
                $temporaryParentKey = $this->temporaryParents[$parentKey];

                $subcategoriesQuery = Category::select('id', 'name');

                if (is_array($temporaryParentKey)) {
                    $subcategoriesQuery = $subcategoriesQuery->whereIn('subcategory_id', $temporaryParentKey);
                } else {
                    $subcategoriesQuery = $subcategoriesQuery->where('subcategory_id', $temporaryParentKey);
                }

                $subcategoriesQuery = $subcategoriesQuery->get()->toArray();

                foreach($parrentChildsItems as $itemSlug => $item) {
                    // This subcategory isn't in the database
                    if(!in_array($itemSlug, array_flatten($subcategoriesQuery))) {
                        if (is_array($temporaryParentKey)) {
                            foreach ($temporaryParentKey as $temporaryParentKeyId) {
                                $createSubCategory = Category::newItem($itemSlug, $temporaryParentKeyId);
                                $this->temporaryChilds[$itemSlug] = array(
                                    'id' => $createSubCategory->id,
                                    'name' => $itemSlug
                                );
                            }
                        } else {
                            $createSubCategory = Category::newItem($itemSlug, $temporaryParentKey);
                            $this->temporaryChilds[$itemSlug] = array(
                                'id' => $createSubCategory->id,
                                'name' => $itemSlug
                            );
                        }
                    }

                    // This subcategory is in the database
                    foreach ($subcategoriesQuery as $subcategoriesFetch) {
                        if (is_array($temporaryParentKey)) {
                            foreach ($temporaryParentKey as $temporaryParentKeyId) {
                                $this->temporaryChilds[$subcategoriesFetch['name']] = array(
                                    'id' => $subcategoriesFetch['id'],
                                    'name' => $subcategoriesFetch['name']
                                );
                            }
                        } else {
                            $this->temporaryChilds[$subcategoriesFetch['name']] = array(
                                'id' => $subcategoriesFetch['id'],
                                'name' => $subcategoriesFetch['name']
                            );
                        }
                    }
                }   
            }
        }
    }

    public function getCommission($campaignId)
    {
        $campaignCommision = $this->client->getCampaignCommissionExtended(
            232507, 
            $campaignId
        );

        foreach ($campaignCommision->products as $key => $commision) {
            if ($commision->saleCommissionFixed > 0) {
                $commissions[] = array(
                    'name' => $commision->campaignProduct->name,
                    'unit' => '&euro;',
                    'value' => $commision->saleCommissionFixed
                );
            }                    

            if ($commision->leadCommission > 0) {
                $commissions[] = array(
                    'name' => $commision->campaignProduct->name,
                    'unit' => '&euro;',
                    'value' => $commision->leadCommission
                 );
            }

            if ($commision->saleCommissionVariable > 0) {
                $commissions[] = array(
                    'name' => $commision->campaignProduct->name,
                    'unit' => '%',
                    'value' => $commision->saleCommissionVariable
                );
            }
        }

        return isset($commissions) ? json_encode($commissions) : '';
    }

    public function updateCampaigns()
    {
        $campaigns = $this->checkConnection()->getCampaigns(
            232507, 
            array(
                'assignmentStatus' => 'accepted'
            )
        );

        foreach ($campaigns as $key => $campaign) {
            if (in_array($campaign->ID, array_flatten($this->affiliates))) {
                if (is_array(json_decode($this->getCommission($campaign->ID)))) {
                    foreach (json_decode($this->getCommission($campaign->ID)) as $key => $commission) {
                        $commissionArray[$campaign->ID][$key.'-'.str_slug($commission->name).'-'.$commission->value] = array(
                            'name' => $commission->name,
                            'unit' => $commission->unit,
                            'value' => $commission->value
                        );
                    }
                }
            }
        }

        $affiliates = Affiliate::whereIn('program_id', array_keys($commissionArray))
            ->where('affiliate_network', 'tradetracker')
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
                        $affiliateCommissionArray[$programId][$commisionKey] = array(
                            'name' => $commission['name'],
                            'unit' => (isset($commissionArray[$affiliate->program_id][$commisionKey.'-'.str_slug($commission['name']).'-'.$commission['value']]) ? $commissionArray[$affiliate->program_id][$commisionKey.'-'.str_slug($commission['name']).'-'.$commission['value']]['unit'] : $commission['unit']),
                            'value' => $commission['value']
                        );
                    }
                }
            }

            if (isset($affiliateCommissionArray[$affiliate->program_id])) {
                $affiliate->compensations = json_encode(array_values($affiliateCommissionArray[$affiliate->program_id]));
                $affiliate->save();
            }
        }
    }

    public function removeCampaigns()
    {
        $existedAffiliates = Affiliate::select(
            'program_id'
        )
            ->where('affiliate_network', $this->affiliate_network)
            ->get()
        ; 

        foreach ($existedAffiliates as $existedAffiliate) {
            $programsArray[] = $existedAffiliate->program_id;
        }

        $activePrograms = $this->checkConnection()->getCampaigns(
            232507, 
            array(
                'assignmentStatus' => 'accepted'
            )
        );

        foreach ($activePrograms as $key => $campaign) {
            if (in_array($campaign->ID, $programsArray)) {
                $activeCampaigns[] = $campaign->ID;
            }
        }
       
        if (isset($activeCampaigns)) {
            $affiliates = Affiliate::whereNotIn('program_id', $activeCampaigns)
                ->where('affiliate_network', $this->affiliate_network)
                ->update(array(
                    'no_show' => 1
                ))
            ;
        }
    }

    public function addCampaigns()
    {
        $campaigns = $this->getCampaigns();  // Get all accepted programs
                
        foreach ($campaigns as $key => $campaign) {
            $insertAffiliate[] = array(
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

            $this->line('- Add new affiliate '.$campaign['programName']);

            # Connect categories and subcategories with eachother
            $categoryId = $campaign['programInfo']->category->ID;

            if (isset($this->parentsArray[$categoryId])) {
                $connectParentKey = $this->temporaryParents[$this->parentsArray[$categoryId]['slug']];

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

            if (isset($campaign['programInfo']->subCategories)) {
                foreach ($campaign['programInfo']->subCategories as $subcat) { 
                    if (isset($this->temporaryChilds[$subcat->name])) {
                        $insertAffiliateCategory[] = array(
                            'affiliate_id' => $campaign['affiliateId'],
                            'category_id' => $this->temporaryChilds[$subcat->name]['id']
                        );
                    }
                }
             }

            // Add an image
            try {
                ImageManagerStatic::make($campaign['programImage'])->save(public_path('images/affiliates/'.$campaign['programNetwork']).'/'. $campaign['programId'].'.jpg');
            } catch (NotReadableException $e) {
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

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'tradetracker_affiliate';

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