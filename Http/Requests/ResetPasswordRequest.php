<?php namespace App\Http\Requests;
 
class ResetPasswordRequest extends Request {
 
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
			'password' => 'required|min:8|confirmed',
	    ];
	}	

	public function messages()
	{
	    return [
	        'password.required' => 'U bent vergeten om een wachtwoord in te vullen.',
	        'password.min' => 'Uw wachtwoord is te kort (minimaal 8 tekens)',
	        'password.confirmed' => 'Uw wachtwoorden komen niet met elkaar overeen.',
	    ];
	}
}