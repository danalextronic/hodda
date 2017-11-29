<?php

namespace App\Console\Commands\Other;

use Illuminate\Console\Command;
use App;
use App\Models\Page;
use App\Models\Company;
use App\Models\News;
use Exception;
use URL;
use Setting;
use Mail;

class Sitemap extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */ 
    protected $signature = 'sitemap:other';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Sitemap';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->pages = Page::select(
            'slug',
            'updated_at'
        )
            ->where('is_hidden', 0)
            ->get()
        ;

        $this->companies = Company::select(
            'slug',
            'updated_at'
        )
            ->where('no_show', 0)
            ->get()
        ;
    }

    public function generateSitemap()
    {
        $sitemap = App::make('sitemap');
        $sitemap->add(URL::to('/'), '2012-08-25T20:10:00+02:00', '1.0', 'daily');
        $sitemap->add(URL::to('faq'), '2012-08-25T20:10:00+02:00', '0.9', 'monthly');
                    
        if (count($this->pages) >= 1) {
            foreach ($this->pages as $page) {
                $sitemap->add(URL::to($page->slug), $page->updated_at, '0.4', 'monthly');
            }
        }     

        if (count($this->companies) >= 1) {
            foreach ($this->companies as $company) {
                $sitemap->add(URL::to($company->slug), $company->updated_at, '0.4', 'monthly');
            }
        }

        // generate your sitemap (format, filename)
        $sitemap->store('xml', 'sitemap', base_path());
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'sitemap_other';

     
            if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
                // Start cronjob
                $this->line(' Start '.$this->signature);
                Setting::set('cronjobs.active.'.$commandName, 1);
                Setting::save();

                // Processing
                try {
                    $this->generateSitemap(); 
                } catch (Exception $e) {
                    $this->line('Er is een fout opgetreden. '.$this->signature);
                   
                    Mail::raw('Er is een fout opgetreden:<br /><br /> '.$e, function ($message) {
                        $message->to(getenv('DEVELOPER_EMAIL'))->subject('Fout opgetreden: '.$this->signature);
                    });
                } 
                // End cronjob
                $this->line('Finished '.$this->signature);
                Setting::set('cronjobs.active.'.$commandName, 0);
                Setting::save();
            } else {
                // Don't run a task mutiple times, when the first task hasnt been finished
                $this->line('This task is busy at the moment.');
            }    
    }

}
