<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Jobs\SendNewsletterEmail;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MailTemplate;
use App\Models\NewsletterGuest;
use App\Models\Newsletter;
use App\Models\Guest;
use Sentinel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Redirect;
use Carbon;
use DB;
use URL;

class NewsletterController extends Controller 
{

    public function __construct(Request $request)
    {
        $this->slugController = 'newsletter';
        $this->section = 'Nieuwsbrief';
        $this->limit = $request->input('limit', 15);
    }

    public function index(Request $request)
    {
        $companies = Company::lists('name', 'id');

        if ($request->has('id')) {
            $newsletter = Newsletter::find($request->input('id'));
        }

        if (isset($newsletter) && count($newsletter) == 0) {
            return Redirect::to('admin/newsletter');
        }

        return view('admin/'.$this->slugController.'/index', [
            'newsletter' => isset($newsletter) && count($newsletter) == 1 ? $newsletter : '',
            'companies' => $companies,
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwsbrief'
        ]);
    }

    public function indexAction(Request $request)
    {
        $this->validate($request, [
            'companies' => 'required',
            'title' => 'required|min:3',
            'content' => 'required'
        ]);

        // Add of find newsletter
        $newsletterCheck = Newsletter::where('id', $request->input('id'))->get();

        if ($newsletterCheck->count() == 0) {
            $newsletter = new Newsletter();
        } else {
            $newsletter = Newsletter::find($request->input('id'));
        }
        
        $newsletter->subject = $request->input('title');
        $newsletter->content = $request->input('content');
        $newsletter->companies_ids = json_encode($request->input('companies'));
        $newsletter->save();

        // Add guests
        $guests = Guest::whereIn('company_id', $request->input('companies'))->get();

        foreach ($guests as $guest) {
            $guestsArray[] = array(
                'user_id' => $guest->user_id,
                'company_id' => $guest->company_id,
                'newsletter_id' => $newsletter->id,
                'created_at' => date('Y-m-d H:i:s')
            );
        }

        if (isset($guestsArray)) {
            NewsletterGuest::insert($guestsArray);
        }

        return Redirect::to('admin/newsletter/guests?id='.$newsletter->id);
    }

    public function guests(Request $request)
    {
        $newsletter = Newsletter::find($request->input('id'));

        if ($newsletter) {
            $guestsArray = array();

            if (trim($newsletter->companies_ids) != '') {
                $guestsQuery = NewsletterGuest::select(
                    'newsletters_guests.newsletter_id as newsletterId',
                    'newsletters_guests.no_show as newsletterNoShow',
                    'newsletters_guests.user_id',
                    'companies.id as companyId',
                    'companies.name as companyName',
                    'users.name',
                    'users.email',
                    'users.gender',
                    'users.birthday_at'
                )
                    ->leftJoin('users', 'newsletters_guests.user_id', '=', 'users.id')
                    ->leftJoin('companies', 'companies.id', '=', 'newsletters_guests.company_id')
                    ->where('newsletters_guests.newsletter_id', $request->input('id'))
                    ->whereNotNull('users.email')
                    ->whereNotNull('users.name')
                ;

                if ($request->has('gender')) {
                    $guestsQuery = $guestsQuery->where('users.gender', $request->input('gender'));
                }

                $guestsQuery = $guestsQuery->get();

                foreach ($guestsQuery as $guestsFetch) {
                    if ($guestsFetch->birthday_at != NULL && $guestsFetch->birthday_at != '0000-00-00') {
                        $bdayDate = Carbon\Carbon::createFromDate(
                            date('Y', strtotime($guestsFetch->birthday_at)),
                            date('m', strtotime($guestsFetch->birthday_at)),
                            date('d', strtotime($guestsFetch->birthday_at))
                        );
                    }

                    $guestsArray[] = array(
                        'id' => $guestsFetch->user_id,
                        'name' => $guestsFetch->name,
                        'gender' => $guestsFetch->gender,
                        'no_show' => $guestsFetch->newsletterNoShow,
                        'email' => $guestsFetch->email,
                        'newsletterId' => $guestsFetch->newsletterId,
                        'companyId' => $guestsFetch->companyId,
                        'companyName' => $guestsFetch->companyName,
                        'age' => isset($bdayDate) ? $bdayDate->age : ''
                    );
                }
            }
            
            if ($request->has('sort')) {
                switch ($request->input('order')) {
                    case 'asc':
                        usort($guestsArray, function($a, $b) use($request) {
                            return $a[$request->input('sort')] - $b[$request->input('sort')];
                        });
                    break;

                    case 'desc':
                        usort($guestsArray, function($a, $b) {
                            return $b[$request->input('sort')] - $a[$request->input('sort')];
                        });
                    break;
                }
            }

            $currentPage = ($request->input('page', 1) - 1);

            $guests = new LengthAwarePaginator(
                array_slice(
                    $guestsArray, 
                    $currentPage * $this->limit, 
                    $this->limit
                ),
                count($guestsArray), 
                $this->limit
            );

            $guests->setPath('');

            if ($request->input('page') > $guests->lastPage()) { 
                $lastPageQueryString = json_decode(json_encode($request->query()), true);
                $lastPageQueryString['page'] = $guests->lastPage();

                return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
            }

            $queryString = $request->query();
            unset($queryString['limit']);

            return view('admin/'.$this->slugController.'/guests', [
                'guests' => $guests,
                'queryString' => $queryString,
                'paginationQueryString' => $request->query(),
                'slugController' => $this->slugController,
                'section' => $this->section, 
                'currentPage' => 'Gasten: '.$newsletter->subject
            ]);
        } else {
            alert()->error('', 'De opgegeven nieuwsbrief bestaat niet.')->persistent('Sluiten');
            return Redirect::to('admin/newsletter');
        }
    }

