<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class EquipmentItem extends Model {
    use SoftDeletes;
    protected $fillable=['unit_id','category_id','name','asset_code','description','tracking_type','quantity_total','quantity_available','condition_status','availability_status','storage_location','replacement_value','purchase_date','photo_path','is_consumable','notes'];
    protected $casts=['is_consumable'=>'boolean','purchase_date'=>'date','replacement_value'=>'decimal:2'];
    public function category(){return $this->belongsTo(EquipmentCategory::class,'category_id');}
    public function unit(){return $this->belongsTo(Unit::class);}
}
