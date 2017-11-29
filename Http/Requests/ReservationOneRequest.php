<?php namespace App\Http\Requests;
 
class ReservationOneRequest extends Request {
 
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
		    'company' => 'exists:companies,id',
            'date' => 'required|date',
            'time' => 'required',
            'person' => 'numeric|min:1'
	    ];
	}	

	public function messages()
	{
	    return [
	        'company.exists'    => 'Dit bedrijf bestaat niet.',
	        'date.required'    => 'U heeft geen datum opgegeven.',
	        'date.date'    => 'Deze datum is ongeldig.',
	        'time.required'    => 'U heeft geen tijd opgegeven.',
	        'person.numeric'    => 'Het aantal personen moet numeriek zijn.',
	        'person.min'    => 'Het aantal personen moet minimaal 1 persoon zijn.',
	    ];
	}
}