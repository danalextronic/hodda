<?php namespace App\Http\Requests;

use Sentinel;

class RestaurantRequest extends Request {
 
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
		    'name' => 'required|min:2',
            'email' => 'required|email',
            'content' => 'required|min:10',
            'subject' => 'required|min:5',
            'CaptchaCode' => 'required|valid_captcha'
       ];
	}	

	public function messages()
	{
	    return [
	        'name.required' => 'U bent vergeten om een naam in te vullen.',
	        'email.required' => 'U bent vergeten om een email in te vullen.',
	        'email.email' => 'Uw e-mail is ongeldig.',
	        'subject.required' => 'U bent vergeten om een onderwerp in te vullen.',
	        'subject.min' => 'Uw onderwerp moet minimaal 5 tekens bevatten.',
	        'content.required' => 'U bent vergeten om een bericht in te vullen.',
	        'content.min' => 'Uw bericht moet minimaal 10 tekens bevatten.',
	        'g-recaptcha-response.captcha' => 'UW opgegeven beveiligingscode klopt niet.',
	        'g-recaptcha-response.required' => 'U bent vergeten om aan te geven dat u geen robot bent.'
	    ];
	}
}