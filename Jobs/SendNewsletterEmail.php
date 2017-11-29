<?php

namespace App\Jobs;

use App\Jobs\Job;
use App\Models\MailTemplate;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewsletterEmail extends Job implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    protected $infoArray;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($infoArray)
    {
        $this->infoArray = $infoArray;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (count($this->infoArray['guests']) >= 1) {
            foreach ($this->infoArray['guests'] as $key => $guest) {
                $mailtemplateModel = new MailTemplate();
                $mailtemplateModel->sendRawMail(array(
                    'email' => $guest['email'],
                    'company_id' => $this->infoArray['company_id'],
                    'content' => $this->infoArray['content'],
                    'subject' => $this->infoArray['subject']
                ));
            }
        }
    }
}
