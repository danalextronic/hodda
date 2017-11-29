<?php namespace App\Http\Requests;

use Sentinel;

class BarcodeRequest extends Request {
 
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
			'code'     => 'required|exists:barcodes,code|unique:barcodes_users,code', 
	    ];
	}	

	public function messages()
	{
	    return [
	        'code.required' => 'U bent vergeten om een barcode in te vullen.',
	        'code.exists' => 'Deze barcode bestaat niet.',
	        'code.unique' => 'Deze barcode is al in gebruik.'
	    ];
	}
}