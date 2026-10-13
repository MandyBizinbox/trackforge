<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (!$token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $users = User::whereNotNull('api_token_hash')->where('is_active', true)->get();
        foreach ($users as $user) {
            if (Hash::check($token, $user->api_token_hash)) {
                $request->setUserResolver(fn () => $user);
                return $next($request);
            }
        }

        return response()->json(['message' => 'Invalid token.'], 401);
    }
}
