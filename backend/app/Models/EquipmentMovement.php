<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EquipmentMovement extends Model { protected $fillable=['equipment_item_id','booking_id','user_id','movement_type','quantity','condition_before','condition_after','notes']; }
