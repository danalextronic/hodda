<?php
namespace App\Http\Controllers\Admin;

use Alert;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Redirect;
use DB;

class NotificationController extends Controller 
{

    public function __construct(Request $request)
    {
        $this->slugController = 'notifications';
        $this->section = 'Notificaties';
        $this->limit = $request->input('limit', 15);
    }

    public function index(Request $request) 
    {
        $notifications = new Notification();

        # Filter by column
        if ($request->has('sort') && $request->has('order')) {
            $notifications = $notifications->orderBy($request->input('sort'), $request->input('order'));

            session(['sort' => $request->input('sort'), 'order' => $request->input('order')]);
        } else {
            $notifications = $notifications->orderBy('created_at', 'desc');
        }

        # Filter by search term
        /*
        if ($request->has('q')) {
            $notifications = $notifications->where(function ($query) use($request) {
                $query
                    ->where('notifications.from', 'LIKE', '%'.$request->input('q').'%' )
                    ->orWhere('.created_at', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('.status', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('.amount', 'LIKE', '%'.$request->input('q').'%')
                    ->orWhere('users.name', 'LIKE', '%'.$request->input('q').'%')
                ;
            });
        }
        */
        
        $notifications = $notifications->paginate($this->limit);

        # Redirect to last page when page don't exist
        if ($request->input('page') > $notifications->lastPage()) { 
            $lastPageQueryString = json_decode(json_encode($request->query()), true);
            $lastPageQueryString['page'] = $notifications->lastPage();

            return Redirect::to($request->url().'?'.http_build_query($lastPageQueryString));
        }

        $queryString = $request->query();
        unset($queryString['limit']);

        return view('admin.notifications.index', array(
            'notifications' => $notifications,        
            'queryString'=> $queryString,
            'slugController' => $this->slugController,
            'paginationQueryString' => $request->query(),
            'limit' => $this->limit,
            'currentPage' => 'Notificaties',
            'section' => 'Overzicht'  
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
        return view('admin/'.$this->slugController.'/create', [
            'slugController' => $this->slugController,
            'section' => $this->section, 
            'currentPage' => 'Nieuwe notificatie'
        ]);
    }

    public function createAction(Request $request)
    {
        $rules = [
            'content' => 'required'
        ];

        $images = count($request->file('image')) - 1;
        $files = $request->file('image');

        if ($request->hasFile('image')) {
            foreach (range(0, $images) as $index) {
                $rule['images.' . $index] = 'mimes:jpeg,jpg,png';
            }

            $rules = array_merge($rules, $rule);
        }

        $this->validate($request, $rules);

        $notification = Notification::create([
            'content' => $request->input('content'),
            'width' => $request->input('width'),
            'height' => $request->input('height')
        ]);

        if ($request->hasFile('image')) {
            $notification
                ->addMedia($request->file('image'))
                ->toMediaLibrary()
            ;
        }

        Alert::success('Deze notificatie is succesvol aangemaakt.')->persistent('Sluiten');   
        return Redirect::to('admin/notifications');
    }

    public function update($id)
    {
        $notification = Notification::with('media')
            ->find($id)
        ;

        $mediaItems = $notification->getMedia();

        return view('admin/notifications/update', [
            'slugController' => $this->slugController,
            'mediaItems' => $mediaItems,
            'notification' => $notification,
            'section' => 'Notificaties', 
            'currentPage' => 'Notificatie wijzigen'
        ]);
    }

    public function updateAction(Request $request, $id)
    {
        $notification = Notification::find($id);

        $rules = [
            'content' => 'required'
        ];

        $images = count($request->file('image')) - 1;

        if ($request->hasFile('image')) {
            $rule['image'] = 'mimes:jpeg,jpg,png';
            $rules = array_merge($rules, $rule);
        }

        $this->validate($request, $rules);

        $notification->width = $request->input('width');
        $notification->height = $request->input('height');
        $notification->content = $request->input('content');
        $notification->save();
        
        if ($request->hasFile('image')) {
            $notification->clearMediaCollection(); 

            $notification
                ->addMedia($request->file('image'))
                ->toMediaLibrary()
            ;
        }

        Alert::success('Deze notificatie is succesvol gewijzigd.')
            ->persistent('Sluiten')
        ;   

        return Redirect::to('admin/notifications');
    }

}