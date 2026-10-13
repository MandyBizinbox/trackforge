<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DamageReport extends Model { protected $fillable=['equipment_item_id','booking_id','reported_by_user_id','damage_type','severity','description','photo_path','repair_status','repair_cost','resolved_at']; protected $casts=['repair_cost'=>'decimal:2','resolved_at'=>'datetime']; public function equipment(){return $this->belongsTo(EquipmentItem::class,'equipment_item_id');} }
