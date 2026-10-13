<?php
namespace App\Http\Controllers;
use App\Models\AppNotification;
use Illuminate\Http\Request;
class NotificationController extends Controller
{
    public function index(Request $request){ return $request->user()->notifications()->latest()->paginate(50); }
    public function unreadCount(Request $request){ return ['count'=>$request->user()->notifications()->whereNull('read_at')->count()]; }
    public function markRead(Request $request, AppNotification $notification){ abort_unless($notification->user_id===$request->user()->id,403); $notification->update(['read_at'=>now()]); return $notification; }
    public function markAllRead(Request $request){ $request->user()->notifications()->whereNull('read_at')->update(['read_at'=>now()]); return ['message'=>'All read']; }
    public function destroy(Request $request, AppNotification $notification){ abort_unless($notification->user_id===$request->user()->id,403); $notification->delete(); return ['message'=>'Deleted']; }
}
