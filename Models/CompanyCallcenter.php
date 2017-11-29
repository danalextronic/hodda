<?php
namespace App\Models;

use App;
use Illuminate\Database\Eloquent\Model;
use Sentinel;
use Spatie\MediaLibrary\HasMedia\HasMediaTrait;
use Spatie\MediaLibrary\HasMedia\Interfaces\HasMediaConversions;
use Cviebrock\EloquentSluggable\SluggableInterface;
use Cviebrock\EloquentSluggable\SluggableTrait;
use Phoenix\EloquentMeta\MetaTrait;
use Carbon\Carbon;

class CompanyCallcenter extends Model implements SluggableInterface, HasMediaConversions
{
    use MetaTrait;
    use SluggableTrait;
    use HasMediaTrait;

    protected $table = 'companies_callcenter';

    protected $sluggable = [
        'build_from' => 'name',
        'save_to' => 'slug',
    ];

    public function registerMediaConversions()
    {
        $this
            ->addMediaConversion('175Thumb')
            ->setManipulations(
                array(
                    'w' => 175, 
                    'h' => 132, 
                    'fit' => 'stretch', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;

        $this
            ->addMediaConversion('mobileThumb')
            ->setManipulations(
                array(
                    'w' => 550, 
                    'h' => 340, 
                    'fit' => 'max', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;
        
        $this
            ->addMediaConversion('450pic')
            ->setManipulations(
                array(
                    'w' => 451, 
                    'h' => 340, 
                    'fit' => 'stretch', 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;

        $this->addMediaConversion('thumb')
            ->setManipulations(
                array(
                    'w' => 368, 
                    'h' => 232, 
                    'format' => 'jpg'
                )
            )
            ->nonQueued()
        ;
    }    

    public static function isCompanyUser($company, $user)
    {
        $checkCompany = static::where('id', '=', $company)
            ->where('user_id', '=', $user)
            ->orWhere('waiter_user_id', '=', $user)
        ;

        if ($checkCompany->count() == 1) {
            return TRUE;
        }

        return FALSE;                             
    }

    public static function isCompanyUserBySlug($slug, $user)
    {
        $checkCompany = static::where('slug', $slug);

        if (Sentinel::inRole('bedrijf')) {
            $checkCompany = $checkCompany
                ->where('user_id', $user)
                ->orWhere('waiter_user_id', $user)
            ;
        }

        $checkCompany = $checkCompany->first();

        if ($checkCompany) {
            return array(
                'is_owner' => 1,
                'regio' => $checkCompany->regio,
                'company_id' => $checkCompany->id
            );
        }

        return FALSE;                             
    }

    public static function getLogo($id)
    {
        $company = static::find($id);

        if (count($company) == 1 && isset($company->getMedia('logo')[0]))  {
            $getLogo = $company->getMedia('logo')[0]->getUrl();
        }

        return isset($getLogo) ? $getLogo : '';                             
    }   

    public function createContract($id)
    {
        setlocale(LC_ALL, 'nl_NL.ISO8859-1');
        setlocale(LC_TIME, 'nl_NL.ISO8859-1');
        setlocale(LC_TIME, 'Dutch');

        $company = CompanyCallcenter::find($id);

        if ($company) {
            $date =  Carbon::create(
                date('Y', strtotime($company->created_at)), 
                date('m', strtotime($company->created_at)),
                date('d', strtotime($company->created_at)), 
                0, 
                0,
                0
            );  

            $html = view('template.contract', array(
                'companyName' => $company->name,
                'companyKVK' => $company->kvk,
                'companyBTW' => $company->btw,
                'companyAddress' => $company->address,
                'companyZipcode' => $company->zipcode,
                'companyCity' => $company->city,
                'contactName' => $company->contact_name,
                'companyEmailReservation' => $company->email,
                'companyEmailAdmin' => $company->contact_email,
                'contactMobile' => $company->contact_phone,
                'contactPhone' => $company->phone,
                'contactRole' => $company->contact_role,
                'companySignature' => $company->signature_url,
                'contactIBAN' => $company->financial_iban,
                'contactIBAName' => $company->financial_iban_tnv,
                'companyDate' => $date->formatLocalized('%d %B %Y')
            ))
                ->render()
            ;

            $this->pdf = App::make('Vsmoraes\Pdf\Pdf');
            $this->pdf = $this->pdf->load($html)->show();
        }
    }

    public static function formatPhoneNumber($number)
    {
        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();

        try {
            if (trim($number) != '') {
                $swissNumberProto = $phoneUtil->parse($number, 'NL');
                return $phoneUtil->format($swissNumberProto, \libphonenumber\PhoneNumberFormat::E164);
            } else {
                return $number;
            }
        } catch (\libphonenumber\NumberParseException $e) {
            var_dump($e);
        }
    }

}