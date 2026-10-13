<?php
namespace App\Http\Controllers;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\EquipmentItem;
use App\Models\EquipmentMovement;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        $q=Booking::with(['items.equipment','requester'])->latest();
        if ($request->user()->role === 'scout') $q->where('requested_by_user_id',$request->user()->id);
        return $q->paginate(100);
    }
    public function store(Request $request, NotificationService $notifier)
    {
        $data=$request->validate([
            'title'=>'required|string|max:255','purpose'=>'nullable|string','section'=>'nullable|string','patrol'=>'nullable|string',
            'start_date'=>'required|date','end_date'=>'required|date|after_or_equal:start_date','notes'=>'nullable|string',
            'items'=>'required|array|min:1','items.*.equipment_item_id'=>'required|exists:equipment_items,id','items.*.quantity_requested'=>'required|integer|min:1'
        ]);
        return DB::transaction(function() use($request,$data,$notifier){
            $booking=Booking::create([
                'unit_id'=>$request->user()->unit_id ?: 1,'requested_by_user_id'=>$request->user()->id,
                'title'=>$data['title'],'purpose'=>$data['purpose']??null,'section'=>$data['section']??$request->user()->section,
                'patrol'=>$data['patrol']??$request->user()->patrol,'start_date'=>$data['start_date'],'end_date'=>$data['end_date'],
                'status'=>'submitted','notes'=>$data['notes']??null
            ]);
            foreach($data['items'] as $item){ $booking->items()->create($item+['status'=>'requested']); }
            $notifier->sendToRoles(['admin','qm'],'booking_requested','New Equipment Request',"{$request->user()->name} submitted booking request: {$booking->title}","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
            return $booking->load('items.equipment','requester');
        });
    }
    public function show(Request $request, Booking $booking){ $this->authorizeView($request,$booking); return $booking->load(['items.equipment.category','requester','approver']); }
    public function update(Request $request, Booking $booking)
    {
        $this->authorizeOwnerOrStaff($request,$booking);
        $data=$request->validate(['title'=>'sometimes|required|string','purpose'=>'nullable|string','section'=>'nullable|string','patrol'=>'nullable|string','start_date'=>'sometimes|required|date','end_date'=>'sometimes|required|date','notes'=>'nullable|string']);
        $booking->update($data);
        return $booking->fresh('items.equipment');
    }
    public function destroy(Request $request, Booking $booking){ $this->authorizeOwnerOrStaff($request,$booking); $booking->update(['status'=>'cancelled']); return response()->json(['message'=>'Cancelled']); }
    public function submit(Booking $booking){ $booking->update(['status'=>'submitted']); return $booking; }
    public function approve(Request $request, Booking $booking, NotificationService $notifier)
    {
        foreach($booking->items as $item){ $item->update(['quantity_approved'=>$item->quantity_requested,'status'=>'approved']); }
        $booking->update(['status'=>'approved','approved_by_user_id'=>$request->user()->id,'approved_at'=>now()]);
        $notifier->sendToUser($booking->requester,'booking_approved','Booking Approved',"Your booking '{$booking->title}' has been approved. The QM will confirm collection and return details.","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        return $booking->fresh('items.equipment');
    }
    public function reject(Request $request, Booking $booking, NotificationService $notifier)
    {
        $data=$request->validate(['reason'=>'nullable|string']);
        $booking->update(['status'=>'rejected','rejected_at'=>now(),'notes'=>trim(($booking->notes ?? '')."\nRejected: ".($data['reason'] ?? 'No reason supplied'))]);
        $notifier->sendToUser($booking->requester,'booking_rejected','Booking Rejected',"Your booking '{$booking->title}' was rejected.".(isset($data['reason'])?' Reason: '.$data['reason']:''),"/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        return $booking;
    }
    public function logistics(Request $request, Booking $booking, NotificationService $notifier)
    {
        $data=$request->validate([
            'collection_date'=>'required|date','collection_time'=>'required','collection_location'=>'nullable|string','collection_instructions'=>'nullable|string',
            'return_date'=>'required|date','return_time'=>'required','return_location'=>'nullable|string','return_instructions'=>'nullable|string','notify'=>'boolean'
        ]);
        $booking->update($data + ['collection_set_by_user_id'=>$request->user()->id,'return_set_by_user_id'=>$request->user()->id,'status'=>'ready_for_collection']);
        if($data['notify'] ?? true) $this->sendLogisticsNotifications($booking->fresh(), $notifier);
        return $booking->fresh('items.equipment');
    }
    public function notifyLogistics(Booking $booking, NotificationService $notifier){ $this->sendLogisticsNotifications($booking,$notifier); return response()->json(['message'=>'Logistics notifications sent']); }
    /**
     * Issue approved equipment. The payload quantities represent the FINAL cumulative
     * number issued for each booking item. An item can be issued only once.
     */
    public function issue(Request $request, Booking $booking, NotificationService $notifier)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.booking_item_id' => 'required|integer|distinct|exists:booking_items,id',
            'items.*.quantity_issued' => 'required|integer|min:0',
            'items.*.issue_condition' => 'nullable|in:good,fair,poor,damaged',
            'items.*.issue_notes' => 'nullable|string',
        ]);

        DB::transaction(function () use ($request, $booking, $data) {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertUnit($request, $lockedBooking);
            abort_unless(in_array($lockedBooking->status, ['approved', 'ready_for_collection'], true), 409, 'Booking must be approved before issuing.');

            $bookingItems = $lockedBooking->items()->lockForUpdate()->get()->keyBy('id');
            $submitted = collect($data['items'])->keyBy('booking_item_id');
            abort_unless($submitted->count() === $bookingItems->count(), 422, 'Include every booking item when issuing equipment.');

            // Lock inventory in a consistent order to reduce deadlock risk.
            $equipment = EquipmentItem::whereIn('id', $bookingItems->pluck('equipment_item_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $totalIssued = 0;
            foreach ($bookingItems as $bi) {
                $row = $submitted->get($bi->id);
                abort_unless($row !== null, 422, 'Missing booking item.');
                abort_unless((int) $bi->quantity_issued === 0 && (int) $bi->quantity_returned === 0, 409, 'Equipment has already been issued.');
                $qty = (int) $row['quantity_issued'];
                abort_unless($qty <= (int) $bi->quantity_approved, 422, 'Cannot issue more than approved.');
                $item = $equipment->get($bi->equipment_item_id);
                abort_unless($item !== null && (int) $item->unit_id === (int) $lockedBooking->unit_id, 403);
                abort_unless(!in_array($item->availability_status, ['maintenance','lost','written_off'], true), 409, 'Equipment is unavailable.');
                abort_unless($qty <= (int) $item->quantity_available, 409, 'Insufficient stock for '.$item->asset_code);
                $totalIssued += $qty;
            }
            abort_unless($totalIssued > 0, 422, 'Issue at least one item.');

            foreach ($bookingItems as $bi) {
                $row = $submitted->get($bi->id);
                $qty = (int) $row['quantity_issued'];
                $item = $equipment->get($bi->equipment_item_id);
                $bi->update([
                    'quantity_issued' => $qty,
                    'issue_condition' => $row['issue_condition'] ?? 'good',
                    'issue_notes' => $row['issue_notes'] ?? null,
                    'status' => $qty > 0 ? 'issued' : 'approved',
                ]);
                if ($qty === 0) continue;
                $item->quantity_available -= $qty;
                $item->availability_status = $item->quantity_available === 0 ? 'checked_out' : 'available';
                $item->save();
                EquipmentMovement::create([
                    'equipment_item_id' => $item->id,
                    'booking_id' => $lockedBooking->id,
                    'user_id' => $request->user()->id,
                    'movement_type' => 'issued',
                    'quantity' => $qty,
                    'condition_before' => $item->condition_status,
                    'notes' => $row['issue_notes'] ?? null,
                ]);
            }

            $lockedBooking->update(['status' => 'checked_out']);
        }, 3);

        $notifier->sendToUser($booking->requester,'equipment_checked_out','Equipment Checked Out',"Equipment for '{$booking->title}' has been checked out.","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        return $booking->fresh('items.equipment');
    }

    /**
     * Returns are cumulative totals, not increments. Repeating the same submission
     * changes nothing. Damaged returns are quarantined and do not increase availability.
     */
    public function returnItems(Request $request, Booking $booking, NotificationService $notifier)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.booking_item_id' => 'required|integer|distinct|exists:booking_items,id',
            'items.*.quantity_returned' => 'required|integer|min:0',
            'items.*.return_condition' => 'nullable|in:good,fair,poor,damaged',
            'items.*.return_notes' => 'nullable|string',
        ]);

        $fullyReturned = DB::transaction(function () use ($request, $booking, $data) {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertUnit($request, $lockedBooking);
            abort_unless($lockedBooking->status === 'checked_out', 409, 'Booking is not checked out.');

            $bookingItems = $lockedBooking->items()->lockForUpdate()->get()->keyBy('id');
            $submitted = collect($data['items'])->keyBy('booking_item_id');
            abort_unless($submitted->count() === $bookingItems->count(), 422, 'Include every booking item when returning equipment.');

            $equipment = EquipmentItem::whereIn('id', $bookingItems->pluck('equipment_item_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($bookingItems as $bi) {
                $row = $submitted->get($bi->id);
                abort_unless($row !== null, 422, 'Missing booking item.');
                $cumulative = (int) $row['quantity_returned'];
                abort_unless($cumulative >= (int) $bi->quantity_returned, 422, 'Returned quantity cannot decrease.');
                abort_unless($cumulative <= (int) $bi->quantity_issued, 422, 'Cannot return more than issued.');
                abort_unless($equipment->has($bi->equipment_item_id), 404);
            }

            foreach ($bookingItems as $bi) {
                $row = $submitted->get($bi->id);
                $cumulative = (int) $row['quantity_returned'];
                $delta = $cumulative - (int) $bi->quantity_returned;
                if ($delta === 0) continue;
                $item = $equipment->get($bi->equipment_item_id);
                $condition = $row['return_condition'] ?? 'good';
                $damaged = $condition === 'damaged';
                $bi->update([
                    'quantity_returned' => $cumulative,
                    'return_condition' => $condition,
                    'return_notes' => $row['return_notes'] ?? null,
                    'status' => $cumulative === (int) $bi->quantity_issued ? 'returned' : 'issued',
                ]);
                if ($damaged) {
                    $item->condition_status = 'damaged';
                    $item->availability_status = 'maintenance';
                } else {
                    $item->quantity_available += $delta;
                    abort_unless($item->quantity_available <= $item->quantity_total, 409, 'Inventory quantity exceeds total.');
                    if (!in_array($item->availability_status, ['maintenance','lost','written_off'], true)) {
                        $item->availability_status = 'available';
                    }
                }
                $item->save();
                EquipmentMovement::create([
                    'equipment_item_id' => $item->id,
                    'booking_id' => $lockedBooking->id,
                    'user_id' => $request->user()->id,
                    'movement_type' => $damaged ? 'returned_damaged' : 'returned',
                    'quantity' => $delta,
                    'condition_before' => $bi->issue_condition,
                    'condition_after' => $condition,
                    'notes' => $row['return_notes'] ?? null,
                ]);
            }
            $complete = $bookingItems->every(fn($bi) =>
                (int) $bi->fresh()->quantity_returned === (int) $bi->quantity_issued
            );
            if ($complete) $lockedBooking->update(['status' => 'returned']);
            return $complete;
        }, 3);

        if ($fullyReturned) {
            $notifier->sendToUser($booking->requester,'equipment_returned','Equipment Returned',"Equipment for '{$booking->title}' has been returned and recorded.","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        }
        return $booking->fresh('items.equipment');
    }

    private function assertUnit(Request $request, Booking $booking): void
    {
        abort_unless((int) $booking->unit_id === (int) $request->user()->unit_id, 403);
    }
    private function sendLogisticsNotifications(Booking $booking, NotificationService $notifier): void
    {
        $notifier->sendToUser($booking->requester,'collection_date_set','Collection Date Confirmed',"Collection for '{$booking->title}': {$booking->collection_date?->format('Y-m-d')} at {$booking->collection_time}. {$booking->collection_location}","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        $notifier->sendToUser($booking->requester,'return_date_set','Return Date Confirmed',"Return for '{$booking->title}': {$booking->return_date?->format('Y-m-d')} at {$booking->return_time}. {$booking->return_location}","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        $booking->update(['collection_notified_at'=>now(),'return_notified_at'=>now()]);
    }
    private function authorizeView(Request $request, Booking $booking): void { if($request->user()->role==='scout' && $booking->requested_by_user_id!==$request->user()->id) abort(403); }
    private function authorizeOwnerOrStaff(Request $request, Booking $booking): void { if(in_array($request->user()->role,['admin','qm'],true)) return; if($booking->requested_by_user_id!==$request->user()->id) abort(403); }
}
