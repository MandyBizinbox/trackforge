<?php
namespace App\Http\Controllers;
use App\Models\BookingItem;
use App\Models\EquipmentItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentController extends Controller
{
    public function index(Request $request)
    {
        $q=EquipmentItem::with('category')->latest();
        if ($request->filled('search')) $q->where('name','like','%'.$request->search.'%')->orWhere('asset_code','like','%'.$request->search.'%');
        if ($request->filled('status')) $q->where('availability_status',$request->status);
        return $q->paginate(100);
    }
    public function store(Request $request)
    {
        $this->authorizeWrite($request);
        $data=$this->validated($request);
        $data['unit_id']=$request->user()->unit_id ?: 1;
        $data['quantity_available']=$data['quantity_total'] ?? 1;
        return EquipmentItem::create($data)->load('category');
    }
    public function show(EquipmentItem $equipment){ return $equipment->load('category'); }
    public function update(Request $request, EquipmentItem $equipment)
    {
        $this->authorizeWrite($request);
        $data=$this->validated($request, $equipment->id);
        $equipment->update($data);
        return $equipment->fresh('category');
    }
    public function destroy(Request $request, EquipmentItem $equipment){ $this->authorizeWrite($request); $equipment->delete(); return response()->json(['message'=>'Deleted']); }
    public function availability(Request $request)
    {
        $data=$request->validate(['start_date'=>'required|date','end_date'=>'required|date|after_or_equal:start_date']);
        $items=EquipmentItem::with('category')->get()->map(function($item) use($data){
            $reserved=BookingItem::where('equipment_item_id',$item->id)
                ->whereHas('booking', fn($q)=>$q->whereIn('status',['submitted','approved','ready_for_collection','checked_out'])->whereDate('start_date','<=',$data['end_date'])->whereDate('end_date','>=',$data['start_date']))
                ->sum('quantity_approved');
            if ($reserved <= 0) $reserved=BookingItem::where('equipment_item_id',$item->id)
                ->whereHas('booking', fn($q)=>$q->whereIn('status',['submitted'])->whereDate('start_date','<=',$data['end_date'])->whereDate('end_date','>=',$data['start_date']))
                ->sum('quantity_requested');
            $item->available_for_dates=max(0, ($item->quantity_total ?? 1)-$reserved);
            return $item;
        });
        return response()->json($items);
    }
    private function authorizeWrite(Request $request): void { if(!in_array($request->user()->role,['admin','qm'],true)) abort(403,'Forbidden'); }
    private function validated(Request $request, ?int $id=null): array
    {
        return $request->validate([
            'category_id'=>'required|exists:equipment_categories,id','name'=>'required|string|max:255',
            'asset_code'=>['required','string','max:80',Rule::unique('equipment_items','asset_code')->ignore($id)],
            'description'=>'nullable|string','tracking_type'=>'required|in:individual,quantity','quantity_total'=>'required|integer|min:1',
            'condition_status'=>'required|in:good,fair,poor,damaged','availability_status'=>'required|in:available,booked,checked_out,maintenance,lost,written_off',
            'storage_location'=>'nullable|string|max:255','replacement_value'=>'nullable|numeric|min:0','purchase_date'=>'nullable|date',
            'photo_path'=>'nullable|string','is_consumable'=>'boolean','notes'=>'nullable|string'
        ]);
    }
}
