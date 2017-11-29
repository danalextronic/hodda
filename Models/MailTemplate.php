<?php
namespace App\Models;

use App\Models\Content;
use App\Models\Reservation;
use Mail;
use Request;
use Config;
use Illuminate\Database\Eloquent\Model;
use Setting;
use Sentinel;
use URL;

class MailTemplate extends Model
{
    
    protected $table = 'mail_templates';

    protected $commands = array(
        '/%name%/i',
        '/%cname%/i',
        '/%phone%/i',
        '/%email%/i',
        '/%date%/i',
        '/%time%/i',
        '/%comment%/i',
        '/%url%/i',
        '/%invoicenumber%/i',
        '/%euro%/i',
        '/%discount%/i',
        '/%days%/i',
        '/%discount_comment%/i',
        '/%randomPassword%/i',
        '/%randompassword%/i'
    );

    protected $alternative = array(
        '/%name/i' => '%name%',
        '/name%/i' => '%name%',
        '/%nam/i' => '%name%',
        '/nam%/i' => '%name%',
        '/phone%/i' => '%phone%',
        '/%phone/i' => '%phone%',
        '/phone%/i' => '%phone%',
        '/%phon%/i' => '%phone%',
        '/%phon/i' => '%phone%',
        '/phon%/i' => '%phone%',
        '/%pho%/i' => '%phone%',
        '/%email/i' => '%email%',
        '/email%/i' => '%email%',
        '/%emai/i' => '%email%',
        '/emai%/i' => '%email%',
    );

    public static function replaceWrongCommands($text)
    {
        $model = new MailTemplate;

        foreach ($model->alternative as $key => $value) {
            $text = preg_replace($key, $value, $text);
        }

        return $text;
    }

    public static function createMailTemplates($companyIds) 
    {
        $mailTemplates = Config::get('preferences.mail_templates');
        $mailTemplatesContentBlock = Config::get('preferences.mail_templates_contentBlocks');
        $contentBlock = Content::getMailTemplate();

        if (count($mailTemplates) >= 1) {
            foreach ($companyIds as $company) {
                foreach ($mailTemplatesContentBlock as $mailId => $template) {
                    if (isset($contentBlock[$template]['title'])) {
                        $mailTemplateArray[] = array(
                            'company_id' => $company,
                            'created_at' => date('Y-m-d H:i:s'),
                            'category' => $mailId,
                            'subject' => isset($contentBlock[$template]) ? $contentBlock[$template]['title'] : '',
                            'content' => isset($contentBlock[$template]) ? $contentBlock[$template]['content'] : '',
                        );
                    }
                }
            }

            if (isset($mailTemplateArray)) {
                MailTemplate::insert($mailTemplateArray);
            }
        }
    }

    public static function getTemplate($category, $company_id = null) 
    {
        $blocks = static::where('mail_templates.category', '=', $category)
            ->leftJoin('companies', 'mail_templates.company_id', '=', 'companies.id')
            ->where('mail_templates.is_active', 0)
        ;

        if ($company_id != null) {
            $blocks = $blocks->where('mail_templates.company_id', $company_id);
        }

        $blocks = $blocks->get();

        if ($blocks->count() >= 1) {
            foreach ($blocks as $block) {
                $template = array(
                    'content' => $block->content, 
                    'subject' => $block->subject,
                    'info' => array(
                        'slug' => $block->slug,
                        'name' => $block->name,
                        'email' => $block->email,
                        'phone' => $block->phone,
                        'address' => $block->address,
                        'zipcode' => $block->zipcode,
                        'city' => $block->city
                    )
                );

                return $template;
            }
        }
    }

