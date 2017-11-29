<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\User;
use App\Models\Company;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\AccountWeight;
use App\Models\AccountRepMax;
use App\Models\Content;
use App\Models\Notification;
use App\Models\Preference;
use App\Models\CompanyService;
use App\Models\CompanyCallcenter;
use App\Models\MailTemplate;
use App\Models\UserDocument;
use Illuminate\Http\Request;
use Sentinel;
use URL;
use anlutro\cURL\cURL;
use Config;

class AjaxController extends Controller 
{

    public function uplodDocuments(Request $request) 
    { 
        $pageMedia = UserDocument::where('page_id', $request->input('page'))->first();

        if ($pageMedia) {
            $newDocument = $pageMedia;
        } else {
            $newDocument = new UserDocument();
        }

        $newDocument->page_id = $request->input('page');
        $newDocument->is_admin_document = 1;
        $newDocument->save();

        $newDocument
            ->addMedia($request->file('files')[0])
            ->toCollection('page-'.$request->input('page'))
        ;

        echo json_encode($request->file('files'));
    }

    public function removeDocuments(Request $request) 
    { 
        $pageMedia = UserDocument::where('page_id', $request->input('page'))->first();

        $media = $pageMedia->getMedia();
        $media[$request->input('fileid')]->delete();
    }

    public function removePhotos(Request $request) 
    { 
        $switchArray = array(
            'row1' => 'group1',
            'row2' => 'group2',
            'row3' => 'group3',
        );

        $media = User::find(Sentinel::getUser()->id)->getMedia($switchArray[$request->input('row')]);
        $media[$request->input('fileid')]->delete();
    }

    public function uploadPhotos(Request $request) 
    { 
        $switchArray = array(
            'row1' => 'group1',
            'row2' => 'group2',
            'row3' => 'group3',
        );

        $user = User::find(Sentinel::getUser()->id)
            ->addMedia($request->file('files')[0])
            ->toCollection($switchArray[$request->input('row')])
        ;

        echo json_encode($request->file('files'));
    }

    public function setWeights(Request $request) 
    { 
        $weight = AccountWeight::where('user_id', Sentinel::getUser()->id)->first();

        if ($weight == null) {       
            $weight = new AccountWeight();
        }

        $weight->weight_json = json_encode($request->input('weight'));
        $weight->user_id = Sentinel::getUser()->id;
        $weight->save();
    }

    public function setRepMax(Request $request) 
    { 
        $weight = AccountRepMax::where('user_id', Sentinel::getUser()->id)->first();

        if ($weight == null) {       
            $weight = new AccountRepMax();
        }

        $weight->weight_json = json_encode($request->input('weight'));
        $weight->user_id = Sentinel::getUser()->id;
        $weight->save();
    }

    public function usersSetRegio(Request $request) 
    { 
        $preferences = new Preference;

        $regio = $preferences->getRegio();
        $city = str_slug($request->input('city'));

        if ($request->has('city') && isset($regio['regioNumber'][$city]) && Sentinel::check()) {
            $user = Sentinel::getUser();
            $userCities = is_array(json_decode($user->city)) ? json_decode($user->city) : array();

            if (!in_array($regio['regioNumber'][$city], $userCities)) {
                $exists = 0;
            } else {
                $exists = 1;
            }

            if (is_array($userCities)) {
                if (!in_array($regio['regioNumber'][$city], $userCities)) {
                    array_push($userCities, ''.$regio['regioNumber'][$city].'');
                    
                    $user->city = json_encode($userCities);
                    $user->save();
                }
            }

            return $exists;
        }

        if ($request->has('city') && isset($regio['regioNumber'][$city]) && !Sentinel::check()) {
            // session('user_off_regio', $regio['regioNumber'][$city]);
            

            return response('Hello World')->cookie(
                'user_off_regio', $regio['regioNumber'][$city]
            );
        }
    }

