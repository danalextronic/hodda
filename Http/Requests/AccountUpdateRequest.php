<?php namespace App\Http\Requests;

use Sentinel;

class AccountUpdateRequest extends Request {
 
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
		    'name'     => 'required',
			'password' => 'min:8|confirmed',
		 	'gender'   => 'required',
			'phone'    => 'required',
			'email'    => 'required|email|unique:users,email,'.Sentinel::getUser()->id,
		];
	}	

	public function messages()
	{
	    return [
	        'name.required'     => 'U bent vergeten om een naam in te vullen.',

	        'password.min' => 'Uw wachtwoord is te kort (minimaal 8 tekens)',
	        'password.confirmed' => 'Uw wachtwoorden komen niet met elkaar overeen.',

	        'gender.required'   => 'U bent vergeten om een aanhef in te vullen.',

	        'email.required'    => 'U bent vergeten om een e-mailadres in te vullen.',
	        'email.email'       => 'Uw opgegeven e-mailadres is ongeldig.',
	        'email.unique'      => 'Dit e-mailadres is al in gebruik.',

	        'phone.required'    => 'U bent vergeten om een telefoonnummer in te vullen.',
	    ];
	}
}