    public function viewMail($options)
    {
        extract(array_merge(
            array(
            ),
            $options
        ));
        
        $mailTemplate = MailTemplate::getTemplate($options['template_id'], $options['company_id']);
        
        if (isset($options['reservation_id'])) {
            $reservation = Reservation::find($options['reservation_id']);
        }

        $user = Sentinel::findByCredentials(array(
            'login' => $options['email'] 
        ));

        if (isset($mailTemplate)) {
            // Create auth code for login
            if ($options['template_id'] == 'reminder-review-client' OR $options['template_id'] == 'reminder-reservation-client') {
                // Search user by email
                $temporaryAuth3 = new TemporaryAuth();
                $temporaryAuth4 = new TemporaryAuth();

                if ($user) {
                    $authLinkLike = 'auth/set/'.$temporaryAuth3->createCode($user->id, 'landingpage/'.$mailTemplate['info']['slug']);
                    $authLinkReview = 'auth/set/'.$temporaryAuth4->createCode($user->id, 'restaurant/'.$mailTemplate['info']['slug'].'#reviews');
                } else {
                    $authLinkLike = 'landingpage/'.$mailTemplate['info']['slug'];
                    $authLinkReview = 'restaurant/'.$mailTemplate['info']['slug'].'#reviews';
                }
            } else {
                $temporaryAuth = new TemporaryAuth();
                $temporaryAuth2 = new TemporaryAuth();

                $authLinkEdit = $temporaryAuth->createCode($user->id, 'account#preferences');

                if (isset($options['invoice_url'])) {
                    $authLinkCancel = $temporaryAuth2->createCode($user->id, $options['invoice_url']);
                } else {
                    $authLinkCancel = $temporaryAuth2->createCode($user->id, 'tegoed-sparen');
                }
            }

            if (isset($options['replacements'])) { 
                foreach ($options['replacements'] as $key => $value) {
                    $mailTemplate['content'] = preg_replace('/'.$key.'/i', $value, $mailTemplate['content']);
                    $mailTemplate['subject'] = preg_replace('/'.$key.'/i', $value, $mailTemplate['subject']);
                }
            } else {
                $content = $mailTemplate['content'];
            }

            $data = array(
                'template' => $mailTemplate['content'],
                'templateId' => $options['template_id'],
                'sendEmail' => $options['email'],
                'authLinkEdit' => isset($authLinkEdit) ? $authLinkEdit : '',
                'authLinkCancel' => isset($authLinkCancel) ? $authLinkCancel : '',
                'authLinkLike' => isset($authLinkLike) ? $authLinkLike : '',
                'authLinkReview' => isset($authLinkReview) ? URL::to($authLinkReview) : '',
                'info' => $mailTemplate['info'],
                'reservationId' => isset($options['reservation_id']) ? $options['reservation_id'] : '',
                'reservation' => isset($reservation) ? $reservation : '',
                'manual' => isset($options['manual']) ? $options['manual'] : '',
                'logo' => Company::getLogo($options['company_id'])
            );

            return $data;
        }
    }

    public function sendMail($options)
    {
        extract(array_merge(
            array(
            ),
            $options
        ));
        
        $mailTemplate = MailTemplate::getTemplate($options['template_id'], $options['company_id']);
        
        if (isset($options['reservation_id'])) {
            $reservation = Reservation::find($options['reservation_id']);
        }

        $user = Sentinel::findByCredentials(array(
            'login' => $options['email'] 
        ));

        if (isset($mailTemplate)) {
            // Sources
            $extraParamaters = '?utm_source='.$options['template_id'].'&utm_campaign=uwvoordeelpas&utm_medium=email&utm_content=restaurant_'.$mailTemplate['info']['slug'];

            // Create auth code for login
            if ($options['template_id'] == 'reminder-review-client' OR $options['template_id'] == 'reminder-reservation-client') {
                // Search user by email
                $temporaryAuth3 = new TemporaryAuth();
                $temporaryAuth4 = new TemporaryAuth();

                if ($user) {
                    $authLinkLike = 'auth/set/'.$temporaryAuth3->createCode($user->id, 'landingpage/'.$mailTemplate['info']['slug'].$extraParamaters);
                    $authLinkReview = 'auth/set/'.$temporaryAuth4->createCode($user->id, 'restaurant/'.$mailTemplate['info']['slug'].'#reviews'.$extraParamaters);
                } else {
                    $authLinkLike = 'landingpage/'.$mailTemplate['info']['slug'].$extraParamaters;
                    $authLinkReview = 'restaurant/'.$mailTemplate['info']['slug'].'#reviews'.$extraParamaters;
                }
            } else {
                $temporaryAuth = new TemporaryAuth();
                $temporaryAuth2 = new TemporaryAuth();

                $authLinkEdit = $temporaryAuth->createCode($user->id, 'account#preferences'.$extraParamaters);

                if (isset($options['invoice_url'])) {
                    $authLinkCancel = $temporaryAuth2->createCode($user->id, $options['invoice_url']);
                } else {
                    $authLinkCancel = $temporaryAuth2->createCode($user->id, 'tegoed-sparen'.$extraParamaters);
                }
            }

            if (isset($options['replacements'])) { 
                foreach ($options['replacements'] as $key => $value) {
                    $mailTemplate['content'] = preg_replace('/'.$key.'/i', $value, $mailTemplate['content']);
                    $mailTemplate['subject'] = preg_replace('/'.$key.'/i', $value, $mailTemplate['subject']);
                }
            } else {
                $content = $mailTemplate['content'];
            }

            $data = array(
                'extraParamaters' => $extraParamaters,
                'template' => $mailTemplate['content'],
                'templateId' => $options['template_id'],
                'sendEmail' => $options['email'],
                'authLinkEdit' => isset($authLinkEdit) ? $authLinkEdit : '',
                'authLinkCancel' => isset($authLinkCancel) ? $authLinkCancel : '',
                'authLinkLike' => isset($authLinkLike) ? $authLinkLike : '',
                'authLinkReview' => isset($authLinkReview) ? URL::to($authLinkReview) : '',
                'info' => $mailTemplate['info'],
                'reservationId' => isset($options['reservation_id']) ? $options['reservation_id'] : '',
                'reservation' => isset($reservation) ? $reservation : '',
                'manual' => isset($options['manual']) ? $options['manual'] : '',
                'logo' => Company::getLogo($options['company_id'])
            );

            if (trim($options['email']) != '' && isset($options['email'])) {
                try {
                    Mail::send(
                        'emails.mailtemplate', 
                        $data, 
                        function ($message) use($options, $mailTemplate) {
                            if (isset($options['attach'])) {
                                $message
                                    ->attachData(
                                        $options['attach']['data'], 
                                        $options['attach']['name']
                                    )
                                ;

                            }

                            $message
                                ->to($options['email'])
                                ->subject($mailTemplate['subject'])
                            ;
                        }
                    );
                } catch (\Swift_RfcComplianceException $e) {
                }
            }
        }
    }