    public function nearbyCompany(Request $request) 
    {
        $preferences = Preference::where('category_id', '=', 2)
            ->with('media')
            ->get()
        ;

        foreach ($preferences as $key => $preference) {
            $media = $preference->getMedia();

            $preferencesArray[str_slug($preference->slug)] = array(
                'slug' => str_slug($preference->slug),
                'name' => $preference->name,
                'image' => isset($media[0]) ? URL::to('public'.$media[0]->getUrl('thumb')) : ''
            );
        }

        $companies = Company::where('no_show', '=', 0)
            ->where('address', '=', $request->input('address'))
            ->where('zipcode', '=', $request->input('zipcode'))
            ->groupBy('name')
            ->get()
        ;

        $company = array();

        foreach ($companies as $key => $result) {
            $curl = new cURL;

            $curlResult = $curl->newRequest(
                'GET', 'https://maps.googleapis.com/maps/api/geocode/json?address='.urlencode($result->address.','.$result->zipcode)
            )
                ->setOption(CURLOPT_CAINFO, base_path('cacert.pem'))
                ->send()
            ;

            $body = json_decode($curlResult->body);

            if (count($body) == 1) {
                if (is_array($body->results) && isset($body->results[0])) {
                    $geometry = $body->results[0]->geometry->location;

                    $company[] = array(
                        'lat' => $geometry->lat,
                        'lng' => $geometry->lng,
                        'name' => $result->name,
                        'address' => $result->address,
                        'zipcode' => $result->zipcode,
                        'city' => $result->city,
                        'url' => url('restaurant/'.$result->slug),
                        'company_type' => $result->company_type
                     );
                }
            }
        }

        return json_encode($company);
    }

    public function nearbyCompanies(Request $request) 
    {  
        $preferences = Preference::where('category_id', '=', 2)
            ->with('media')
            ->get()
        ;

        foreach ($preferences as $key => $preference) {
            $media = $preference->getMedia();

            $preferencesArray[str_slug($preference->slug)] = array(
                'slug' => str_slug($preference->slug),
                'name' => $preference->name,
                'image' => isset($media[0]) ? URL::to('public'.$media[0]->getUrl('thumb')) : ''
            );
        }

        $companies = Company::where('no_show', '=', 0)
            ->where('address', '!=', '')
            ->where('zipcode', '!=', '')
            ->groupBy('name')
            ->get()
        ;

        $company = array();

        foreach ($companies as $key => $result) {
             $company[] = array(
                'name' => $result->name,
                'address' => $result->address,
                'zipcode' => $result->zipcode,
                'city' => $result->city,
                'url' => url('restaurant/'.$result->slug),
                'company_type' => $result->company_type,
                'kitchen' => (is_array(json_decode($result->kitchens)) && isset($preferencesArray[str_slug(json_decode($result->kitchens)[0])]) ? $preferencesArray[str_slug(json_decode($result->kitchens)[0])]['image'] : '')
            );
        }

        return json_encode($company);
    }

    public function mailtemplates(Request $request) 
    {  
        $mailtemplate = MailTemplate::select(
            'mail_templates.id',
            'mail_templates.is_active'
        )
            ->leftJoin('companies', 'mail_templates.company_id', '=', 'companies.id')
        ;

        if (Sentinel::inRole('bedrijf') && Sentinel::inRole('admin') == FALSE)  {
            $mailtemplate = $mailtemplate->where('companies.user_id', Sentinel::getUser()->id);
        }

        $mailtemplate  = $mailtemplate->find($request->input('id'));

        if ($mailtemplate) {
            $mailtemplate->is_active = $request->input('is_active');
            $mailtemplate->save();
        }
    }

    public function appointmentCompanies(Request $request) 
    {  
        $companies = CompanyCallcenter::select(
            'id',
            'name',
            'city',
            'email',
            'address',
            'zipcode',
            'contact_name'
        )
            ->where('id', $request->input('id'))
            ->get()
            ->toArray()
        ;

        return count($companies) == 1 ? json_encode($companies) : json_encode(array());
    }

    public function notifications(Request $request) 
    {  
        $notifications = Notification::with('media')
            ->where('is_on', 1)
        ;

        if ($request->has('id')) {
            $notifications = Notification::where('id', $request->input('id'));        
        }

        $notifications = $notifications->first();

        if ($notifications) {
            $mediaItems = $notifications->getMedia();

            if ($notifications) {
               $jsonArray['text'] = '
                '.(isset($mediaItems[0]) ? '<img width="'.($request->has('width') ? $request->input('width') : $notifications->width).'px" height="'.($request->has('height') ? $request->input('height') : $notifications->height).'px" src="'.url('public/'.$mediaItems[0]->getUrl()).'" /><br />' : '').'
                <div class="description">
                    '.$request->input('content').'
               </div>';

               $jsonArray['success'] = 1;
               $jsonArray['id'] = $notifications->id;
           }
        } else {
            $jsonArray['success'] = 0;
        }

        return isset($jsonArray) ? json_encode($jsonArray) : '';
    }

