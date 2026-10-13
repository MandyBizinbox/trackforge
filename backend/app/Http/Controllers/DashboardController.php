<?php
namespace App\Http\Controllers;
use App\Models\Booking;
use App\Models\EquipmentItem;
use App\Models\DamageReport;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user=$request->user();
        $bookingQuery=Booking::query();
        if ($user->role === 'scout') $bookingQuery->where('requested_by_user_id',$user->id);
        return response()->json([
            'equipment_total'=>EquipmentItem::count(),
            'equipment_available'=>EquipmentItem::where('availability_status','available')->count(),
            'equipment_checked_out'=>EquipmentItem::where('availability_status','checked_out')->count(),
            'equipment_maintenance'=>EquipmentItem::where('availability_status','maintenance')->count(),
            'pending_bookings'=>(clone $bookingQuery)->where('status','submitted')->count(),
            'approved_bookings'=>(clone $bookingQuery)->whereIn('status',['approved','ready_for_collection'])->count(),
            'checked_out_bookings'=>(clone $bookingQuery)->where('status','checked_out')->count(),
            'damage_open'=>DamageReport::whereIn('repair_status',['open','in_repair'])->count(),
        ]);
    }
}
