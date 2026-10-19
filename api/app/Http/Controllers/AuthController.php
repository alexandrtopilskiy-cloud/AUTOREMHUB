<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\DocumentLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class AuthController
{
    public function login(LoginRequest $request, DocumentLockService $locks): JsonResponse
    {
        $credentialsUser = User::query()
            ->where('login', $request->validated('login'))
            ->first();

        if (
            !$credentialsUser
            || !Hash::check($request->validated('password'), $credentialsUser->password)
            || !$credentialsUser->hasPlatformAccess()
        ) {
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'Неверный логин или пароль');
        }

        $force = (bool) ($request->validated('force') ?? false);

        [$user, $token] = DB::transaction(function () use (
            $credentialsUser,
            $force,
            $locks,
        ): array {
            $user = User::query()
                ->whereKey($credentialsUser->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$user->hasPlatformAccess()) {
                throw new ApiException(
                    401,
                    'INVALID_CREDENTIALS',
                    'Неверный логин или пароль',
                );
            }

            $expirationMinutes = (int) config('sanctum.expiration', 0);
            $activeTokens = $user->tokens()
                ->where(function ($tokens): void {
                    $tokens->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->when(
                    $expirationMinutes > 0,
                    fn ($query) => $query->where(
                        'created_at',
                        '>=',
                        now()->subMinutes($expirationMinutes),
                    ),
                )
                ->exists();

            if ($activeTokens && !$force) {
                throw new ApiException(
                    409,
                    'SESSION_ACTIVE',
                    'Личный кабинет уже открыт на другом устройстве',
                );
            }

            if ($force) {
                $locks->releaseAll($user);
                $user->tokens()->delete();
            }

            return [$user, $user->createToken('api')->plainTextToken];
        });

        $loginKey = strtolower((string) $request->input('login', ''));
        RateLimiter::clear('login-ip:' . $request->ip());
        RateLimiter::clear('login:' . hash('sha256', $loginKey . '|' . $request->ip()));
        RateLimiter::clear('login-global:' . hash('sha256', $loginKey));

        return response()
            ->json([
                'user'  => $user->apiData(),
                'token' => $token,
            ]);
    }

    public function session(Request $request): JsonResponse
    {
        return response()
            ->json([
                'user' => $request->user()->apiData(),
            ]);
    }

    public function logout(Request $request, DocumentLockService $locks): Response
    {
        $locks->releaseAll($request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
