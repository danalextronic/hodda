<?php namespace App\Http\Requests;
 
class ReviewRequest extends Request {
 
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
		    'food' => 'min:1,max:5',
            'service' => 'min:1,max:5',
            'decor' => 'min:1,max:5',
            'content' => 'required'
	    ];
	}	

	public function messages()
	{
	    return [
	        'food.min' => 'Het eten moet u minimaal met 1 ster beoordelen.',
	        'food.max' => 'Het eten mag u maar met maximaal 5 sterren beoordelen.',
			'service.min' => 'De service moet u minimaal met 1 ster beoordelen.',
	        'service.max' => 'De service mag u maar met maximaal 5 sterren beoordelen.',
	      	'decor.min' => 'Het decor moet u minimaal met 1 ster beoordelen.',
	        'decor.max' => 'Het decor mag u maar met maximaal 5 sterren beoordelen.',
	        'content.required' => 'U bent vergeten om een review achter te laten.',
	    ];
	}
}