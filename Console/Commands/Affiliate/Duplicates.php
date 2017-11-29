<?php

namespace App\Console\Commands\Affiliate;

use Illuminate\Console\Command;
use App\Models\Category;
use App\Models\Affiliate;
use App\Models\AffiliateCategory;
use App\Helpers\AffiliateHelper;
use Exception;
use Request;
use DB;

class Duplicates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dulicates:affiliate';

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
        $results = Affiliate::whereIn('id', function ($query) {
            $query
                ->select('id')
                ->from('affiliates')
                ->groupBy(array('name'))
                ->havingRaw('count(*) > 1')
            ;
        })
            ->get()
        ;

        foreach ($results as $key => $value) {
            $duplicateId[] = $value->id;
            $duplicateNames[] = $value->name;
        }

        if (isset($duplicateNames, $duplicateId)) {
            $dulicated = Affiliate::whereIn('name', $duplicateNames)->whereNotIn('id', $duplicateId)->get();

            foreach ($dulicated as $key => $duplicate) {
                $affiliateIds[] = $duplicate->id;
            }

            if (isset($duplicateId)) {
                $affiliates = Affiliate::whereIn('id', $affiliateIds)->delete();
                $affiliates = AffiliateCategory::whereIn('affiliate_id', $affiliateIds)->delete();
            }
        }
    }

}