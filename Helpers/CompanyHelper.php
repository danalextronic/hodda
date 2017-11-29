<?php
namespace App\Helpers;

class CompanyHelper 
{

    public function regioArray($regio) 
    {

        $json = json_decode($regio);

        return $json;
    }

}