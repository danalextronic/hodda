<?php namespace App\Http\Requests;

class DiscountBuyRequest extends Request 
{
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
			'terms' => 'accepted', 
	 	];
	}	

	public function messages()
	{
	    return [
	        'terms.accepted' => 'U bent niet akkoord gegaan met de voorwaarden.',
	    ];
	}
}