    public function sendMailSite($options)
    {
        extract(array_merge(
            array(
                'noreply@uwvoordeelpas.nl'
            ),
            $options
        ));
            
        $allSettings = Setting::all(); 
    
        $mailTemplate = array(
            'welcome' => array(
                'subject' => isset($allSettings['welcome_mail_title']) ? $allSettings['welcome_mail_title'] : '',
                'content' => isset($allSettings['welcome_mail_content']) ? $allSettings['welcome_mail_content'] : ''
            ),
            'register_naturel' => array(
                'subject' => isset($allSettings['register_naturel_title']) ? $allSettings['register_naturel_title'] : '',
                'content' => isset($allSettings['register_naturel_content']) ? $allSettings['register_naturel_content'] : ''
            ),
            'register' => array(
                'subject' => isset($allSettings['register_title']) ? $allSettings['register_title'] : '',
                'content' => isset($allSettings['register_content']) ? $allSettings['register_content'] : ''
            ),
            'new_company' => array(
                'subject' => isset($allSettings['new_company_title']) ? $allSettings['new_company_title'] : '',
                'content' => isset($allSettings['new_company_content']) ? $allSettings['new_company_content'] : ''
            ),
            'forgot_password' => array(
                'subject' => isset($allSettings['forgot_password_title']) ? $allSettings['forgot_password_title'] : '',
                'content' => isset($allSettings['forgot_password_content']) ? $allSettings['forgot_password_content'] : ''
            ),
            'saldo_change' => array(
                'subject' => isset($allSettings['saldo_change_title']) ? $allSettings['saldo_change_title'] : '',
                'content' => isset($allSettings['saldo_change_content']) ? $allSettings['saldo_change_content'] : ''
            ),
            'saldo_charge' => array(
                'subject' => isset($allSettings['saldo_charge_title']) ? $allSettings['saldo_charge_title'] : '',
                'content' => isset($allSettings['saldo_charge_content']) ? $allSettings['saldo_charge_content'] : ''
            ),
            'transaction_accepted' => array(
                'subject' => isset($allSettings['transaction_accepted_title']) ? $allSettings['transaction_accepted_title'] : '',
                'content' => isset($allSettings['transaction_accepted_content']) ? $allSettings['transaction_accepted_content'] : ''
            ),
            'transaction_open' => array(
                'subject' => isset($allSettings['transaction_open_title']) ? $allSettings['transaction_open_title'] : '',
                'content' => isset($allSettings['transaction_open_content']) ? $allSettings['transaction_open_content'] : ''
            ),
            'transaction_rejected' => array(
                'subject' => isset($allSettings['transaction_rejected_title']) ? $allSettings['transaction_rejected_title'] : '',
                'content' => isset($allSettings['transaction_rejected_content']) ? $allSettings['transaction_rejected_content'] : ''
            ),
            'appointment_mail' => array(
                'subject' => isset($allSettings['callcenter_mail_title']) ? $allSettings['callcenter_mail_title'] : '',
                'content' => isset($allSettings['callcenter_mail_content']) ? $allSettings['callcenter_mail_content'] : ''
            ),
            'appointment_info_mail' => array(
                'subject' => isset($allSettings['callcenter_info_mail_title']) ? $allSettings['callcenter_info_mail_title'] : '',
                'content' => isset($allSettings['callcenter_info_mail_content']) ? $allSettings['callcenter_info_mail_content'] : ''
            ),
            'appointment_reminder_mail' => array(
                'subject' => isset($allSettings['callcenter_reminder_title']) ? $allSettings['callcenter_reminder_title'] : '',
                'content' => isset($allSettings['callcenter_reminder_content']) ? $allSettings['callcenter_reminder_content'] : ''
            )
        );

        if (isset($mailTemplate[$options['template_id']])) {
            $extraParamaters = '?utm_source='.$options['template_id'].'&utm_campaign=uwvoordeelpas&utm_medium=email&utm_content=uwvoordeelpas';

            if (isset($options['replacements'])) { 
                foreach ($options['replacements'] as $key => $value) {
                    $mailTemplate[$options['template_id']]['content'] = preg_replace('/'.$key.'/i', $value, $mailTemplate[$options['template_id']]['content']);
                    $mailTemplate[$options['template_id']]['subject'] = preg_replace('/'.$key.'/i', $value, $mailTemplate[$options['template_id']]['subject']);
                }   
            }

            $user = Sentinel::findByCredentials(array(
                'login' => $options['email'] 
            ));

            $temporaryAuth = new TemporaryAuth();
            $temporaryAuth2 = new TemporaryAuth();

            $authLinkEdit = $temporaryAuth->createCode($user->id, 'account#preferences');
            $authLinkCancel = $temporaryAuth2->createCode($user->id, 'tegoed-sparen');

            $data = array(
                'extraParamaters' => $extraParamaters,
                'template' => $mailTemplate[$options['template_id']]['content'],
                'templateId' => $options['template_id'],
                'logo' => '',
                'authLinkEdit' => isset($authLinkEdit) ? $authLinkEdit : '',
                'authLinkCancel' => isset($authLinkCancel) ? $authLinkCancel : '',
            );

            if (trim($options['email']) != '' && isset($options['email'])) {

                try {
                    Mail::send(
                        'emails.mailtemplate', 
                        $data, 
                        function ($message) use($options, $mailTemplate) {
                            if (isset($options['attach'])) {
                                $message
                                    ->attachData(
                                        $options['attach']['data'], 
                                        $options['attach']['name']
                                    )
                                ;

                            }

                            $message
                                ->to($options['email'])
                                ->subject($mailTemplate[$options['template_id']]['subject'])
                            ;
                        }
                    );
                } catch (\Swift_RfcComplianceException $e) {
                }
            }
        }
    }

    public function sendRawMail($options)
    {
        extract(array_merge(
            array(
            ),
            $options
        ));
            
        $allSettings = Setting::all(); 

        if (isset($options['company_id'])) {
            $company = Company::find($options['company_id']);
        }

        $data = array(
            'template' => $options['content'],
            'logo' => '',
            'info' => array(
                'slug' => (isset($company) ? $company->slug : ''),
                'address' => (isset($company) ? $company->address : ''),
                'zipcode' => (isset($company) ? $company->zipcode : ''),
                'city' => (isset($company) ? $company->city : ''),
                'phone' => (isset($company) ? $company->phone : ''),
                'name' => (isset($company) ? $company->name : '')
            )
        );

        if (trim($options['email']) != '' && isset($options['email'])) {
            try {
                Mail::send(
                    'emails.newsletter', 
                    $data, 
                    function ($message) use($options) {
                        $message
                            ->to($options['email'])
                            ->subject($options['subject'])
                        ;
                    }
                );
            } catch (\Swift_RfcComplianceException $e) {
            }
        }
    }
}