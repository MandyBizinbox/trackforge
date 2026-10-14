<?php

namespace Tests\Feature;

use App\Http\Middleware\ApiTokenAuth;
use App\Models\Booking;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\EquipmentMovement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryMovementTest extends TestCase
{
    use RefreshDatabase;

    private User $qm;
    private EquipmentItem $ropes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ApiTokenAuth::class);

        $unit = Unit::create(['name' => 'Test Scout Unit']);
        $category = EquipmentCategory::create(['name' => 'Pioneering', 'slug' => 'pioneering']);
        $this->qm = User::create([
            'unit_id' => $unit->id,
            'name' => 'Quartermaster',
            'email' => 'qm@example.test',
            'password' => 'test-password-not-for-production',
            'role' => 'qm',
            'is_active' => true,
        ]);
        $this->ropes = EquipmentItem::create([
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'name' => '4m Ropes',
            'asset_code' => 'TEST-ROPE-4M',
            'tracking_type' => 'quantity',
            'quantity_total' => 100,
            'quantity_available' => 100,
            'condition_status' => 'good',
            'availability_status' => 'available',
        ]);
    }

    private function booking(int $quantity, string $status = 'submitted'): Booking
    {
        $booking = Booking::create([
            'unit_id' => $this->qm->unit_id,
            'requested_by_user_id' => $this->qm->id,
            'title' => 'Equipment request',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
            'status' => $status,
        ]);
        $booking->items()->create([
            'equipment_item_id' => $this->ropes->id,
            'quantity_requested' => $quantity,
            'quantity_approved' => $status === 'submitted' ? 0 : $quantity,
            'status' => $status === 'submitted' ? 'requested' : 'approved',
        ]);
        return $booking;
    }

    public function test_approval_prevents_overlapping_allocation(): void
    {
        $first = $this->booking(70);
        $second = $this->booking(40);

        $this->actingAs($this->qm)->postJson("/api/bookings/{$first->id}/approve")->assertOk();
        $this->actingAs($this->qm)->postJson("/api/bookings/{$second->id}/approve")->assertStatus(409);

        $this->assertSame('submitted', $second->fresh()->status);
        $this->assertSame(0, (int) $second->items()->first()->quantity_approved);
    }

    public function test_issue_partial_return_and_duplicate_request(): void
    {
        $booking = $this->booking(20, 'approved');
        $line = $booking->items()->first();

        $issue = ['items' => [[
            'booking_item_id' => $line->id,
            'quantity_issued' => 20,
            'issue_condition' => 'good',
        ]]];
        $this->actingAs($this->qm)->postJson("/api/bookings/{$booking->id}/issue", $issue)->assertOk();
        $this->assertSame(80, (int) $this->ropes->fresh()->quantity_available);

        $this->actingAs($this->qm)->postJson("/api/bookings/{$booking->id}/issue", $issue)->assertStatus(409);
        $this->assertSame(80, (int) $this->ropes->fresh()->quantity_available);

        $partial = ['items' => [[
            'booking_item_id' => $line->id,
            'quantity_returned' => 12,
            'return_condition' => 'good',
        ]]];
        $this->actingAs($this->qm)->postJson("/api/bookings/{$booking->id}/return", $partial)->assertOk();
        $this->assertSame(92, (int) $this->ropes->fresh()->quantity_available);

        $this->actingAs($this->qm)->postJson("/api/bookings/{$booking->id}/return", $partial)->assertOk();
        $this->assertSame(92, (int) $this->ropes->fresh()->quantity_available);

        $complete = ['items' => [[
            'booking_item_id' => $line->id,
            'quantity_returned' => 20,
            'return_condition' => 'good',
        ]]];
        $this->actingAs($this->qm)->postJson("/api/bookings/{$booking->id}/return", $complete)->assertOk();
        $this->assertSame(100, (int) $this->ropes->fresh()->quantity_available);
        $this->assertSame('returned', $booking->fresh()->status);
        $this->assertSame(3, EquipmentMovement::count());
    }

    public function test_foreign_booking_item_cannot_be_issued(): void
    {
        $first = $this->booking(10, 'approved');
        $second = $this->booking(15, 'approved');
        $foreignItem = $second->items()->first();

        $this->actingAs($this->qm)->postJson("/api/bookings/{$first->id}/issue", [
            'items' => [['booking_item_id' => $foreignItem->id, 'quantity_issued' => 10]],
        ])->assertStatus(422);
        $this->assertSame(100, (int) $this->ropes->fresh()->quantity_available);
    }

    public function test_scout_cannot_approve_or_issue(): void
    {
        $scout = User::create([
            'unit_id' => $this->qm->unit_id,
            'name' => 'Scout',
            'email' => 'scout@example.test',
            'password' => 'test-password-not-for-production',
            'role' => 'scout',
            'is_active' => true,
        ]);
        $booking = $this->booking(1);
        $this->actingAs($scout)->postJson("/api/bookings/{$booking->id}/approve")->assertForbidden();
        $this->actingAs($scout)->postJson("/api/bookings/{$booking->id}/issue", [
            'items' => [['booking_item_id' => $booking->items()->first()->id, 'quantity_issued' => 1]],
        ])->assertForbidden();
    }

    public function test_checked_out_booking_cannot_be_cancelled(): void
    {
        $booking = $this->booking(2, 'approved');
        $line = $booking->items()->first();
        $this->actingAs($this->qm)->postJson("/api/bookings/{$booking->id}/issue", [
            'items' => [['booking_item_id' => $line->id, 'quantity_issued' => 2]],
        ])->assertOk();
        $this->actingAs($this->qm)->deleteJson("/api/bookings/{$booking->id}")->assertStatus(409);
        $this->assertSame('checked_out', $booking->fresh()->status);
    }
}
