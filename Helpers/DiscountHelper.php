<?php
namespace App\Helpers;

use Config;

class DiscountHelper 
{

	protected $patterns = array(
		'/%days%/',
		'/%discount%/'
	);
		
	public function replaceKeys($discountData, $days, $text, $class)
	{
		if ($discountData != 'null' && $discountData != NULL && $discountData != '[""]') {
			$discount = json_decode($discountData->discount);
			$discountDays = json_decode($discountData->days);

			if (is_array($discount) && isset($discount[0]) && $discount[0] != 'NULL') {
                $daysArray = Config::get('preferences.days');
                
               	if (is_array($discountDays)) {
	               	foreach ($discountDays as $discountDay) {
	                    $day[] = lcfirst(substr($daysArray[$discountDay], 0, 2));
	                }

	                $day = implode(',', $day);
                }

                if (isset($day)) {
					$replacements = array(
						$day,
						$discount[0]
					);

					$replacedText = preg_replace($this->patterns, $replacements, $text);

		    		return '<div class="'.$class.'" data-html=" '.$replacedText.' <a href=\''.url('voordeelpas/buy').'\'>Meer info</a>">
		                   <span class="ribbon">'.$discount[0].'</span>
		            </div>';
		        }
	        }
	    }
	}    

}