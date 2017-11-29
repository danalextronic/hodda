<?php

namespace App\Providers;

use App;
use App\User;
use App\Models\Content;
use App\Models\Preference;
use App\Models\SysSetting;
use App\Models\Page;
use Carbon\Carbon;
use Session;
use Setting;
use Illuminate\Support\ServiceProvider;
use Blade;
use Request;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(Request $request)
    {
        $preference = new Preference();

        $preference = new Preference();
        $websiteSettings =  json_decode(json_encode(Setting::get('website')), true);
        $sources = (isset($websiteSettings['source']) ? explode(PHP_EOL, $websiteSettings['source']) : array());

        $sidemenuPages = Page::where('in_sidemenu', 1)
            ->where('is_hidden', 0)
            ->get()
        ;
        $cities = Preference::where('category_id', 9)
            ->where('no_frontpage', 0)
            ->with('media')
            ->orderByRaw('name asc')
            ->get()
        ;

        $sliderMedia = SysSetting::where('key', '=', 'header_active')
            ->with('media')
            ->first()
        ;

        view()->share(array(
            'sidemenuPages' => $sidemenuPages,
            'sliderMedia' => $sliderMedia ? $sliderMedia->getMedia('images') : array(),
            'contentBlock' => Content::getBlocks(),
            'cityArray' => $cities,
            'regio' => $preference->getRegio()['regio'],
            'preference' => Preference::getPreferences(),
            'pageLinks' => Page::getPages(),
            'discountSettings' => json_decode(json_encode(Setting::get('discount')), true),
            'websiteSetting' => json_decode(json_encode(Setting::get('website')), true),
            'sources' => $sources,
            'convertLocale' => array(
                'en' => 'gb',
                'nl' => 'nl',
                'fr' => 'fr',
                'be' => 'be',
                'de' => 'de',
            )
        ));
      
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {

    }
}