    public function example(Request $request)
    {
        $newsletter = Newsletter::find($request->input('id'));

        if ($newsletter) {
            return view('admin/'.$this->slugController.'/example', [
                'paginationQueryString' => $request->query(),
                'slugController' => $this->slugController,
                'section' => $this->section, 
                'currentPage' => 'Voorbeeld: '.$newsletter->subject
            ]);
        } else {
            alert()->error('', 'De opgegeven nieuwsbrief bestaat niet.')->persistent('Sluiten');
            return Redirect::to('admin/newsletter');
        }
    }

    public function exampleAction(Request $request)
    {
        $newsletter = Newsletter::find($request->input('id'));

        if ($newsletter) {
            $guestsQuery = NewsletterGuest::select(
                    'newsletters_guests.newsletter_id as newsletterId',
                    'newsletters_guests.no_show as newsletterNoShow',
                    'newsletters_guests.user_id',
                    'companies.id as companyId',
                    'companies.name as companyName',
                    'users.name',
                    'users.email',
                    'users.gender',
                    'users.birthday_at'
                )
                ->leftJoin('users', 'newsletters_guests.user_id', '=', 'users.id')
                ->leftJoin('companies', 'companies.id', '=', 'newsletters_guests.company_id')
                ->where('newsletters_guests.no_show', 0)
                ->where('newsletters_guests.newsletter_id', $request->input('id'))
                ->whereNotNull('users.email')
                ->whereNotNull('users.name')
                ->get()
            ;

            foreach ($guestsQuery as $guestsFetch) {
                $guestsArray[] = array(
                    'email' => $guestsFetch->email,
                    'name' => $guestsFetch->name,
                );
            }

            if (isset($guestsArray)) {
                alert()->success('', 'Uw nieuwsbrief staat succesvol in de wachtrij om verstuurd te worden.')->persistent('Sluiten');
                
                $companies = json_decode($newsletter->companies_ids);

                foreach($companies as $company) {
                    $infoArray = array(
                        'company_id' => $company,
                        'subject' => $newsletter->subject,
                        'content' => $newsletter->content,
                        'guests' => $guestsArray
                    );

                    $this->dispatch(new SendNewsletterEmail($infoArray));
                }

            } else {
                alert()->error('', 'U heeft geen gasten om een nieuwsbrief te versturen.')->persistent('Sluiten');
                
                return Redirect::to('admin/newsletter/guests?id='.$request->input('id'));
            }
        } else {
            alert()->error('', 'De opgegeven nieuwsbrief bestaat niet.')->persistent('Sluiten');
            return Redirect::to('admin/newsletter');
        }
    }

}