<?php
namespace App\Http\Controllers;

use Alert;
use App;
use App\Http\Requests\AccountUpdateRequest;
use App\Http\Requests\BarcodeRequest;
use App\Models\Barcode;
use App\Models\Company;
use App\Models\BarcodeUser;
use App\Models\Payment;
use App\Models\RoleUser;
use App\Models\AccountWeight;
use App\Models\MailTemplate;
use App\User;
use App\Http\Controllers\Controller;
use Config;
use Sentinel;
use Illuminate\Support\Facades\Response;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use URL;
use DB;
use DateTime;
use Mail;
use Redirect;

class AccountController extends Controller 
{

    public function __construct(Request $request)
    {
        setlocale(LC_ALL, 'nl_NL.ISO8859-1');
        setlocale(LC_TIME, 'nl_NL.ISO8859-1');
        setlocale(LC_TIME, 'Dutch');

        $this->queryString = $request->query();

        if (isset($this->queryString['type'])) {
            unset($this->queryString['type']);
        }

        unset($this->queryString['limit']);
    }

    public function settings() 
    {
        return view('account/settings');
    }

    public function settingsAction(AccountUpdateRequest $request) 
    {
        $this->validate($request, []);

        $user = Sentinel::getUser();
        $user->name = $request->input('name');
        $user->phone = $request->input('phone');
        $user->gender = $request->input('gender');
        $user->birthday_at = $request->input('birthday_at');

        if ($request->input('email') != Sentinel::getUser()->email) {
            $code = str_random(10);
            $user->new_email = $request->input('email');
            $user->new_email_code  = $code;

            Mail::send('emails.reset-email', ['user' => $user, 'code' => $code], function($message) use ($user, $request) {
                $message->to($request->input('email'))->subject('Nieuw e-mailadres');
            });

            $request->session()->flash('success_email_message', 'Er is een mail gestuurd naar uw nieuwe e-mailadres om uw e-mailadres te activeren.');
        }
                
        if ($request->has('password')) {
            Sentinel::update($user, array('password' => $request->input('password')));
        }
                        
        $user->save();
        
        Alert::success('Uw gegevens zijn succesvol gewijzigd.')->persistent('Sluiten');

        return Redirect::to('account');
    }

    public function barcodes() 
    {
        $data = Barcode::select(
            'barcodes.id',
            'barcodes_users.user_id',
            'barcodes.expire_date',
            'barcodes.code',
            'barcodes_users.is_active',
            'barcodes_users.created_at as activatedOn',
            'barcodes.created_at',
            'users.name',
            'users.phone',
            'users.email',
            'companies.name as companyName'
        )
            ->leftJoin('barcodes_users', 'barcodes.id', '=', 'barcodes_users.barcode_id')
            ->leftJoin('users', 'barcodes_users.user_id', '=', 'users.id')
            ->leftJoin('companies', 'companies.id', '=', 'barcodes.company_id')
            ->where('barcodes_users.user_id', Sentinel::getUser()->id)
            ->get()
        ;

        return view('account/barcodes', [         
        	'data' => $data
        ]);
    }

    public function barcodeAction(BarcodeRequest $request) 
    {
    	$user = Sentinel::getUser();
    	$this->validate($request, []);

		if(Sentinel::inRole('barcode_user') == FALSE) {
			$role = Sentinel::findRoleByName('Barcode');
			$role->users()->attach($user);
		}

		$barcodeInfo = Barcode::where('code', $request->input('code'))
            ->where('is_active', 1)
            ->first()
        ;
	   
       if (count($barcodeInfo) == 1) {
            $barcode = new BarcodeUser;
            $barcode->barcode_id  = $barcodeInfo->id;
            $barcode->user_id = Sentinel::getUser()->id;
            $barcode->code = $request->input('code');
            $barcode->company_id = $barcodeInfo->company_id;
            $barcode->is_active = 1;
            $barcode->save();
		
            $request->session()->flash('success_message', 'Uw opgegeven barcode is succesvol ingevoerd.');
		}

		return Redirect::to('account/barcodes');
    }

    public function deleteAccount() 
    {
        $user = Sentinel::getUser();

        Reservation::where('user_id', $user->id)->delete();
        User::where('id', $user->id)->delete();
        BarcodeUser::where('user_id', $user->id)->delete();
        Guest::where('user_id', $user->id)->delete();
        RoleUser::where('user_id', $user->id)->delete();
        Review::where('user_id', $user->id)->delete();

        Sentinel::logout();

        return Redirect::to('/');
    }
    
}