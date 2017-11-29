<?php
namespace App\Helpers;

class StrHelper
{

    public static function stristrArray($haystack, $needle) 
    {
        if ( !is_array( $haystack ) ) {
            return false;
        }

        foreach ( $haystack as $element ) {
            if ( stristr( $element, $needle ) ) {
                return $element;
            }
        }
    }

    public static function addScheme($url, $scheme = 'http://')
    {
        return parse_url($url, PHP_URL_SCHEME) === null ? $scheme . $url : $url;
    }

}