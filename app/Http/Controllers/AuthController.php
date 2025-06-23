<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function getOwners($user)
    {
        $company_id = $user->company_id;
        $owners = DB::table('cxs_user as cu')
            ->select(['cu.id', 'cu.full_name as name', 'cu.email'])
            ->where('cu.user_status', 'Active')
            ->where('cu.company_id', $company_id)
            ->where(function ($query) use ($user) {
                $query->whereExists(function ($subquery) {
                    $subquery->select(DB::raw(1))
                        ->from('cxs_licensed_products as cp')
                        ->whereColumn('cp.user_id', 'cu.id');
                })
                    ->orWhereExists(function ($subquery) {
                        $subquery->select(DB::raw(1))
                            ->from('cxs_licensed_products_subscription as cps')
                            ->whereColumn('cps.user_id', 'cu.id');
                    });
            })
            ->orderBy('cu.full_name')
            ->get();

        return $owners;
    }

    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ($email === null || trim($email) === '') {
            return response()->json([
                'name' => 'Bad Request',
                'target' => 'email',
                'message' => 'Email is required'
            ], 500);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return response()->json([
                'name' => 'Bad Request',
                'target' => 'email',
                'message' => 'Invalid email'
            ], 500);
        }

        if ($password === null || trim($password) === '') {
            return response()->json([
                'name' => 'Bad Request',
                'target' => 'password',
                'message' => 'Password is required'
            ], 500);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return response()->json([
                'name' => 'Bad Request',
                'target' => 'email',
                'message' => 'Email does not exist'
            ], 500);
        }

        if (!Hash::check($password, $user->password)) {
            return response()->json([
                'name' => 'Bad Request',
                'target' => 'password',
                'message' => 'Password is incorrect'
            ], 500);
        }

        $token = JWTAuth::attempt([
            'full_name' => $user->full_name,
            'email' => $email,
            'password' => $password,
        ]);

        if ($token === false || $token === null) {
            return response()->json([
                'name' => 'Bad Request',
                'message' => 'Invalid Credentials'
            ], 500);
        }

        $projects = Project::where('company_id', $user->company_id)->get();

        $owners = $this->getOwners($user);

        return response()->json([
            'token' => $token,
            'user' => $user,
            'projects' => $projects,
            'owners' => $owners
        ], 200);
    }

    public function register(Request $request): JsonResponse
    {
        return response()->json(['message' => 'User created'], 201);
    }

    public function checkAuth(Request $request): JsonResponse
    {
        $token = $request->header('Authorization');

        if ($token === null || trim($token) === '' || !str_starts_with($token, 'Bearer ')) {
            return response()->json([
                'authenticated' => false,
            ], 200);
        }

        $token = substr($token, 7);

        try {
            $payload = JWTAuth::setToken($token)->getPayload();
        } catch (\Exception $e) {
            return response()->json([
                'authenticated' => false,
            ], 500);
        }

        $userid = $payload->get('sub');

        $user = User::query()->where('id', $userid)->first();

        if ($user === null) {
            return response()->json([
                'authenticated' => false,
            ], 500);
        }

        $projects = Project::whereIn('company_id', $request->user->company_id)->get();

        $owners = $this->getOwners($user);

        return response()->json([
            'authenticated' => true,
            'user' => $user,
            'projects' => $projects,
            'owners' => $owners
        ], 200);
    }
}
