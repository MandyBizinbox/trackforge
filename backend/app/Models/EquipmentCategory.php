<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EquipmentCategory extends Model { protected $fillable=['name','slug','description','sort_order']; public function items(){return $this->hasMany(EquipmentItem::class,'category_id');}}
