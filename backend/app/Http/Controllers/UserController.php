<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
class UserController extends Controller
{
    public function index(){ return User::orderBy('name')->paginate(100); }
    public function store(Request $request){ $data=$request->validate(['name'=>'required','email'=>'required|email|unique:users,email','password'=>'required|min:8','phone'=>'nullable','role'=>'required|in:admin,qm,scout,viewer','section'=>'nullable','patrol'=>'nullable','is_active'=>'boolean']); $data['unit_id']=$request->user()->unit_id ?: 1; $data['password']=Hash::make($data['password']); return User::create($data); }
    public function show(User $user){ return $user; }
    public function update(Request $request, User $user){ $data=$request->validate(['name'=>'sometimes|required','email'=>['sometimes','required','email',Rule::unique('users','email')->ignore($user->id)],'password'=>'nullable|min:8','phone'=>'nullable','role'=>'sometimes|in:admin,qm,scout,viewer','section'=>'nullable','patrol'=>'nullable','is_active'=>'boolean']); if(!empty($data['password'])) $data['password']=Hash::make($data['password']); else unset($data['password']); $user->update($data); return $user; }
    public function destroy(User $user){ $user->update(['is_active'=>false]); return ['message'=>'User deactivated']; }
}
