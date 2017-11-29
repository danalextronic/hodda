<?php

namespace App\Console\Commands\Barcode;

use Illuminate\Console\Command;
use App\Models\Barcode;
use App\Models\BarcodeUser;
use Exception;
use Setitng;
use Mail;

class Expired extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'expired:barcode';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove barcodes after 1 year';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->barcodesUser = BarcodeUser::select(
            'id',
            'created_at',
            'is_active'
        )
            ->whereRaw('date(date_add(created_at, interval 1 year)) >= "'.date('Y-m-d').'"')
            ->get()
        ; 

        $this->barcodes = Barcode::select(
            'id',
            'created_at',
            'is_active'
        )
            ->whereRaw('date(date_add(created_at, interval 1 year)) >= "'.date('Y-m-d').'"')
            ->get()
        ; 
    }

    public function getExpiredBarcodes()
    {
        foreach ($this->barcodesUser as $barcodesUser) {
            $barcodesUser->is_active = 0;
            $barcodesUser->save();
        }

        foreach ($this->barcodes as $barcode) {
            $barcode->is_active = 0;
            $barcode->save();
        }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $commandName = 'expired_barcode';

        if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
            // Start cronjob
            $this->line(' Start '.$this->signature);
            Setting::set('cronjobs.active.'.$commandName, 1);
            Setting::save();

            // Processing
            try {
                $this->getExpiredBarcodes(); 
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
