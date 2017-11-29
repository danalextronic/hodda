<?php namespace App\Http\Requests;
 
class ForgotPasswordRequest extends Request {
 
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
			 'email' => 'required|email|exists:users'
	    ];
	}	

	public function messages()
	{
	    return [
	        'email.required'    => 'U bent vergeten om een e-mailadres in te vullen.',
	        'email.email'    => 'Uw opgegeven e-mailadres is ongeldig.',
	        'email.exists'    => 'Dit e-mailadres bestaat niet.',
	    ];
	}
}