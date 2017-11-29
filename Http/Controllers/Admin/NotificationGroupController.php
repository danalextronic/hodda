<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\NotificationGroup;
use App\Models\Notification;
use Illuminate\Http\Request;
use Redirect;
use DB;

class NotificationGroupController extends Controller 
{

    public function __construct(Request $request)
    {
        $this->slugController = 'notifications/groups';
        $this->section = 'Notificaties groepen';
        $this->limit = $request->input('limit', 15);
    }

    public function index(Request $request) 
    {
        $notifications = new NotificationGroup();

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            $notifications = $notifications->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $notifications = $notifications->orderBy('created_at', 'asc');
        }

        $notifications = $notifications->paginate($this->limit);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $notifications->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $notifications->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);

        return view('admin.notifications.groups.index', array(
            'notifications' => $notifications,        
            'queryString'=> $queryString,
            'slugController' => $this->slugController,
            'paginationQueryString' => $request->query(),
            'limit' => $this->limit,
            'currentPage' => 'Overzicht',
            'section' => 'Notificaties groepen'  
        ));
    }

    public function indexAction(Request $request)
    {
        if ($request->input('action') == 'radioOut') {
            $notification = Notification::where('is_on', 1)
                ->first()
            ;

            if ($notification) {
                $notification->is_on = 0;
                $notification->save();
            }
        } else if ($request->has('id')) {
            $notification = Notification::whereIn('id', $request->input('id'))
                ->delete()
            ;

            Alert::success('De gekozen selectie is succesvol verwijderd.')
                ->persistent('Sluiten')
            ;
        } else {
            if ($request->has('idRadio')) {
                $notification = Notification::find($request->input('idRadio'));
                $notificationFirst = Notification::where('is_on', 1)
                    ->update([
                        'is_on' => 0
                    ])
                ;

                if ($notification) {
                    $notification->is_on = 1;
                    $notification->save();
                }

                Alert::success('De opgegeven notificatie is succesvol op actief gezet.')
                    ->persistent('Sluiten')
                ;
            }
        }

        return Redirect::to('admin/'.$this->slugController);
    }

    public function create()
    {
        $notifications = Notification::all();

        foreach ($notifications as $key => $notification) {
            $notificationArray[$notification->id] = substr(strip_tags($notification->content), 0, 100).'..';
        }

        return view('admin/'.$this->slugController.'/create', [
            'notificationArray' => isset($notificationArray) ? $notificationArray : array(),
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe notificatie'
        ]);
    }

    public function createAction(Request $request)
    {
        $rules = [
            'name' => 'required',
            'notifications' => 'required'
        ];

        $this->validate($request, $rules);

        $notification = NotificationGroup::create([
            'notification_ids' => json_encode($request->input('notifications')),
            'name' => $request->input('name')
        ]);

        Alert::success('Deze notificatie groep is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/notifications/groups');
    }

    public function update($id)
    {
        $notificationGroup = NotificationGroup::find($id);

        $notifications = Notification::all();

        foreach ($notifications as $key => $notification) {
            $notificationArray[$notification->id] = substr(strip_tags($notification->content), 0, 100).'..';
        }

        return view('admin/notifications/groups/update', [
            'slugController' => $this->slugController,
            'notificationArray' => isset($notificationArray) ? $notificationArray : array(),
            'notification' => $notificationGroup,
            'section' => 'Notificatie groep', 
            'currentPage' => 'Notificatie groep wijzigen'
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $notification = NotificationGroup::find($id);

        $rules = [
            'name' => 'required',
            'notifications' => 'required'
        ];
        
        $this->validate($request, $rules);

        $notification->name = $request->input('name');
        $notification->notification_ids = json_encode($request->input('notifications'));
        $notification->save();

        Alert::success('Deze notificatie groep is succesvol gewijzigd.')
            ->persistent('Sluiten')
        ;   

        return Redirect::to('admin/notifications/groups');
    }

}