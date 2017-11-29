<?php
namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\MailtemplateRequest;
use App;
use Alert;
use App\User;
use App\Http\Controllers\Controller;
use App\Models\MailTemplate;
use App\Models\Company;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;
use Setting;

class MailTemplatesController extends Controller 
{

    public function __construct()
    {
        $this->slugController = 'mailtemplates';
        $this->section = 'Meldingen';
        $this->companies = Company::all();
        $this->admin = Sentinel::inRole('admin');
    }

    public function index(Request $request, $slug = null)
    {
        $data = MailTemplate::select(
            'mail_templates.id',
            'mail_templates.subject',
            'mail_templates.category',
            'mail_templates.is_active',
            'mail_templates.type',
            'companies.name'
        )
            ->leftJoin('companies', 'mail_templates.company_id', '=', 'companies.id')
        ;

        if ($request->has('type')) {
            $data = $data->where('type', '=', $request->input('type'));
        }

        if ($request->has('q')) {
            $data = $data->where('subject', 'LIKE', '%'.$request->input('q').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $data = $data->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('mail_templates.id', 'desc');
        }
        
        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));
        $data->setPath($this->slugController.(trim($slug) != '' ? '/'.$slug : ''));

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }
        
        $queryString = $request->query();
        unset($queryString['limit']);

        return view('admin/'.$this->slugController.'/index', [
            'data' => $data, 
            'countItems' => $dataCount, 
            'companyParam' => $slug,
            'companies' => $this->companies,
            'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section, 
            'currentPage' => 'Overzicht'        
        ]);
    }

    public function indexCompany(Request $request, $slug = null)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == TRUE || Sentinel::inRole('admin')) {
            $data = MailTemplate::select(
                'mail_templates.id',
                'mail_templates.subject',
                'mail_templates.is_active',
                'mail_templates.category',
                'mail_templates.type',
                'companies.name'
            )
                ->leftJoin('companies', 'mail_templates.company_id', '=', 'companies.id')
            ;
   
            if ($request->has('type')) {
                $data = $data->where('type', '=', $request->input('type'));
            }

            if ($request->has('q')) {
                $data = $data->where('subject', 'LIKE', '%'.$request->input('q').'%');
            }

            if ($request->has('sort') && $request->has('order')) {
                $data = $data->orderBy($request->input('sort'), $request->input('order'));

                session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
            }

            $data = $data->where('companies.slug', $slug);

            $dataCount = $data->count();

            $data = $data->paginate($request->input('limit', 15));
            $data->setPath('/admin/mailtemplates/'.(trim($slug) != '' ? $slug : ''));

            $queryString = $request->query();
            unset($queryString['limit']);

            return view('admin/'.$this->slugController.'/index', [
                'data' => $data, 
                'companies' => $this->companies,
                'countItems' => $dataCount, 
                'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
                'companyParam' => $slug,
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'limit' => $request->input('limit', 15),
                'section' => $this->section, 
                'currentPage' => 'Overzicht'        
            ]);
        }
    }

    public function create($slug = null)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        if ($companyOwner['is_owner'] == TRUE) {
            return view('admin/'.$this->slugController.'/create', [
                'slugController' => $this->slugController.(trim($slug) != '' ? '/'.$slug : ''),
                'companyParam' => $slug,
                'section' => $this->section, 
                'currentPage' => 'Nieuw mail template'
            ]);
        } elseif (Sentinel::inRole('admin')) {
            return view('admin/'.$this->slugController.'/create', [
                'companies' => Company::lists('name', 'id'),
                'slugController' => $this->slugController,
                'section' => $this->section, 
                'currentPage' => 'Nieuw mail template'
            ]);
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
    }

    public function createAction(Request $request, $slug = null)
    {
        $companyOwner = Company::isCompanyUserBySlug($slug, Sentinel::getUser()->id);

        $this->validate($request, [
            'content' => 'required'
        ]);

        if ($companyOwner['is_owner'] == TRUE) {
            $data = new MailTemplate;
            $data->subject = $request->input('subject');
            $data->content = $request->input('content');
            $data->type = $request->input('type');
            $data->category = $request->input('category');
            $data->company_id  = $companyOwner['company_id'];
            $data->save();

            Alert::success('Dit mail template is succesvol aangemaakt.')->persistent('Sluiten');   
            return Redirect::to('admin/mailtemplates/update/'.$data->id);
        } elseif(Sentinel::inRole('admin')) {
            $data = new MailTemplate;
            $data->subject = $request->input('subject');
            $data->content = $request->input('content');
            $data->type = $request->input('type');
            $data->category = $request->input('category');
            $data->company_id = $request->input('company');
            $data->save();

            Alert::success('Dit mail template is succesvol aangemaakt.')->persistent('Sluiten');   
            return Redirect::to('admin/mailtemplates/update/'.$data->id);
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
    }

    public function update($id)
    {
        $data = MailTemplate::select(
            'mail_templates.*',
            'companies.slug',
            'companies.name'
        )
            ->leftJoin('companies', 'mail_templates.company_id', '=', 'companies.id')
            ->find($id)
        ;   

        if (
            count($data) >= 1 
            && Company::isCompanyUser($data->company_id, Sentinel::getUser()->id) 
            || Sentinel::inRole('admin')
        ) {
            return view('admin/'.$this->slugController.'/update', [
                'companies' => Company::lists('name', 'id'),
                'data' => $data,
                'slugController' => $this->slugController.(isset($data->slug) && trim($data->slug) != '' ? '/'.$data->slug : ''),
                'section' => $this->section,
                'admin' => $this->admin,  
                'currentPage' => 'Wijzig mail template'
            ]);
        } else {
            User::getRoleErrorPopup();
            return Redirect::to('/');
        }
    }

    public function updateAction(MailtemplateRequest $request, $id) 
    {
        $data = MailTemplate::find($id);
        $this->validate($request, []);

        if (
            Company::isCompanyUser($data->company_id, Sentinel::getUser()->id) 
            || Sentinel::inRole('admin')
        ) {
            $data->is_active = $request->input('is_active');
            $data->subject = $request->input('subject');
            $data->content = $request->input('content');
            $data->type = $request->input('type');
            $data->category = $request->input('category');
            $data->explanation = $request->input('explanation');
            $data->company_id = $request->has('company') ? $request->input('company') : $data->company_id;
            $data->save();

            Alert::success('Dit mail template is succesvol aangepast.')->persistent('Sluiten');   
            return Redirect::to('admin/mailtemplates/update/'.$id);
        }
    }

    public function deleteAction(Request $request, $slug = null)
    {
        if ($request->has('id')) {
            $data = MailTemplate::whereIn('id', $request->input('id'))
                ->delete()
            ;
        }

        Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
        return Redirect::to('admin/'.$this->slugController.(trim($slug) != '' ? '/'.$slug : ''));
    }

    public function settings(Request $request)
    {
        $settings = json_decode(json_encode(Setting::all()), true);
        
        return view('admin/mailtemplates/settings', [
            'slugController' => 'mailtemplates',
            'section' => 'Mailtemplates', 
            'currentPage' => 'Instellingen',
            'settings' => $settings
        ]);
    }

    public function settingsAction(Request $request)
    {
        Setting::set(
            array(
                'welcome_mail_title' => $request->input('welcome_mail_title'),
                'welcome_mail_content' => $request->input('welcome_mail_content'),
                'callcenter_info_mail_title' => $request->input('callcenter_info_mail_title'),
                'callcenter_info_mail_content' => $request->input('callcenter_info_mail_content'),
                'callcenter_mail_title' => $request->input('callcenter_mail_title'),
                'callcenter_mail_content' => $request->input('callcenter_mail_content'),
                'callcenter_reminder_title' => $request->input('callcenter_reminder_title'),
                'callcenter_reminder_content' => $request->input('callcenter_reminder_content'),
                'register_title' => $request->input('register_title'),
                'register_content' => $request->input('register_content'),
                'new_company_title' => $request->input('new_company_title'),
                'new_company_content' => $request->input('new_company_content'),
                'forgot_password_title' => $request->input('forgot_password_title'),
                'forgot_password_content' => $request->input('forgot_password_content'),
                'saldo_charge_title' => $request->input('saldo_charge_title'),
                'saldo_charge_content' => $request->input('saldo_charge_content'),
                'transaction_accepted_title' => $request->input('transaction_accepted_title'),
                'transaction_accepted_title' => $request->input('transaction_accepted_title'),
                'transaction_accepted_content' => $request->input('transaction_accepted_content'),
                'transaction_open_title' => $request->input('transaction_open_title'),
                'transaction_open_content' => $request->input('transaction_open_content'),
                'transaction_rejected_title' => $request->input('transaction_rejected_title'),
                'transaction_rejected_content'=> $request->input('transaction_rejected_content')
            )
        );

        Alert::success('De mail instellingen zijn succesvol aangepast.')->persistent('Sluiten');

        return Redirect::to('admin/mailtemplates/settings');
    }

}