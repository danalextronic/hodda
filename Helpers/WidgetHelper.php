<?php
namespace App\Helpers;

use App\Models\AccountWeight;
use App\Models\AccountRepMax;
use App\Models\UserDocument;
use App\User;
use Sentinel;

class WidgetHelper 
{

    public function userPageOne($pageId) 
    {
        $weight = AccountWeight::where('user_id', Sentinel::getUser()->id)->first();

        return view('account/external/weight', array(
            'weightRows' => $weight ? count(json_decode($weight->weight_json, true)) : 20,
            'weightArray' => $weight ? json_decode($weight->weight_json, true) : array(),
            'startingIndex' => $weight ? count(json_decode($weight->weight_json)) : 19
        ))
        	->render()
        ;
    }

    public function userPageTwo($pageId) 
    {
        $weight = AccountRepMax::where('user_id', Sentinel::getUser()->id)->first();

        return view('account/external/repmax', array(
            'weightRows' => $weight ? count(json_decode($weight->weight_json, true)) : 5,
            'weightArray' => $weight ? json_decode($weight->weight_json, true) : array(),
            'startingIndex' => $weight ? count(json_decode($weight->weight_json)) : 4
        ))
            ->render()
        ;
    }

    public function userPageThree($pageId) 
    {
        $userMedia = User::where('id', Sentinel::getUser()->id)->with('media')->first();

        $mediaOne = $userMedia->getMedia('group1'); 
        $mediaTwo = $userMedia->getMedia('group2'); 
        $mediaThree = $userMedia->getMedia('group3'); 

        return view('account/external/photos', array(
            'mediaOne' => $mediaOne,
            'mediaTwo' => $mediaTwo,
            'mediaThree' => $mediaThree,
        ))
            ->render()
        ;
    }

    public function userPageFour($pageId) 
    {
        return view('account/external/pagefour', array(
        ))
            ->render()
        ;
    }

    public function userPageFive($pageId) 
    {
        return view('account/external/pagefive', array(
        ))
            ->render()
        ;
    }

    public function userPageSix($pageId) 
    {
        $pageMedia = UserDocument::where('page_id', $pageId)->with('media')->first(); 
        $media = $pageMedia ? $pageMedia->getMedia('page-'.$pageId) : array(); 

        return view('account/external/pagesix', array(
            'pageId' => $pageId,
            'media' => $media
        ))
        	->render()
        ;
    }

    public function search($pageId, $content) 
    {
		$content = preg_replace('/%userpage1%/', $this->userPageOne($pageId), $content);
        $content = preg_replace('/%userpage2%/', $this->userPageTwo($pageId), $content);
        $content = preg_replace('/%userpage3%/', $this->userPageThree($pageId), $content);
		$content = preg_replace('/%admin_uploads%/', $this->userPageSix($pageId), $content);

        return $content;
    }

}