<?php
namespace App\Http\Controllers;
use App\Models\PushSubscription;
use App\Services\NotificationService;
use Illuminate\Http\Request;
class PushSubscriptionController extends Controller
{
    public function subscribe(Request $request){ $data=$request->validate(['endpoint'=>'required|string','keys.p256dh'=>'required|string','keys.auth'=>'required|string','content_encoding'=>'nullable|string']); $sub=PushSubscription::updateOrCreate(['user_id'=>$request->user()->id,'endpoint'=>$data['endpoint']],['public_key'=>$data['keys']['p256dh'],'auth_token'=>$data['keys']['auth'],'content_encoding'=>$data['content_encoding']??'aes128gcm','user_agent'=>$request->userAgent(),'last_used_at'=>now()]); return $sub; }
    public function unsubscribe(Request $request){ $request->validate(['endpoint'=>'required|string']); PushSubscription::where('user_id',$request->user()->id)->where('endpoint',$request->endpoint)->delete(); return ['message'=>'Unsubscribed']; }
    public function test(Request $request, NotificationService $notifier){ $notifier->sendToUser($request->user(),'push_test','Test Notification','Your notification setup is ready.','/notifications'); return ['message'=>'Test notification created']; }
}
