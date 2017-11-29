<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\CompanyCallcenter;
use App\Models\MailTemplate;
use App\Models\TemporaryAuth;
use Illuminate\Http\Request;
use Sentinel;
use Redirect;
use DB;

class AppointmentController extends Controller 
{

    public function __construct(Request $request)
    {
        $this->slugController = 'appointments';
        $this->section = 'Afspraken';
        $this->limit = $request->input('limit', 15);
    }

    public function index(Request $request) 
    {
        $appointments = Appointment::select(
            'appointments.id',
            'appointments.email',
            'appointments.status',
            'appointments.comment',
            'appointments.created_at',
            'appointments.place',
            'appointments.appointment_at',
            'companies_callcenter.name',
            'users.name as callerName'
        )
            ->leftJoin('users', 'appointments.caller_id', '=', 'users.id')
            ->leftJoin('companies_callcenter', 'companies_callcenter.id', '=', 'appointments.company_id')
        ;

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            $appointments = $appointments->orderBy('appointments.'.$request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $appointments = $appointments->orderBy('appointments.created_at', 'desc');
        }

        if ($request->has('caller_id') && Sentinel::inRole('admin')) {
            $appointments = $appointments->where('appointments.caller_id', '=', $request->input('caller_id'));
        } 

        # Filter by search term
        if ($request->has('q')) {
            $appointments = $appointments->where(function ($query) use($request) {
                $query
                    ->where('appointments.from', 'LIKE', '%'.$request->input('q').'%' )
                    ->orWhere('appointments.created_at', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('appointments.status', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('appointments.name', 'LIKE', '%'.$request->input('q').'%')
                ;
            });
        }
        # Filter by date
        if ($request->has('date')) {
            $appointments = $appointments->whereDate('appointments.appointment_at', '=', date('Y-m-d', strtotime($request->input('date'))));
        }

        $appointments = $appointments->paginate($this->limit);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $appointments->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $appointments->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);

        $role = Sentinel::findRoleBySlug('callcenter');
        $callcenterUsers = $role->users()->with('roles')->get();

        return view('admin.appointments.index', array(
            'callcenterUsers' => $callcenterUsers,        
            'appointments' => $appointments,        
            'queryString'=> $queryString,
            'slugController' => $this->slugController,
            'paginationQueryString' => $request->query(),
            'limit' => $this->limit,
            'currentPage' => 'Afspraken',
            'section' => 'Overzicht'  
        ));
    }

    public function indexAction(Request $request)
    {
         if ($request->has('id')) {
            $notification = Appointment::whereIn('id', $request->input('id'))->delete();

            Alert::success('De gekozen selectie is succesvol verwijderd.')->persistent('Sluiten');
        } 

        return Redirect::to('admin/'.$this->slugController);
    }

    public function create($slug = NULL)
    {
        $companies = CompanyCallcenter::lists('name', 'id');

        if ($slug != NULL) {
            $companyBySlug = CompanyCallcenter::select(
                'id'
            )
                ->where('slug', $slug)
                ->where('no_show', '=', 0)
                ->first()
            ;
        }

        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'companyBySlug' => isset($companyBySlug) ? $companyBySlug->id : '', 
            'companies' => $companies, 
            'currentPage' => 'Nieuwe afspraak'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, array(
            'date' => 'required',
            'email' => 'required',
            'time' => 'required',
            'company' => 'required|exists:companies_callcenter,id'
        ));
        
        // Get company and save contact person name
        $callcenterCompany = CompanyCallcenter::where('id', '=', $request->input('company'))->first();
        $callcenterCompany->contact_name = $request->input('contact_name');

        if ($request->input('send_mail') == 1) {
            $temporaryAuth = new TemporaryAuth();
            $mailtemplate = new MailTemplate();

            // Check if the user exists
            $user = Sentinel::findByCredentials(array(
                'login' => $request->input('email')
            ));

            // Add a new user / owner
            if (!$user) {
                $randomPassword = str_random(20);

                $user = Sentinel::registerAndActivate(array(
                    'email' => $request->input('email'),
                    'password' => $randomPassword
                ));

                if ($callcenterCompany->contact_name != NULL) {
                    $user->name = $callcenterCompany->contact_name;
                }

                $user->save();
            } else {
                $role = Sentinel::findRoleById('2');

                if ($role != null)  {
                    $role->users()->detach($user);
                }
            }

            // Add a new company
            $addCompany = new Company;
            $addCompany->slug = str_slug($callcenterCompany->name);
            $addCompany->name = $callcenterCompany->name;
            $addCompany->contact_name = $callcenterCompany->contact_name;
            $addCompany->contact_email = $callcenterCompany->contact_email;
            $addCompany->contact_phone = $callcenterCompany->contact_phone;
            $addCompany->email = $callcenterCompany->email;
            $addCompany->phone = $callcenterCompany->phone;
            $addCompany->address = $callcenterCompany->address;
            $addCompany->zipcode = $callcenterCompany->zipcode;
            $addCompany->city = $callcenterCompany->city;
            $addCompany->website = $callcenterCompany->website;
            $addCompany->regio = $callcenterCompany->regio;
            $addCompany->no_show = 1;
            $addCompany->financial_email = $callcenterCompany->financial_email;
            $addCompany->financial_iban = $callcenterCompany->financial_iban;
            $addCompany->financial_iban_tnv = $callcenterCompany->financial_iban_tnv;
            $addCompany->kvk = $callcenterCompany->kvk;
            $addCompany->btw = $callcenterCompany->btw;
            $addCompany->user_id = $user->id;
            $addCompany->save();

            $callcenterCompany->company_id = $addCompany->id;

            // Create auth code for login
            $createAuthSignup = $temporaryAuth->createCode($user->id, 'admin/companies/update/'.$addCompany->id.'/'.$addCompany->slug.'?step=1');

            // Send a company sign up mail
            $mailtemplate->sendMailSite(array(
                'email' => $request->input('email'),
                'template_id' => 'appointment_mail',
                'fromEmail' => Sentinel::getUser()->email,
                'replacements' => array(
                    '%name%' => $callcenterCompany->contact_name,
                    '%cname%' => $callcenterCompany->contact_name,
                    '%date%' => date('d-m-Y'),
                    '%time%' => date('H:i'),
                    '%email%' => $request->input('email'),
                    '%status%' => $request->input('status'),
                    '%comment%' => $request->input('comment'),
                    '%place%' => $request->input('place'),
                    '%url%' => url('auth/set/'.$createAuthSignup)
                )
            ));

            // Attach a role to the user
            $role = Sentinel::findRoleByName('Bedrijf');
            $role->users()->attach($user);
        }

        $callcenterCompany->save();

        if ($request->input('send_information_mail') == 1) {
            // Send an information mail
            $mailtemplate->sendMailSite(array(
                'email' => $request->input('email'),
                'template_id' => 'appointment_info_mail',
                'fromEmail' => Sentinel::getUser()->email,
                'replacements' => array(
                    '%name%' => $callcenterCompany->contact_name,
                    '%cname%' => $callcenterCompany->contact_name,
                    '%date%' => date('d-m-Y'),
                    '%time%' => date('H:i'),
                    '%email%' => $request->input('email'),
                    '%status%' => $request->input('status'),
                    '%place%' => $request->input('place'),
                    '%comment%' => $request->input('comment'),
                    '%url%' => ''
                )
            ));
        }

        Appointment::create([
            'appointment_at' => date('Y-m-d H:i').':00',
            'status' => $request->input('status'),
            'email' => $request->input('email'),
            'place' => $request->input('place'),
            'comment' => $request->input('comment'),
            'company_id' => $addCompany->id,
            'send_mail' => $request->has('send_mail') ? $request->input('send_mail') : 0,
            'send_reminder' => $request->input('send_reminder'),
            'caller_id' => Sentinel::getUser()->id,
            'last_reminder_at' => $request->input('send_mail') == 1 ? date('Y-m-d H:i:s') : ''
        ]);

        Alert::success('Er is succevol een nieuwe afspraak aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/appointments');
    }

    public function update($id)
    {
        $appointment = Appointment::find($id);
        $companies = CompanyCallcenter::lists('name', 'id');

        return view('admin/appointments/update', [
            'slugController' => $this->slugController,
            'appointment' => $appointment,
            'companies' => $companies,
            'section' => 'Afspraken', 
            'currentPage' => 'Afspraken wijzigen'
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $appointment = Appointment::find($id);

        $this->validate($request, array(
            'date' => 'required',
            'time' => 'required'
        ));

        $appointment->appointment_at = $request->input('date').' '. $request->input('time');
        $appointment->status = $request->input('status');
        $appointment->email = $request->input('email');
        $appointment->place = $request->input('place');
        $appointment->company_id = $request->input('company');
        $appointment->comment = $request->input('comment');
        $appointment->send_reminder = $request->has('send_reminder') ? 1 : 0;
        $appointment->save();
        
        Alert::success('Uw afspraak is succesvol gewijzigd.')->persistent('Sluiten');   

        return Redirect::to('admin/appointments');
    }

}