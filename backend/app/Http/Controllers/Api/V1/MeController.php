<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteAccountRequest;
use App\Http\Requests\UpdateMeRequest;
use App\Http\Resources\ApiResource;
use App\Http\Resources\UserResource;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function update(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated());
        $user->save();

        return new UserResource($user);
    }

    public function export(Request $request, AccountService $account): JsonResponse
    {
        return response()->json(
            $account->export($request->user(), $request),
            200,
            ['Content-Disposition' => 'attachment; filename="my-data.json"'],
            ApiResource::JSON_OPTIONS | JSON_PRETTY_PRINT,
        );
    }

    public function destroy(DeleteAccountRequest $request, AccountService $account): Response
    {
        $user = $request->user();

        if (! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['That password is not correct.'],
            ]);
        }

        $account->delete($user);

        return response()->noContent();
    }
}
