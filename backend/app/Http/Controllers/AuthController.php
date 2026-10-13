<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email'=>'required|email','password'=>'required|string']);
        $user = User::where('email', $data['email'])->where('is_active', true)->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['message'=>'Invalid login details.'], 422);
        }
        $token = Str::random(80);
        $user->forceFill(['api_token_hash'=>Hash::make($token),'last_login_at'=>now()])->save();
        return response()->json(['token'=>$token,'user'=>$user]);
    }
    public function me(Request $request){ return response()->json(['user'=>$request->user()]); }
    public function logout(Request $request){ $request->user()->forceFill(['api_token_hash'=>null])->save(); return response()->json(['message'=>'Logged out']); }
}
