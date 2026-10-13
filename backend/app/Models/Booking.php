<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Booking extends Model {
    protected $fillable=['unit_id','requested_by_user_id','approved_by_user_id','title','purpose','section','patrol','start_date','end_date','collection_date','collection_time','collection_location','collection_instructions','return_date','return_time','return_location','return_instructions','collection_set_by_user_id','return_set_by_user_id','collection_notified_at','return_notified_at','status','notes','approved_at','rejected_at'];
    protected $casts=['start_date'=>'date','end_date'=>'date','collection_date'=>'date','return_date'=>'date','collection_notified_at'=>'datetime','return_notified_at'=>'datetime','approved_at'=>'datetime','rejected_at'=>'datetime'];
    public function items(){return $this->hasMany(BookingItem::class);}
    public function requester(){return $this->belongsTo(User::class,'requested_by_user_id');}
    public function approver(){return $this->belongsTo(User::class,'approved_by_user_id');}
}
