<?php namespace App\Http\Requests;
 
class LoginRequest extends Request {
 
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
	    return [
		    'email' => 'required|email',
	        'password' => 'required',
	        '_token' => 'required',
	    ];
	}	

	public function messages()
	{
	    return [
	        'password.required' => 'U bent vergeten om een wachtwoord in te vullen.',
	        'email.required' => 'U bent vergeten om een e-mailadres in te vullen.',
	        'email.email' => 'Uw opgegeven e-mailadres is ongeldig.',
	        '_token.required' => 'Er is iets mis gegaan tijdens het inloggen. Probeert u het opnieuw door het scherm te sluiten en weer te openen.'
	    ];
	}
}