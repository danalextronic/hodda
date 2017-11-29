<?php 
namespace App\Http\Requests\Admin;

use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\MailTemplate;
use Illuminate\Support\Facades\Request as RequestFacade;

class MailtemplateRequest extends FormRequest {
 	
 	public function __construct(ValidationFactory $validationFactory)
    {
        $validationFactory->extend(
        	'categoryExists',
            function ($attribute, $value, $parameters) {
            	$category = MailTemplate::where('category', '=', $value)
					->where('company_id', RequestFacade::get('company_id'))
					->first()
				;

            	if (count($category) == 0) {
            		if ($category->id != RequestFacade::get('ids')) {
            			return $value != $value;
            		}
            	}

	            return $value == $value;
            },
            'Er is al een mail template met dezelfde categorie.'
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
	    return [
		    'subject' => 'required',
		    'content' => 'required',
		    'category' => 'required',
		];
	}	

	public function messages()
	{
	    return [
	        'subject.required' => 'U bent vergeten een onderwerp in te vullen.',
	        'category.required' => 'U bent vergeten een categorie te kiezen.',
	        'content.required' => 'U bent vergeten een bericht in te vullen.'
	    ];
	}
}