<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CompanyCallcenter;
use App\Models\MailTemplate;
use DB;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Storage;
use Redirect;
use Mail;

class CompaniesCallcenterController extends Controller
{
    
    public function __construct()
    {
        $this->slugController = 'companies/callcenter';
        $this->section = 'Bellijst';
        $this->users = Sentinel::getUserRepository()->whereIn('default_role_id', [2,3])->get();
    }

    public function index(Request $request)
    {
        $data = CompanyCallcenter::select(
            'companies_callcenter.*',
            'companies.click_registration',
            'companies.id as companyId',
            'companies.signature_url'
        )
            ->where('companies_callcenter.no_show', '=', 0)
            ->leftJoin('users', 'users.id', '=', 'companies_callcenter.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'companies_callcenter.company_id')
        ;

        if ($request->has('q')) {
            $data = $data
                ->where('companies_callcenter.name', 'LIKE', '%'.$request->input('q'))
                ->orWhere('companies_callcenter.email', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.contact_email', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.address', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.contact_name', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.contact_phone', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.contact_role', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.city', 'LIKE', '%'.$request->input('q').'%')
                ->orWhere('companies_callcenter.zipcode', 'LIKE', '%'.$request->input('q').'%')
            ;
        }

        if ($request->has('date')) {
            $data = $data->whereDate('companies_callcenter.callback_at', '=', $request->input('date'));
        }

        if ($request->has('score')) {
            switch ($request->input('score')) {
                case 'won':
                    $status = 1;
                    break;

                case 'lose':
                    $status = 2;
                    break;

                case 'open':
                    $status = 0;
                    break;
                
            }

            $data = $data->where('companies_callcenter.score', '=', $status);
        }

        if ($request->has('caller_id')) {
            $data = $data->where('companies_callcenter.caller_id', '=', $request->input('caller_id'));
        }

        if ($request->has('favorite')) {
            $data = $data->where('companies_callcenter.caller_id', '=', Sentinel::getUser()->id);
        }

        if ($request->has('city')) {
            $data = $data->where('companies_callcenter.city', 'LIKE', '%'.$request->input('city').'%');
        }

        if ($request->has('sort') && $request->has('order')) {
            $companiesColumn = array(
                'updated_at',
                'name',
                'city'
            );

            if(in_array($request->input('sort'), $companiesColumn)) {
                $data = $data->orderBy('companies_callcenter.'.$request->input('sort'), $request->input('order'));
            } else {
                $data = $data->orderBy($request->input('sort'), $request->input('order'));
            }

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $data = $data->orderBy('companies_callcenter.id', 'desc');
        }

        $dataCount = $data->count();

        $data = $data->paginate($request->input('limit', 15));

        # Redirect to last page when page don't exist
        if ($request->input('page') > $data->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $data->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }
        
        $queryString = $request->query();
        unset($queryString['limit']);
        unset($queryString['caller_id']);

        $role = Sentinel::findRoleBySlug('callcenter');
        $callcenterUsers = $role->users()->with('roles')->get();

        return view('admin/callcenter/index', [
            'data' => $data,
            'callcenterUsers' => $callcenterUsers,
            'countItems' => $dataCount,
            'slugController' => $this->slugController,
            'queryString' => $queryString,
            'paginationQueryString' => $request->query(),
            'limit' => $request->input('limit', 15),
            'section' => $this->section,
            'currentPage' => 'Overzicht'
        ]);
    }

    public function callMeBack()
    {        
        return view('admin/callcenter/callmeback', [
            'users' => $this->users,
            'noAdmin' => 1,
            'slugController' => 'aansluiten',
            'section' => 'Aansluiten',
            'currentPage' => 'Bel mij terug'
        ]);
    }

    public function callMeBackAction(Request $request)
    {   
        $this->validate($request, array(
            'name' => 'required',
            'phone' => 'required|min:10',
            'email' => 'required|email',
            'date' => 'required',
            'time' => 'required'
        ));

        $data = new CompanyCallcenter;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->email = $request->input('email');
        $data->phone = $request->input('phone');
        $data->save();

        $appointment = Appointment::create([
            'appointment_at' => $request->input('date').' '.$request->input('time').':00',
            'email' => $request->input('email'),
            'company_id' => $data->id
        ]);

        Alert::success('Bedankt voor uw aanvraag. Wij proberen zo snel mogelijk contact met u op te nemen.')->persistent('Sluiten');
        return Redirect::to('aansluiten/callmeback');
    }

    public function create()
    {        
        return view('admin/callcenter/create', [
            'users' => $this->users,
            'slugController' => $this->slugController,
            'section' => $this->section,
            'currentPage' => 'Nieuw bedrijf'
        ]);
    }

    public function createAction(Request $request)
    {
        $this->validate($request, array(
            'name' => 'required|unique:companies_callcenter',
            'email' => 'email',
            'contact_name' => 'required',
            'contact_email' => 'required|email',
            'financial_email' => 'email'
        ));

        $data = new CompanyCallcenter;
        $data->slug = str_slug($request->input('name'));
        $data->name = $request->input('name');
        $data->user_id = $request->input('owner');
        $data->contact_name  = $request->input('contact_name');
        $data->contact_email = $request->input('contact_email');
        $data->contact_phone = $request->input('contact_phone');
        $data->contact_role  = $request->input('contact_role');
        $data->comment = $request->input('comment');
        $data->email = $request->input('email');
        $data->phone = $request->input('phone');
        $data->address = $request->input('address');
        $data->zipcode = $request->input('zipcode');
        $data->city = $request->input('city');
        $data->regio = $request->input('regio');
        $data->financial_iban = $request->input('financial_iban');
        $data->financial_iban_tnv  = $request->input('financial_iban_tnv');
        $data->financial_email = $request->input('financial_email');
        $data->kvk = $request->input('kvk');
        $data->btw = $request->input('btw');
        $data->facilities = json_encode($request->input('facilities'));
        $data->kitchens = json_encode($request->input('kitchens'));
        $data->allergies = json_encode($request->input('allergies'));
        $data->sustainability = json_encode($request->input('sustainability'));
        $data->kids = json_encode($request->input('kids'));
        $data->price = json_encode($request->input('price'));
        $data->preferences = json_encode($request->input('preferences'));
        $data->discount = json_encode($request->input('discount'));
        $data->save();

        Alert::success('Dit bedrijf is succesvol aangemaakt.')->persistent('Sluiten');
        return Redirect::to('admin/companies/callcenter/update/'.$data->id.'/'.$data->slug);
    }

    public function update($id, $slug)
    {
        $data = CompanyCallcenter::where('slug', '=', $slug)
            ->where('id', '=', $id)
            ->first()
        ;

        if ($data) {
            $mediaItems = $data->getMedia('default');
            $documentItems = $data->getMedia('documents');
            $logoItem = $data->getMedia('logo');

            return view('admin/callcenter/update', [
                'users' => $this->users,
                'data' => $data,
                'admin' => Sentinel::inRole('admin'),
                'media' => $mediaItems,
                'documentItems' => $documentItems,
                'logoItem' => $logoItem,
                'slugController' => $this->slugController,
                'section' => $this->section,
                'currentPage' => 'Wijzig bedrijf'
            ]);
        } else {
            alert()->error('', 'Dit bedrijf bestaat helaas niet.')->persistent("Sluiten");
            return Redirect::to('admin/companies/callcenter');
        }
    }

    public function updateAction(Request $request, $id, $slug)
    {
        $data = CompanyCallcenter::where('slug', '=', $slug)
            ->where('id', '=', $id)
            ->first()
        ;

        $next = CompanyCallcenter::where('id', '>', $data->id);

        if ($request->has('city')) {
            $next = $next->where('city', $request->input('city'));
        }

        $next = $next
            ->where('score', 0)
            ->min('slug')
        ;

        $nextId = CompanyCallcenter::where('id', '>', $id);

        if ($request->has('city')) {
            $nextId = $nextId->where('city', $request->input('city'));
        }

        $nextId = $nextId
            ->where('score', 0)
            ->min('id')
        ;

        $startId = CompanyCallcenter::select(
            'id',
            'slug'
        )

            ->where('score', '=', 0)
            ->first()
        ;

        if ($data) {
            $this->validate($request, array(
                'name' => 'required',
                'email' => 'email',
                'contact_email' => 'email',
                'financial_email' => 'email'
            ));

            $data->slug = str_slug($request->input('name'));
            $data->name = $request->input('name');
            $data->comment = $request->input('comment');
            $data->contact_name  = $request->input('contact_name');
            $data->contact_email = $request->input('contact_email');
            $data->contact_phone = $request->input('contact_phone');
            $data->contact_role = $request->input('contact_role');
            $data->email = $request->input('email');
            $data->phone = $request->input('phone');
            $data->address = $request->input('address');
            $data->zipcode = $request->input('zipcode');
            $data->city = $request->input('city');
            $data->regio = $request->input('regio');
            $data->financial_iban = $request->input('financial_iban');
            $data->financial_iban_tnv  = $request->input('financial_iban_tnv');
            $data->financial_email = $request->input('financial_email');
            $data->kvk = $request->input('kvk');
            $data->btw = $request->input('btw');
            $data->score = $request->input('score');

            if (Sentinel::inRole('callcenter')) {
                $data->caller_id = Sentinel::getUser()->id;
            }

            $data->callback_at = $request->input('callback_date').' '.$request->input('callback_time').':00';
            $data->called_at = $request->input('date').' '.$request->input('time').':00';
            $data->facilities = json_encode($request->input('facilities'));
            $data->kitchens = json_encode($request->input('kitchens'));
            $data->allergies = json_encode($request->input('allergies'));
            $data->sustainability = json_encode($request->input('sustainability'));
            $data->kids = json_encode($request->input('kids'));
            $data->price = json_encode($request->input('price'));
            $data->preferences = json_encode($request->input('preferences'));
            $data->discount = json_encode($request->input('discount'));
            $data->save();

            Alert::success('Dit bedrijf is succesvol aangepast.')->persistent('Sluiten');

            if (isset($nextId)) {
                return Redirect::to('admin/companies/callcenter/update/'.$nextId.'/'.$next.'/'.($request->has('city') ? '?city='.$request->input('city') : ''));
            } else {
                return Redirect::to('admin/companies/callcenter/update/'.$startId->id.'/'.$startId->slug.'/'.($request->has('city') ? '?city='.$request->input('city') : ''));
            }

        } else {
            alert()->error('', 'Dit bedrijf bestaat helaas niet.')->persistent("Sluiten");
            return Redirect::to('admin/companies/callcenter');
        }
    }

    public function deleteAction(Request $request)
    {
        $data = CompanyCallcenter::whereIn('id', $request->input('id'));

        switch ($request->input('action')) {
            case 'remove':
                $data->update(array(
                    'no_show' => 1
                ));

                Alert::success('', 'De gekozen selectie is succesvol verwijderd.')->persistent("Sluiten");
            break;

            case 'win':
                $data->update(array(
                    'score' => 1
                ));

                Alert::success('', 'De gekozen selectie is succesvol gezet als WIN.')->persistent("Sluiten");
            break;

            case 'lose':
                $data->update(array(
                    'score' => 2
                ));

                Alert::success('', 'De gekozen selectie is succesvol gezet als LOSE.')->persistent("Sluiten");
            break;

            case 'favorite':
                $data->update(array(
                    'caller_id' => Sentinel::getUser()->id
                ));

                Alert::success('', 'De gekozen selectie is succesvol toegevoegd als favoriet.')->persistent("Sluiten");
            break;

            case 'unfavorite':
                $data->update(array(
                    'caller_id' => 0
                ));

                Alert::success('', 'De gekozen selectie is succesvol verwijderd als favoriet.')->persistent("Sluiten");
            break;
        }

        return Redirect::to('admin/companies/callcenter');
    }

    public function export(Request $request)
    {
        $data = CompanyCallcenter::select(
            'companies_callcenter.name',
            'companies_callcenter.email',
            'companies_callcenter.phone',
            'companies_callcenter.address',
            'companies_callcenter.zipcode',
            'companies_callcenter.city',
            'companies_callcenter.contact_name',
            'companies_callcenter.contact_email',
            'companies_callcenter.contact_phone',
            'companies_callcenter.kvk',
            'companies_callcenter.btw'
        )
            ->get()
        ;

        $excel = App::make('excel');
        $excel
            ->create('export-callcenter-companies', function($excel) use($data) {
                $excel->sheet('Bedrijven UwVoordeelpas', function($sheet) use($data) {
                    $sheet->fromArray($data);
                });
            })
            ->export('csv')
        ;
    }

    public function import()
    {
        return view('admin/callcenter/import', [
            'section' => $this->section, 
            'slugController' => $this->slugController,
            'currentPage' => 'Importeer'
        ]);
    }

    public function importAction(Request $request)
    {
        $companies = CompanyCallcenter::select(
            'name'
        )
            ->get()
        ;

        $names = $companies
            ->map(function ($company) {
                return $company->name;
            })
            ->toArray()
        ;

        $excel = App::make('excel');
        $excel
            ->load($request->file('csv')->getRealPath(), function($reader) use($names) {
                $reader->each(function($sheet) use($names) {
                    $sheet->each(function($row, $keys) use($names) {
                        $data = new CompanyCallcenter();

                        if (!in_array($row->name, $names) && trim($row->name) != '') {
                            $data->slug = str_slug($row->name);
                            $data->name = $row->name;
                            $data->contact_name = $row->contact_name;
                            $data->contact_email = $row->contact_email;
                            $data->contact_phone = $row->contact_phone;
                            $data->contact_role = $row->contact_role;
                            $data->email = $row->email;
                            $data->phone = $row->phone;
                            $data->address = $row->address;
                            $data->zipcode = $row->zipcode;
                            $data->city = $row->city;
                            $data->kvk = $row->kvk;
                            $data->btw = $row->btw;
                            $data->save();
                        }
                    });
                });
            })
        ;

        alert()->success('', 'Uw opdracht is succesvol uitgevoerd.')->persistent('Sluiten');

        return Redirect::to('admin/companies/callcenter');
    }

    public function favorite($slug)
    {
        $data = CompanyCallcenter::where('slug','=', $slug)
            ->whereNull('user_id')
            ->first()
        ;

        if ($data) {

            if ($data->caller_id == 0) {
                alert()->success('', 'Uw opgegeven bedrijf is succesvol opgeslagen in uw persoonlijke lijst.')->persistent('Sluiten');
            } else {
                alert()->success('', 'Uw opgegeven bedrijf is succesvol uit uw persoonlijke lijst gehaald.')->persistent('Sluiten');
            }

            $data->caller_id =  $data->caller_id != 0 ? 0 : Sentinel::getUser()->id;
            $data->save();

            return Redirect::to('admin/companies/callcenter');
        } else {
            alert()->error('', 'Uw opgegeven bedrijf is niet gevonden.')->persistent('Sluiten');

            return Redirect::to('admin/companies/callcenter');
        }
    }

    public function contract($id, $slug)
    {
        $data = CompanyCallcenter::find($id);

        if (count($data) == 1) {
            $company = new CompanyCallcenter();
            return $company->createContract($id);
        } else {
            Alert::error('Dit bedrijf bestaat helaas niet of u heeft niet genoeg rechten.')->persistent("Sluiten");
            return Redirect::to('/');
        }
    }

}
