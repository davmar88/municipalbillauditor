<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\ApiResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'popia_consent_version' => User::POPIA_CONSENT_VERSION,
            'popia_consented_at' => now(),
            'ai_extraction_consent' => (bool) ($data['ai_extraction_consent'] ?? false),
        ]);

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => (new UserResource($user->refresh()))->resolve($request),
        ], 201, [], ApiResource::JSON_OPTIONS);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $user->createToken($data['device_name'] ?? 'api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => (new UserResource($user))->resolve($request),
        ], 200, [], ApiResource::JSON_OPTIONS);
    }

    public function logout(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