    public function popupCompanies(Request $request) 
    {
        $company = Company::select(
            'days',
            'company_html',
            'name',
            'id'
        )
            ->find($request->input('company_id'))
        ;

        foreach (Config::get('preferences.days') as $key => $day) {
            if (in_array($key, json_decode($company->days))) {
                $days[] = $day;
            }
        }

        $contentBlock = Content::getBlocks(array(
            'days' => isset($days) ? implode(', ', $days) : ''
        ));

        if ($company) {
            $companyClick = new Company;
            $companyClick->addPopupClick($company->id);
        }

        $company['textBlock'] = isset($contentBlock[59]) ? $contentBlock[59] : '';

        return count($company) >= 1 ? json_encode($company) : '';
    }

    public function adminCompaniesServices(Request $request) 
    {
        $data = CompanyService::select(
            'name',
            'content',
            'tax',
            'price'
        )
            ->where('company_id','=', $request->input('company'))
            ->get()
            ->toArray()
        ;

        $errorMessage = array(
            'error' => 'Uw gekozen bedrijf heeft geen producten. <a href="'.URL::to('admin/services/create').'" target="_blank">Klik hier</a> om nieuwe producten toe te voegen.'
        );

        return count($data) >= 1 ? json_encode($data) : json_encode($errorMessage);

    }

    public function adminCompaniesContract(Request $request) 
    {
        $data = Company::where('slug','=', $request->input('slug'));

        if (Sentinel::inRole('bedrijf') && Sentinel::inRole('admin') == FALSE)  {
            $data = $data->where('user_id', Sentinel::getUser()->id);
        }

        $data = $data->first();

        if ($data) {
            $documentItems = $data->getMedia('documents');

            foreach ($documentItems as $doc) {
                $documents[] = '<a href="'.url('public/'.$doc->getUrl()).'" target="_blank"><i class="file pdf outline red large icon"></i> Contract</a><br />';
            }
        }

        return isset($documents) ? implode(',', $documents) : 'Er is momenteel nog geen contract voor uw bedrijf.';
    }

    public function faq(Request $request) 
    {
        $faq = Faq::find($request->input('id'));
        $faq->clicks = ($faq->clicks + 1);
        $faq->save();
    }

    public function faqSubCategories(Request $request) 
    {
        $subcategories = FaqCategory::select(
            'id', 
            'name'
        )
            ->where('category_id', '=', $request->input('category'))
            ->get()
            ->toArray()
        ;

        return json_encode($subcategories);
    }

    public function faqSearch(Request $request) 
    {
        $faqQuery = Faq::select(
            'id', 
            'title',
            'answer'
        )
            ->where('title', 'LIKE', '%'.$request->input('q').'%')
            ->get()
        ;

        $faq = array();

        foreach ($faqQuery as $key => $info) {
            $faq[$key]['name'] =  $info->title;
            $faq[$key]['link'] = URL::to('faq?q='.$request->input('q'));
        }     

        $faqJson['items'] = $faq;

        return json_encode($faqJson);
    }

    public function users(Request $request)
    {
        $users = Sentinel::getUserRepository()->select(
            'id', 
            'name',
            'email'
        )
            ->where('name', 'LIKE', $request->input('q').'%')
            ->orWhere('email', 'LIKE', $request->input('q').'%')
            ->get()
            ->toArray()
        ;

        $user['items'] = $users;

        return json_encode($user);
    }

    public function barcodesCompanies(Request $request) 
    {
        $company = Company::select(
            'slug', 
            'name'
        )
            ->where('name', 'LIKE', $request->input('q').'%')
            ->get()
            ->toArray()
        ;

        foreach ($company as $key => $info) {
            $company[$key]['link'] = URL::to('admin/barcodes?company='.$info['slug']);
        }     

        $companies['items'] = $company;

        return json_encode($companies);
    }

