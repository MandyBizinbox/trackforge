<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable {
    use HasFactory, Notifiable;
    protected $fillable=['unit_id','name','email','password','phone','role','section','patrol','is_active','api_token_hash','last_login_at'];
    protected $hidden=['password','api_token_hash'];
    protected $casts=['is_active'=>'boolean','last_login_at'=>'datetime','password'=>'hashed'];
    public function unit(){ return $this->belongsTo(Unit::class); }
    public function notifications(){ return $this->hasMany(AppNotification::class); }
}
