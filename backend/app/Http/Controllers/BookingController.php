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
        $q->where('unit_id', $request->user()->unit_id);
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
        abort_unless($request->user()->unit_id, 403, 'User has no assigned unit.');
        $ids = collect($data['items'])->pluck('equipment_item_id');
        abort_unless($ids->unique()->count() === $ids->count(), 422, 'Duplicate equipment lines are not allowed.');
        $owned = EquipmentItem::query()->where('unit_id', $request->user()->unit_id)->whereIn('id', $ids)->count();
        abort_unless($owned === $ids->count(), 403, 'Equipment must belong to your unit.');
        return DB::transaction(function() use($request,$data,$notifier){
            $booking=Booking::create([
                'unit_id'=>$request->user()->unit_id,'requested_by_user_id'=>$request->user()->id,
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
        abort_unless(in_array($booking->status, ['draft', 'submitted'], true), 409, 'Only draft or submitted bookings can be edited.');
        $data=$request->validate(['title'=>'sometimes|required|string','purpose'=>'nullable|string','section'=>'nullable|string','patrol'=>'nullable|string','start_date'=>'sometimes|required|date','end_date'=>'sometimes|required|date','notes'=>'nullable|string']);
        $start = $data['start_date'] ?? $booking->start_date->toDateString();
        $end = $data['end_date'] ?? $booking->end_date->toDateString();
        abort_unless($end >= $start, 422, 'End date must not precede start date.');
        $booking->update($data);
        return $booking->fresh('items.equipment');
    }
    public function destroy(Request $request, Booking $booking){
        $this->authorizeOwnerOrStaff($request,$booking);
        abort_unless(in_array($booking->status, ['draft', 'submitted', 'approved', 'ready_for_collection'], true), 409, 'Checked-out and completed bookings cannot be cancelled.');
        $booking->update(['status'=>'cancelled']);
        return response()->json(['message'=>'Cancelled']);
    }
    public function submit(Request $request, Booking $booking){
        $this->authorizeOwnerOrStaff($request, $booking);
        abort_unless($booking->status === 'draft', 409, 'Only draft bookings can be submitted.');
        $booking->update(['status'=>'submitted']);
        return $booking;
    }
    public function approve(Request $request, Booking $booking, NotificationService $notifier)
    {
        DB::transaction(function () use ($request, $booking) {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->assertUnit($request, $lockedBooking);
            abort_unless($lockedBooking->status === 'submitted', 409, 'Only submitted bookings can be approved.');
            $items = $lockedBooking->items()->lockForUpdate()->get();
            abort_unless($items->isNotEmpty(), 422, 'Booking has no equipment.');
            abort_unless($items->pluck('equipment_item_id')->unique()->count() === $items->count(), 422, 'Duplicate equipment lines are not supported.');

            $equipment = EquipmentItem::whereIn('id', $items->pluck('equipment_item_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($items as $bi) {
                $item = $equipment->get($bi->equipment_item_id);
                abort_unless($item && (int) $item->unit_id === (int) $lockedBooking->unit_id, 403);
                abort_unless(!in_array($item->availability_status, ['maintenance','lost','written_off'], true), 409, 'Equipment unavailable.');
                $reserved = BookingItem::query()
                    ->where('equipment_item_id', $item->id)
                    ->where('booking_id', '!=', $lockedBooking->id)
                    ->whereHas('booking', fn ($query) => $query
                        ->whereIn('status', ['approved','ready_for_collection'])
                        ->whereDate('start_date', '<=', $lockedBooking->end_date)
                        ->whereDate('end_date', '>=', $lockedBooking->start_date))
                    ->sum('quantity_approved');
                // Checked-out stock is already deducted from quantity_available.
                $available = max(0, (int) $item->quantity_available - (int) $reserved);
                abort_unless((int) $bi->quantity_requested <= $available, 409, 'Insufficient availability for '.$item->asset_code);
            }

            foreach ($items as $bi) {
                $bi->update(['quantity_approved' => $bi->quantity_requested, 'status' => 'approved']);
            }
            $lockedBooking->update([
                'status' => 'approved',
                'approved_by_user_id' => $request->user()->id,
                'approved_at' => now(),
            ]);
        }, 3);

        $notifier->sendToUser($booking->requester,'booking_approved','Booking Approved',"Your booking '{$booking->title}' has been approved. The QM will confirm collection and return details.","/bookings/{$booking->id}",['booking_id'=>$booking->id]);
        return $booking->fresh('items.equipment');
    }
    public function reject(Request $request, Booking $booking, NotificationService $notifier)
    {
        $this->assertUnit($request, $booking);
        abort_unless($booking->status === 'submitted', 409, 'Only submitted bookings can be rejected.');
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
        $this->assertUnit($request, $booking);
        abort_unless(in_array($booking->status, ['approved','ready_for_collection'], true), 409, 'Booking must be approved.');
        $booking->update($data + ['collection_set_by_user_id'=>$request->user()->id,'return_set_by_user_id'=>$request->user()->id,'status'=>'ready_for_collection']);
        if($data['notify'] ?? true) $this->sendLogisticsNotifications($booking->fresh(), $notifier);
        return $booking->fresh('items.equipment');
    }
    public function notifyLogistics(Request $request, Booking $booking, NotificationService $notifier){
        $this->assertUnit($request, $booking);
        abort_unless($booking->status === 'ready_for_collection', 409, 'Set collection and return details first.');
        $this->sendLogisticsNotifications($booking,$notifier);
        return response()->json(['message'=>'Logistics notifications sent']);
    }
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
                $reservedOther = BookingItem::query()
                    ->where('equipment_item_id', $item->id)
                    ->where('booking_id', '!=', $lockedBooking->id)
                    ->whereHas('booking', fn ($query) => $query
                        ->whereIn('status', ['approved', 'ready_for_collection'])
                        ->whereDate('start_date', '<=', $lockedBooking->end_date)
                        ->whereDate('end_date', '>=', $lockedBooking->start_date))
                    ->sum('quantity_approved');
                abort_unless($qty <= (int) $item->quantity_available - (int) $reservedOther, 409, 'Stock reserved by another booking.');
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
            'items.*.quantity_damaged' => 'sometimes|integer|min:0',
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
                $cumulativeDamaged = (int) ($row['quantity_damaged'] ?? ($row['return_condition'] ?? null) === 'damaged' ? $cumulative : (int) $bi->quantity_damaged);
                abort_unless($cumulativeDamaged >= (int) $bi->quantity_damaged && $cumulativeDamaged <= $cumulative, 422, 'Invalid damaged quantity.');
                abort_unless($cumulativeDamaged - (int) $bi->quantity_damaged <= $cumulative - (int) $bi->quantity_returned, 422, 'Invalid damaged return increment.');
                $item = $equipment->get($bi->equipment_item_id);
                abort_unless($item !== null && (int) $item->unit_id === (int) $lockedBooking->unit_id, 403);
            }

            foreach ($bookingItems as $bi) {
                $row = $submitted->get($bi->id);
                $cumulative = (int) $row['quantity_returned'];
                $delta = $cumulative - (int) $bi->quantity_returned;
                if ($delta === 0) continue;
                $item = $equipment->get($bi->equipment_item_id);
                $condition = $row['return_condition'] ?? 'good';
                $cumulativeDamaged = (int) ($row['quantity_damaged'] ?? ($condition === 'damaged' ? $cumulative : (int) $bi->quantity_damaged));
                $newDamaged = $cumulativeDamaged - (int) $bi->quantity_damaged;
                $usable = $delta - $newDamaged;
                $bi->update([
                    'quantity_returned' => $cumulative,
                    'quantity_damaged' => $cumulativeDamaged,
                    'return_condition' => $condition,
                    'return_notes' => $row['return_notes'] ?? null,
                    'status' => $cumulative === (int) $bi->quantity_issued ? 'returned' : 'issued',
                ]);
                $item->quantity_available += $usable;
                $item->quantity_quarantined += $newDamaged;
                abort_unless($item->quantity_available >= 0
                    && $item->quantity_quarantined >= 0
                    && $item->quantity_available + $item->quantity_quarantined <= $item->quantity_total, 409, 'Invalid stock reconciliation.');
                if ($item->tracking_type === 'individual' && $newDamaged > 0) {
                    $item->condition_status = 'damaged';
                    $item->availability_status = 'maintenance';
                } elseif (!in_array($item->availability_status, ['maintenance', 'lost', 'written_off'], true)) {
                    $item->availability_status = $item->quantity_available > 0 ? 'available' : 'checked_out';
                }
                $item->save();
                EquipmentMovement::create([
                    'equipment_item_id' => $item->id,
                    'booking_id' => $lockedBooking->id,
                    'user_id' => $request->user()->id,
                    'movement_type' => $newDamaged === $delta ? 'returned_damaged' : ($newDamaged > 0 ? 'returned_mixed' : 'returned'),
                    'quantity' => $delta,
                    'condition_before' => $bi->issue_condition,
                    'condition_after' => $condition,
                    'notes' => trim(($row['return_notes'] ?? '').($newDamaged > 0 ? "\\nDamaged units in this return: {$newDamaged}" : '')),
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
    private function authorizeView(Request $request, Booking $booking): void {
        $this->assertUnit($request, $booking);
        if($request->user()->role==='scout' && $booking->requested_by_user_id!==$request->user()->id) abort(403);
    }
    private function authorizeOwnerOrStaff(Request $request, Booking $booking): void {
        $this->assertUnit($request, $booking);
        if(in_array($request->user()->role,['admin','qm'],true)) return;
        if($booking->requested_by_user_id!==$request->user()->id) abort(403);
    }
}
