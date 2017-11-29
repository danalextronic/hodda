<?php namespace App\Http\Requests;
 
use Illuminate\Validation\Factory as ValidationFactory;
use Sentinel;

class AdminCreateReservationRequest extends Request {
 	
 	public function __construct(ValidationFactory $validationFactory)
    {

        $validationFactory->extend(
              'saldo',
            function ($attribute, $value, $parameters) {
            	return (Sentinel::check() ? Sentinel::getUser()->saldo >= $value : 1);
            },
            'U heeft het opgegeven spaartegoed niet in bezit.'
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
           	'name'      => 'required',
            'date'      => 'required',
            'email'     => 'email|unique:users'.(Sentinel::check() ? ',id,'.Sentinel::getUser()->id : ''),
            'time'      => 'required',
	    ];

    	return $rules;
	}	


	public function messages()
	{
	    return [
	        'name.required'     => 'U bent vergeten om een naam in te vullen.',

	        'password.required' => 'U bent vergeten om een wachtwoord in te vullen.',
	        'password.min' => 'Uw wachtwoord is te kort (minimaal 8 tekens)',
	        'password.confirmed' => 'Uw wachtwoorden komen niet met elkaar overeen.',

	        'gender.required'   => 'U bent vergeten om een aanhef in te vullen.',

	        'email.required'    => 'U bent vergeten om een e-mailadres in te vullen.',
	        'email.email'    => 'Uw opgegeven e-mailadres is ongeldig.',
	        'email.unique'    => 'Dit e-mailadres is al in gebruik.',

	        'phone.required'    => 'U bent vergeten om een telefoonnummer in te vullen.',

	        'av.accepted'    => 'U bent vergeten om de Algemene Voorwaarden te accepteren.',
	        'saldo.required'    => 'U heeft geen saldo bedrag ingevoerd.',
	    ];
	}
}