<?php
namespace App\Http\Controllers\Admin;

use App;
use Alert;
use App\Http\Controllers\Controller;
use App\Models\CompanyService;
use App\Models\Company;
use App\Models\SysSetting;
use App\Models\Invoice;
use Config;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Intervention\Image\ImageManagerStatic;
use Intervention\Image\Exception\NotReadableException;
use Redirect;
use Setting;

class SettingsController extends Controller 
{

    public function __construct(Request $request)
    {
       	$this->slugController = 'settings';
       	$this->section = 'Website instellingen';
    }

    public function index(Request $request)
    {   
        $websiteSettings = json_decode(json_encode(Setting::get('website')), true);
        $discountSettings = json_decode(json_encode(Setting::get('discount')), true);
        $cronjobSettings = json_decode(json_encode(Setting::get('cronjobs')), true);
        $apiSettings = json_decode(json_encode(Setting::get('settings')), true);
        $kitchensSettings = json_decode(json_encode(Setting::get('filters')), true);
        $invoicesSettings = json_decode(json_encode(Setting::get('default')), true);

        $kitchens = Config::get('preferences.kitchens');
        $cities = array_values(Config::get('preferences.cities'));

        sort($cities);

        foreach ($cities as $key => $city) {
            $citiesArray[str_slug($city)] = $city;
        }

        $preferences = isset($websiteSettings['preferences']) && count(json_decode($websiteSettings['preferences'])) >= 1 ? json_decode($websiteSettings['preferences']) : Config::get('preferences.options');
       
        $preferencesArray = isset($websiteSettings['preferences']) && is_array(json_decode($websiteSettings['preferences'])) ? json_decode($websiteSettings['preferences']) : Config::get('preferences.options');
        end($preferencesArray);
        
        $mediaItems = SysSetting::where('key', '=', 'header_active')
            ->with('media')
            ->first()
        ;

        return view('admin/'.$this->slugController.'/index', [
            'lastKey' => key($preferencesArray),
            'mediaItems' => $mediaItems ? $mediaItems->getMedia('images') : array(),
            'preferences' => $preferences,
            'cities' => $citiesArray,
            'kitchens' => $kitchens,
            'slugController' => $this->slugController,
            'section' => $this->section,
            'currentPage' => 'Overzicht', 
            'kitchensSettings' => $kitchensSettings,
            'cronjobSettings' => $cronjobSettings,
            'apiSettings' => $apiSettings,
            'discountSettings' => $discountSettings,
            'invoicesSettings' => $invoicesSettings,
            'websiteSettings' => $websiteSettings
        ]);
    }

    public function indexAction(Request $request)
    {   
        $requests = $request->all();
        unset($requests['_token']);

        $settingsArray = array(
            'callcenter_reminder',
            'callcenter_reminder_status'
        );

        foreach ($requests as $key => $value) {
            if (in_array($key, $settingsArray)) {
                Setting::set('settings.'.$key, $value);
            }
        }

        Alert::success('De instellingen zijn succesvol aangepast.')->persistent('Sluiten');

        return Redirect::to('admin/settings');
    }

    public function cronjobsAction(Request $request)
    {   
        $requests = $request->all();
        unset($requests['_token']);

        Setting::forget('cronjobs');

        $settingsArray = array(
            'callcenter_reminder',
            'callcenter_reminder_status',
        );

        foreach ($requests as $key => $value) {
            Setting::set('cronjobs.'.$key, 1);
        }

        Alert::success('De instellingen zijn succesvol aangepast.')->persistent('Sluiten');

        return Redirect::to('admin/settings');
    }

    public function invoicesAction(Request $request)
    {   
        if ($request->isMethod('post')) {
            $requests = $request->all();

            Setting::set('default.services_noshow', $request->input('services_noshow'));
            Setting::set('default.services_name', $request->input('name'));
            Setting::set('default.services_price', $request->input('price'));
            Setting::set('default.services_tax', $request->input('tax'));

            Alert::success('De instellingen zijn succesvol aangepast.')->persistent('Sluiten');

            return Redirect::to('admin/settings');
        } else {
            alert()->error('', 'Het formulier is niet correct ingevuld.')->persistent('Sluiten');
            return Redirect::to('admin/settings');
        }
    }

    public function websiteAction(Request $request)
    {   
        if ($request->isMethod('post')) {
            $requests = $request->all();

            Setting::set('website.facebook', $request->input('facebook'));
            Setting::set('website.contact_email', $request->input('contact_email'));
            Setting::set('website.preferences', json_encode($request->input('preferences')));
            if ($request->hasFile('logo')) {
                ImageManagerStatic::make($request->file('logo'))->save(public_path('images/vplogo.png'));
            }


            Alert::success('De instellingen zijn succesvol aangepast.')->persistent('Sluiten');
            return Redirect::to('admin/settings');
        } else {
            alert()->error('', 'Het formulier is niet correct ingevuld.')->persistent('Sluiten');
            return Redirect::to('admin/settings');
        }
    }

    public function discountAction(Request $request)
    {   
        if ($request->isMethod('post')) {
            $requests = $request->all();

            if ($request->hasFile('header')) {
                Setting::set('header_active', 1);
                Setting::save();

                $setting = SysSetting::where('key', '=', 'header_active')
                    ->first()
                ;

                foreach ($request->file('header') as $pdf) {
                    $setting
                        ->addMedia($pdf)
                        ->toCollection('images')
                    ;
                }
            }

            // Google pointers
            $files = array(
                'company_google_pointer',
                'cinema_google_pointer',
            );

            foreach ($files as $id => $file) {
                if ($request->hasFile($file)) {
                    try {
                        ImageManagerStatic::make($request->file($file))->save(public_path('images/'.$file.'.'.$request->file($file)->getClientOriginalExtension()));
                    } catch (NotReadableException $e) {
                    }

                    Setting::set('discount.'.$file, 'images/'.$file.'.'.$request->file($file)->getClientOriginalExtension());
                }
            }

            Alert::success('De instellingen zijn succesvol aangepast.')->persistent('Sluiten');

            return Redirect::to('admin/settings');
        } else {
            alert()->error('', 'Het formulier is niet correct ingevuld.')->persistent('Sluiten');
            return Redirect::to('admin/settings');
        }
    }


    public function deleteImage(Request $request, $image)
    {
        $mediaItems = SysSetting::where('key', '=', 'header_active')
            ->with('media')
            ->first()
            ->getMedia('images')
        ;
        
        if (isset($mediaItems[$image])) {
            $mediaItems[$image]->delete();
        }

        alert()->success('', 'De gekozen afbeelding is succesvol verwijderd.')->persistent('Sluiten');

        return Redirect::to('admin/settings');
    }

    public function run(Request $request, $slug)
    {   
        switch ($slug) {
            case 'affilinet':
                Setting::set('cronjobs.affilinet_affiliate', 1);
                break;
            
            case 'tradetracker':
                Setting::set('cronjobs.tradetracker_affiliate', 1);
                break;

            case 'zanox':
                Setting::set('cronjobs.zanox_affiliate', 1);
                break;

            case 'daisycon':
                Setting::set('cronjobs.daisycon_affiliate', 1);
                break;

            case 'tradedoubler':
                Setting::set('cronjobs.tradedoubler_transaction', 1);
                break;

            case 'hotspot':
                Setting::set('cronjobs.wifi_guest', 1);
                break;
        }

        Alert::success('De gekozen api wordt nu uitgevoerd.')->persistent('Sluiten');

        return Redirect::to('admin/settings');
    }

}