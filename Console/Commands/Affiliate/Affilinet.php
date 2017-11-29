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

class Affilinet extends Command
{

    /**
     * @var string
     */
    protected $signature = 'affilinet:affiliate';

    /**
     * @var string
     */
    protected $description = 'Import affilinet feed to database';

    /**
     * @var string
     */
    protected $affiliate_network = 'affilinet';

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

            $this->login = new \SoapClient('https://api.affili.net/V2.0/Logon.svc?wsdl', $options);
            $this->token = $this->login->Logon(array(
                'Username' => Setting::get('settings.affilinet_name'),
                'Password' => Setting::get('settings.affilinet_pw'),
                'WebServiceType' => 'Publisher'
            ));

            $this->client = new \SoapClient('https://api.affili.net/V2.0/PublisherProgram.svc?wsdl', $options);

            return $this->client;
        } catch (SoapFault $fault) {
            echo $fault->faultstring;
        }
    }

    public function getCampaigns()
    {
        $campaigns = $this->checkConnection()->GetPrograms(array(
            'CredentialToken' => $this->token,
            'DisplaySettings' => array(
                'PageSize' => 100,
                'CurrentPage' => 1
            ),
            'GetProgramsQuery' => array(
                'PartnershipStatus' => array('Active')
            )
        ));

        $campaigns = $campaigns->ProgramCollection->Program;

        $programs = array();

        foreach ($campaigns as $campaign) {    
            if (
                !in_array($campaign->ProgramTitle, array_flatten($this->lastAffiliates)) 
                && !in_array($campaign->ProgramId, array_flatten($this->affiliates)) 
                && $this->affiliateHelper->domainRestriction($campaign->ProgramURL) == 1
            ) {
                $this->lastId++;
               
                $programs[] = array(
                    'affiliateId' => $this->lastId,
                    'affiliateCreated' => date('now'),
                    'programId' => $campaign->ProgramId,
                    'programUrl' => $campaign->ProgramURL,
                    'programSlug' => str_slug($campaign->ProgramTitle),
                    'programName' => $campaign->ProgramTitle,
                    'programTrackingLink' => 'http://zijn.samenresultaat.nl/click.asp?ref=#USERID&site='.$campaign->ProgramId.'&type=text',
                    'programTrackingDuration' => $campaign->CookieLifetime.' dagen',
                    'programNetwork' => $this->affiliate_network,
                    'programCommissions' => $this->getCommission($campaign->ProgramId),
                    'programInfo' => $campaign->ProgramCategoryIds->int,
                    'programImage' => $campaign->LogoURL
                );
            }
        }

        $categories = $this->generateCategories();

        return $programs;
    }

    public function addCampaigns()
    {
        $campaigns = $this->getCampaigns(); // Get all accepted programs
                
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

            // Connect categories and subcategories with eachother
            foreach ($campaign['programInfo'] as $programCategoryIds) {
                if (isset($this->parentsArray[$programCategoryIds])) {
                    $connectParentKey = $this->temporaryParents[$this->parentsArray[$programCategoryIds]['slug']];

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

            foreach ($campaign['programInfo'] as $programCategoryIds) {
                if (isset($this->temporaryChilds[$programCategoryIds])) {
                    $connectChildId = $this->temporaryChilds[$programCategoryIds]['id'];

                    $insertAffiliateCategory[] = array(
                        'affiliate_id' => $campaign['affiliateId'],
                        'category_id' => $connectChildId
                    );
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

    public function updateCampaigns()
    {
        $campaigns = $this->checkConnection()->GetPrograms(array(
            'CredentialToken' => $this->token,
            'DisplaySettings' => array(
                'PageSize' => 100,
                'CurrentPage' => 1
            ),
            'GetProgramsQuery' => array(
                'PartnershipStatus' => array('Active')
            )
        ));

        $campaigns = $campaigns->ProgramCollection->Program;
        $programs = array();

        foreach ($campaigns as $campaign) {
            if (in_array($campaign->ProgramId, array_flatten($this->affiliates))) {
                $this->lastId++;
               
                $programUpdate = Affiliate::where('program_id', $campaign->ProgramId)
                    ->where('affiliate_network', $this->affiliate_network)
                    ->first()
                ;

                $programUpdate->tracking_link = 'http://zijn.samenresultaat.nl/click.asp?ref=#SITEID#&site='.$campaign->ProgramId.'&type=b1&bnb=1&subid=#SUB_ID#';
                $programUpdate->save();

                $this->line('Updating program id #'.$campaign->ProgramId);
            }
        }

        return $programs;
    }

    public function generateCategories() 
    {
        $this->programCategories = $this->client->GetProgramCategories($this->token);

        foreach ($this->programCategories->RootCategories->ProgramCategory as $category) {
            $this->parentsArray[$category->CategoryId] = array(
                'id' =>  $category->CategoryId,
                'name' => $category->Name,
                'slug' => str_slug($category->Name)
            );

            $programSubCategories = json_decode(json_encode($category->SubCategories), true);

            if (isset($programSubCategories['ProgramCategory'])) {
                $i = 0;
                foreach ($programSubCategories as $key => $subCategory) {
                    $i++;

                    if($this->affiliateHelper->arrayAssoc($subCategory)) {
                        $this->parentsChilds[$subCategory['CategoryId']] = array(
                            'parentCategoryId' => $subCategory['ParentCategoryId'],
                            'categoryId' => $subCategory['CategoryId'],
                            'name' => $subCategory['Name'],
                            'slug' => str_slug($subCategory['Name'])
                        );
                    } else {
                        $subCategory = $programSubCategories['ProgramCategory'][$i];

                        $this->parentsChilds[$subCategory['CategoryId']] = array(
                            'parentCategoryId' => $subCategory['ParentCategoryId'],
                            'categoryId'=> $subCategory['CategoryId'],
                            'name' => $subCategory['Name'],
                            'slug' => str_slug($subCategory['Name'])
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
                if (!in_array($parentResult['name'], $flattenCategories) && count($duplicatedCategories) >= 1) {
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
                if(isset($this->parentsArray[$parrentChildsItems['parentCategoryId']])) {
                    $parentKey = str_slug($this->parentsArray[$parrentChildsItems['parentCategoryId']]['name']);

                    $temporaryParentKey = $this->temporaryParents[$parentKey];

                    $subcategoriesQuery = Category::select('id', 'name');

                    if (is_array($temporaryParentKey)) {
                        $subcategoriesQuery = $subcategoriesQuery->whereIn('subcategory_id', $temporaryParentKey);
                    } else {
                        $subcategoriesQuery = $subcategoriesQuery->where('subcategory_id', $temporaryParentKey);
                    }

                    $subcategoriesQuery = $subcategoriesQuery->get()->toArray();
                    $itemSlug  = $parrentChildsItems['name'];

                    // This subcategory isn't in the database
                    if(!in_array($itemSlug, array_flatten($subcategoriesQuery))) {
                        if (is_array($temporaryParentKey)) {
                            foreach ($temporaryParentKey as $temporaryParentKeyId) {
                                $createSubCategory = Category::newItem($itemSlug, $temporaryParentKeyId);
                                $this->temporaryChilds[$parrentChildsItems['categoryId']] = array(
                                    'id' => $createSubCategory->id,
                                    'name' => $itemSlug
                                );
                            }
                        } else {
                            $createSubCategory = Category::newItem($itemSlug, $temporaryParentKey);
                            $this->temporaryChilds[$parrentChildsItems['categoryId']] = array(
                                'id' => $createSubCategory->id,
                                'name' => $itemSlug
                            );
                        }
                    }

                    // This subcategory is in the database
                    foreach ($subcategoriesQuery as $subcategoriesFetch) {
                        if (is_array($temporaryParentKey)) {
                            foreach ($temporaryParentKey as $temporaryParentKeyId) {
                                $this->temporaryChilds[$parrentChildsItems['categoryId']] = array(
                                    'id' => $subcategoriesFetch['id'],
                                    'name' => $subcategoriesFetch['name']
                                );
                            }
                        } else {
                            $this->temporaryChilds[$parrentChildsItems['categoryId']] = array(
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
        $campaignCommision = $this->client->GetProgramRates(array(
            'CredentialToken' => $this->token,
            'PublisherId' => 763801,
            'ProgramId' => $campaignId
        ));
                        
        $campaignCommision = json_decode(json_encode($campaignCommision), true);

        foreach ($campaignCommision as $commision) {
            if ($this->affiliateHelper->arrayAssoc($commision)) {
                # Commission array with aplha keys
                if ($commision['RateValue'] > 0) {
                    $commissions[] = array(
                        'name' => ucfirst($commision['RateName']), 
                        'value' => $commision['RateValue'],
                        'unit' => $commision['Unit']
                    );
                }
            } else {
                # Commission array with numeric keys
                foreach ($commision as $rate) {
                    if ($rate['RateValue'] > 0) {
                        $commissions[] = array(
                            'name' => ucfirst($rate['RateName']),
                            'value' => $rate['RateValue'],
                            'unit' => $rate['Unit']
                        );
                    }
                }
            }
        }

        $commissions = array_unique($commissions, SORT_REGULAR);

        return isset($commissions) ? json_encode($commissions) : '';
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'affilinet_affiliate';

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
                        $this->updateCampaigns(); // Update Campagins
                        $this->addCampaigns(); // Add Campagins

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