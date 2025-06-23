<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\User;

class Auth
{
    public function handle(Request $request, Closure $next): JsonResponse
    {
        $token = $request->header('Authorization');

        if ($token === null || trim($token) === '' || !str_starts_with($token, 'Bearer ')) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 200);
        }

        $token = substr($token, 7);

        try {
            $payload = JWTAuth::setToken($token)->getPayload();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 500);
        }

        $userid = $payload->get('sub');

        $user = User::query()->where('id', $userid)->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 500);
        }

        $request->user = $user;

        return $next($request);
    }
}
