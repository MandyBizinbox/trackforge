<?php
namespace App\Http\Controllers;
use App\Models\DamageReport;
use App\Models\EquipmentItem;
use App\Services\NotificationService;
use Illuminate\Http\Request;
class DamageReportController extends Controller
{
    public function index(){ return DamageReport::with('equipment')->latest()->paginate(100); }
    public function store(Request $request, NotificationService $notifier){ $data=$request->validate(['equipment_item_id'=>'required|exists:equipment_items,id','booking_id'=>'nullable|exists:bookings,id','damage_type'=>'required|string','severity'=>'required|in:minor,major,unusable','description'=>'nullable|string','photo_path'=>'nullable|string','repair_cost'=>'nullable|numeric']); $report=DamageReport::create($data+['reported_by_user_id'=>$request->user()->id,'repair_status'=>'open']); EquipmentItem::whereKey($data['equipment_item_id'])->update(['condition_status'=>'damaged','availability_status'=>'maintenance']); $notifier->sendToRoles(['admin','qm'],'damage_reported','Damage Reported','A damage report has been created.', '/damage', ['damage_report_id'=>$report->id]); return $report->load('equipment'); }
    public function show(DamageReport $damageReport){ return $damageReport->load('equipment'); }
    public function update(Request $request, DamageReport $damageReport){ $data=$request->validate(['damage_type'=>'sometimes|string','severity'=>'sometimes|in:minor,major,unusable','description'=>'nullable|string','repair_status'=>'sometimes|in:open,in_repair,repaired,written_off','repair_cost'=>'nullable|numeric','resolved_at'=>'nullable|date']); $damageReport->update($data); return $damageReport->fresh('equipment'); }
    public function destroy(DamageReport $damageReport){ $damageReport->delete(); return ['message'=>'Deleted']; }
}
