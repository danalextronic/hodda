<?php
namespace App\Console\Commands\Reservation;

use App;
use App\Models\Company;
use App\Models\CompanyReservation;
use App\Models\Reservation;
use Exception;
use Storage;
use Mail;
use Illuminate\Console\Command;
use Vsmoraes\Pdf\Pdf;

class Today extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'today:reservation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function getReservations()
    {
        $this->companyReservations = CompanyReservation::select(
                    'companies.name',
                    'company_reservations.id',
                    'company_reservations.date',
                    'company_reservations.start_time',
                    'company_reservations.end_time',
                    'company_reservations.company_id',
                    'company_reservations.available_persons',
                    'company_reservations.closed_at'
                )
                    ->leftJoin('companies', 'companies.id', '=', 'company_reservations.company_id')
                    ->whereNotNull('company_reservations.closed_at')
                    ->whereRaw('DATE_FORMAT(company_reservations.closed_at, "%Y-%m-%d %H:%i") <= "'.date('Y-m-d H:i').'"')
                    ->orderBy('company_reservations.date', 'asc')
                    ->get()
                ;

                foreach ($this->companyReservations as $companyReservation) {
                    $reservationIdArray[$companyReservation->id] = $companyReservation->id;

                    $dateReservations[$companyReservation->company_id][$companyReservation->date][$companyReservation->id] = array(
                        'id' => $companyReservation->id,
                        'name' => $companyReservation->name,
                        'startTime' => $companyReservation->start_time,
                        'endTime' => $companyReservation->end_time,
                        'date' => $companyReservation->date,
                        'companyId' => $companyReservation->company_id,
                        'closed_at' => $companyReservation->closed_at
                    );

                    if (is_object(json_decode($companyReservation->available_persons))) {
                       foreach (json_decode($companyReservation->available_persons) as $key => $value) {
                          $lockedTimes[$companyReservation->company_id][$companyReservation->date][$companyReservation->id][$key] = 0;
                       }
                    }
                }

                if (isset($dateReservations)) {
                    $this->reservations = Reservation::select(
                        'company_reservations.id as companyId',
                        'company_reservations.date',
                        'reservations.id',
                        'reservations.reservation_id',
                        'reservations.date as reservationDate',
                        'reservations.time',
                        'reservations.name',
                        'reservations.email',
                        'reservations.phone',
                        'reservations.allergies',
                        'reservations.preferences',
                        'reservations.persons',
                        'reservations.saldo',
                        'reservations.comment',
                        'reservations.company_id'
                    )
                        ->leftJoin('company_reservations', function($join) {
                            $join

                                ->on('reservations.company_id', '=', 'company_reservations.company_id')
                                ->on('reservations.reservation_id', '=', 'company_reservations.id')
                            ;
                        })
                        ->where('reservations.is_cancelled', '=', 0)
                        ->whereIn('reservations.status', array('reserved', 'present'))
                        ->whereIn('reservations.reservation_id', array_keys($reservationIdArray))
                        ->get()
                    ;

                    foreach ($this->reservations as $key => $reservation) {
                        $dateReservations[$reservation->company_id][$reservation->date][$reservation->companyId]['reservations'][] = array(
                            'id' => $reservation->id,
                            'date' => $reservation->reservationDate,
                            'time' => $reservation->time,
                            'name' => $reservation->name,
                            'email' => $reservation->email,
                            'saldo' => $reservation->saldo,
                            'phone' => $reservation->phone,
                            'preferences' => $reservation->preferences,
                            'allergies' => $reservation->allergies,
                            'persons' => $reservation->persons
                        );
                    }

                    foreach ($dateReservations as $dateReservationCompanyId => $dateReservation) {

                        foreach ($dateReservation as $dateReservationDateKey => $dateReservationDate) {

                            foreach ($dateReservationDate as $key => $value) {
                                if (isset($dateReservations[$dateReservationCompanyId][$dateReservationDateKey][$key]['reservations'])) {
                                    $date = $dateReservationDate[$key]['date'];
                                    $name = $dateReservationDate[$key]['name'];
                                    $closed_at = date('Y-m-d', strtotime($dateReservationDate[$key]['date']));

                                    $company = Company::find($dateReservationCompanyId);
                                    $meta = $company->getMeta('today_reservation', array()); 

                                    if (!in_array($date, $meta)) {
                                        # Set 0 as available persons
                                        if (isset($lockedTimes[$dateReservationCompanyId][$dateReservationDateKey])) {
                                            foreach ($lockedTimes[$dateReservationCompanyId][$dateReservationDateKey] as $key => $jsonPersons) {
                                                $updatePersons = CompanyReservation::find($key);
                                                $updatePersons->available_persons = json_encode($jsonPersons);
                                                $updatePersons->save();
                                            }
                                        }
                                        
                                        $options = array(
                                            'name' => $name,
                                            'date' => $date,
                                            'reservations' => $dateReservationDate
                                        );

                                        #  Create pdf file
                                        $html = view('template.pdf.reservations', $options)->render();
                                        
                                        $this->pdf = App::make('Vsmoraes\Pdf\Pdf');
                                        $this->pdf = $this->pdf->load($html)->output();

                                        # Send mail to admin
                                        Mail::send(
                                            'emails.commands.send-reservations', 
                                            $options, 
                                            function ($message) use($date, $name) {
                                                $message
                                                    ->attachData($this->pdf, 'reserveringen-'.str_slug($name).$date.'.pdf')
                                                    ->subject('Reserveringen - '.$name)
                                                    ->from('noreply@uwvoordeelpas.nl', 'UwVoordeelpas')
                                                    ->to(getenv('ADMIN_EMAIL'), 'UwVoordeelpas')
                                                ;
                                            }
                                        );
                                    }

                                    if (count($meta) == 0) {
                                        $company->addMeta('today_reservation', array($closed_at));
                                    } else {
                                        $company->appendMeta('today_reservation', array($closed_at)); 
                                    }
                                }
                            }
                        }
                    }
                }
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */

    public function handle()
    {
        $commandName = 'today_reservation';

        if (Setting::get('cronjobs.'.$commandName) == NULL) {
            echo 'This command is not working right now. Please activate this command.';
        } else {
            if (Setting::get('cronjobs.active.'.$commandName) == NULL OR Setting::get('cronjobs.active.'.$commandName) == 0) {
                // Start cronjob
                $this->line(' Start '.$this->signature);
                Setting::set('cronjobs.active.'.$commandName, 1);
                Setting::save();

                // Processing
                try {
                    $this->getReservations(); 
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
}