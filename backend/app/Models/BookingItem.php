<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BookingItem extends Model {
    protected $fillable=['booking_id','equipment_item_id','quantity_requested','quantity_approved','quantity_issued','quantity_returned','quantity_damaged','status','issue_condition','return_condition','issue_notes','return_notes'];
    public function booking(){return $this->belongsTo(Booking::class);}
    public function equipment(){return $this->belongsTo(EquipmentItem::class,'equipment_item_id');}
}
