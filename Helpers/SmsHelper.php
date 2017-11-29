<?php
namespace App\Helpers;

use App\Models\MailTemplate;
use App\Models\Company;
use Exception;
use MessageBird\Exceptions\BalanceException;
use MessageBird\Exceptions\AuthenticateException;
use MessageBird\Client;
use Mail;

class SmsHelper 
{

    protected $commands = array(
        '/%name%/i',
        '/%saldo%/i',
        '/%phone%/i',
        '/%email%/i',
        '/%date%/i',
        '/%persons%/i',
        '/%comment%/i',
        '/%allergies%/i',
        '/%preferences%/i',
    );

 	public static function getTemplate($category, $company_id = null) 
    {
        $blocks = MailTemplate::where(
            'category', $category
        )
            ->where('is_active', 0)
        ;

        if(trim($company_id) != '') {
            $blocks = $blocks->where('company_id', $company_id);
        }

        $blocks = $blocks->get();

        if ($blocks->count() >= 1) {
            foreach ($blocks as $block) {
                $template = array(
                    'content' => $block->content, 
                    'subject' => $block->subject
                );

                return $template;
            }
        }
    }

    public function sendSMS($options) 
    {
        extract(array_merge(
            array(
                'template_id' => 1,
                'company_id' => 1,
            ),
            $options
        ));
        
        $smsTemplate = MailTemplate::getTemplate($options['template_id'], $options['company_id']);
            
        if (isset($smsTemplate)) {
            if (isset($options['replacements'])) { 
                foreach ($options['replacements'] as $key => $value) {
                    if (in_array('/%'.$key.'%/i', $this->commands)) {
                        $replaceCommands[] = '/%'.$key.'%/i';
                    }
                }

                $content = preg_replace(
                    isset($replaceCommands) ? $replaceCommands : $this->commands, 
                    $options['replacements'], 
                    $smsTemplate['content']
                );
            } else {
                $content = $smsTemplate['content'];
            }

            $data = array(
                'template' => $content
            );

            $statusCheck =  getenv('MESSAGEBIRD_ACTIVE') == 0 ? getenv('MESSAGEBIRD_TESTKEY') : getenv('MESSAGEBIRD_PRODKEY');
			
			try {
				$messageBird = new Client($statusCheck);

				$message = new \MessageBird\Objects\Message();
				$message->originator = 'UwVdeelpas';
				$message->recipients = preg_replace('/[^0-9,.]/', '', $options['recipients']);
				$message->body = nl2br(strip_tags($content));

				if (getenv('MESSAGEBIRD_ACTIVE') == 0) {
					echo '<pre>';
					var_dump($messageBird->messages->create($message));
					echo '</pre>';
				} else {
                    $messageBird->messages->create($message);
				}

                $company = Company::find($options['company_id']);
echo 'ss';
                if($company) {
                    echo 'dd';
                  echo  $company->getMeta('sms_message');

                    $company->updateMeta('sms_message'.date('Y-m-d'), array(
                        date('Y-m-d') => +2
                    ));
                }

			} catch (MessageBird\Exceptions\AuthenticateException $e) {

			} catch (MessageBird\Exceptions\BalanceException $e) {
	            Mail::raw('Er is een fout opgetreden: U kunt geen smsjes meer versturen omdat uw saldo te laag is op MessageBird.', function ($message) {
	                $message->to(getenv('ADMIN_EMAIL'))->subject('Fout opgetreden - MessageBird');
	            });
			} catch (Exception $e) {
			    Mail::raw('Er is een fout opgetreden: '.$e->getMessage(), function ($message) {
	                $message->to(getenv('DEVELOPER_EMAIL'))->subject('Fout opgetreden - MessageBird');
	            });
			}
        }
    }
}