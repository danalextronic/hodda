<?php namespace App\Http\Requests;
 
use App\Helpers\MoneyHelper;
use App\Models\Company;
use App\User;
use Illuminate\Validation\Factory as ValidationFactory;
use Sentinel;
use URL;
use Illuminate\Support\Facades\Request as RequestFacade;

class ReservationTwoRequest extends Request 
{
 	
 	public function __construct(ValidationFactory $validationFactory)
    {
        $selectedUser = new User();

    	$company = Company::select('min_saldo')->find(RequestFacade::get('company_id'));
    	$companyMinSaldo = $company->min_saldo;

        $user = RequestFacade::has('user_id') ? Sentinel::getUserRepository()->findById(RequestFacade::get('user_id')) : Sentinel::getUser();

        $validationFactory->extend(
            'saldoMin',
            function ($attribute, $value, $parameters) use($companyMinSaldo) {
            	return (Sentinel::check() && MoneyHelper::getAmount($value) > 0 ? MoneyHelper::getAmount($companyMinSaldo) <= MoneyHelper::getAmount($value) : 1);
            },
            'Om hier te reserveren moet u een spaartegoed hebben van minimaal &euro;'.$companyMinSaldo.' <a href="'.URL::to('payment/charge?min='.($companyMinSaldo - RequestFacade::get('saldo'))).'" target="_blank">Klik hier om uw saldo op te waarderen</a>'
        );
        
        $validationFactory->extend(
            'saldo',
            function ($attribute, $value, $parameters) use($user, $selectedUser) {
            	return (Sentinel::check() ? MoneyHelper::getAmount($selectedUser->getSaldo($user->id)) >= MoneyHelper::getAmount($value) : 1);
            },
            RequestFacade::has('user_id') ?
            'Deze gebruiker heeft het opgegeven saldo niet in bezit.' : 'U heeft het opgegeven spaartegoed niet in bezit.'
        );

        $validationFactory->extend(
            'saldoMax',
            function ($attribute, $value, $parameters) {
            	return (Sentinel::check() ? MoneyHelper::getAmount($value) <= 150 : 1);
            },
            'Het is alleen toegestaan om met een bedrag tot &euro;150 te reserveren.'
        );
    }

 
	/**
	 * Determine if the user is authorized to make this request.
	 *
	 * @return bool
	 */
	public function authorize()
	{
		return true;
	}
 
	/**
	 * Get the validation rules that apply to the request.
	 *
	 * @return array
	 */
	public function rules()
	{
	    $rules = [
           	'company_id' => 'required',
           	'name' => 'required',
            'date' => 'required',
            'email' => 'required|email',
            'time' => 'required',
            'phone' => 'required|min:10',
            'saldo' => (!RequestFacade::has('company_page') ? 'saldoMin|saldoMax|'.(RequestFacade::get('old_saldo') != RequestFacade::get('saldo') && RequestFacade::get('saldo') > RequestFacade::get('old_saldo') ? 'saldo' : '') : ''),
	    ];

        if (!RequestFacade::has('group_reservation')) {
            $rules['av'] = 'accepted';
        }

    	return $rules;
	}	

	public function messages()
	{
	    return [
	        'name.required' => 'U bent vergeten om een naam in te vullen.',
	        'password.required' => 'U bent vergeten om een wachtwoord in te vullen.',
	        'password.min' => 'Uw wachtwoord is te kort (minimaal 8 tekens)',
	        'password.confirmed' => 'Uw wachtwoorden komen niet met elkaar overeen.',
	        'gender.required' => 'U bent vergeten om een aanhef in te vullen.',
	        'email.required' => 'U bent vergeten om een e-mailadres in te vullen.',
	        'email.email' => 'Uw opgegeven e-mailadres is ongeldig.',
	        'email.unique' => 'Dit e-mailadres is al in gebruik.',
            'phone.required' => 'U bent vergeten om een telefoonnummer in te vullen.',
	        'phone.min' => 'Uw telefoonnummer is te kort (minimaal 10 cijfers).',
	        'av.accepted' => 'U bent vergeten om de Algemene Voorwaarden te accepteren.',
	        'saldo.required' => 'U heeft geen saldo bedrag ingevoerd.',
	        'saldo.max' => 'U kunt maximaal &euro; 500 uitgeven van uw spaartegoed.'
	    ];
	}
}