    public function usersCompanies(Request $request) 
    {
        $termDivider = str_replace(' ', '|', $request->input('q'));

        $companies = Company::where('no_show', '=', 0)
            ->where('company_type',  $request->has('type') && $request->input('type') == 'cinema' ? 1 : 0)
            ->where(function ($query) use($request, $termDivider) {
                $query
                    ->where('name', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('address', 'RLIKE', $termDivider)
                    ->orWhere('zipcode', 'RLIKE', $termDivider)
                    ->orWhere('city', 'RLIKE', $termDivider)
                ;
            })
            ->with('media')
            ->get()
        ;

        $company = array();
        foreach ($companies as $key => $info) {
            $media = $info->getMedia('default');
            $company[$key]['name'] = $info->name;
            $company[$key]['link'] = url('restaurant/'.$info->slug);

            if (isset($media[0])) {
                $company[$key]['image'] = url('public'.($media[0]->getUrl('175Thumb')));
            } else {
               $company[$key]['image'] = url('public/images/placeholdimage.png');
            }     
        }     

        $companiesJson['items'] = $company;

        return json_encode($companiesJson);
    }

    public function adminCompaniesInvoices(Request $request) 
    {
        $company = Company::select(
            'slug', 
            'name'
        )
            ->where('name', 'LIKE', $request->input('q').'%')
            ->get()
            ->toArray()
        ;

        foreach ($company as $key => $info) {
            $company[$key]['link'] = URL::to('admin/invoices/overview/'.$info['slug']);
        }     

        $companies['items'] = $company;

        return json_encode($companies);
    }

    public function adminCompanies(Request $request) 
    {
        $company = Company::select(
            'slug', 
            'name'
        )
            ->where('name', 'LIKE', $request->input('q').'%')
        ;

        $second = Sentinel::getUserRepository()->select(
            'id as slug', 
            'name'
        )
            ->where('name', 'LIKE', $request->input('q').'%')
            ->union($company)
            ->get()
            ->toArray()
        ;

        foreach ($second as $key => $info) {
            if (is_numeric($info['slug'])) {
                $companies[$key]['link'] = URL::to('admin/reservations/saldo?user='.$info['slug']);
                $companies[$key]['name'] = $info['name'];
            } else {
                $companies[$key]['link'] = URL::to('admin/reservations/saldo/'.$info['slug']);
                $companies[$key]['name'] = $info['name'];
            }
        }     

        $companiesJson['items'] = isset($companies) ? $companies : '';

        return json_encode($companiesJson);
    }

    public function adminCompaniesOwners(Request $request) 
    {
        $owner = Sentinel::getUserRepository()->select(
            'users.id', 
            'users.name', 
            'users.email', 
            'users.phone'
        )
            ->whereIn('default_role_id', array(2,3))
            ->where('name', 'LIKE', $request->input('q').'%')
            ->get()
            ->toArray()
        ;

        $owners['items'] = $owner;

        return json_encode($owners);
    }

    public function adminCompaniesCallers(Request $request) 
    {
        $owner = Sentinel::getUserRepository()->select(
            'users.id', 
            'users.name', 
            'users.email', 
            'users.phone'
        )
            ->where('default_role_id', '=', 5)
            ->where('name', 'LIKE', $request->input('q').'%')
            ->get()
            ->toArray()
        ;

        $owners['items'] = $owner;

        return json_encode($owners);
    }

    public function adminCompaniesWaiters(Request $request) 
    {
        $owner = Sentinel::getUserRepository()->select(
            'users.id', 
            'users.name', 
            'users.email', 
            'users.phone'
        )
            ->where('default_role_id', '=', '4')
            ->where('name', 'LIKE', $request->input('q').'%')
            ->get()
            ->toArray()
        ;

        $owners['items'] = $owner;

        return json_encode($owners);
    }

    public function adminGuestsQuery(Request $request)
    {
        $queryString = array();

        if($request->has('limit')) {
            $queryString['limit'] = $request->input('limit');
        }

        if($request->has('sort')) {
            $queryString['sort'] = $request->input('sort');
        }

        if($request->has('order')) {
            $queryString['order'] = $request->input('order');
        }

        parse_str($request->input('query'), $getParameters);
        unset($getParameters['order']);
        unset($getParameters['limit']);
        unset($getParameters['sort']);

        if ($request->has('query')) {
            foreach ($getParameters as $key => $getParameter) {
                foreach ($getParameter as $getParameterValue) {
                    if ($key == 'dayno') {
                       $queryString['dayno'][$getParameterValue] = $getParameterValue;
                    }

                    if ($key == 'time') {
                       $queryString['time'][] = $getParameterValue;
                   }
                }
            }
        }

        if ($request->has('city')) {
            foreach ($request->input('city') as $days => $day) {
                $queryString['city'][$day] = $day;
            }
        }

        if ($request->has('allergies')) {
            foreach ($request->input('allergies') as $key => $time) {
                $queryString['allergies'][] = $time;
            }
        }

        if ($request->has('preferences')) {
            foreach ($request->input('preferences') as $key => $time) {
                $queryString['preferences'][] = $time;
            }
        }

        return urldecode(http_build_query($queryString));
    }

}