<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\RepairRequest;
use App\Policies\RepairRequestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    private const LOGIN_PER_IP_PER_MINUTE = 30;
    private const LOGIN_PER_ACCOUNT_PER_MINUTE = 5;
    private const LOGIN_PER_ACCOUNT_GLOBAL_PER_MINUTE = 20;
    private const API_PER_USER_PER_MINUTE = 240;

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): array {
            $login = $request->json('login');
            $loginKey = strtolower(is_string($login) ? $login : '');

            return [
                // 30/мин с одного IP — классический брутфорс.
                Limit::perMinute(self::LOGIN_PER_IP_PER_MINUTE)->by('login-ip:' . $request->ip()),

                // 5/мин на пару IP+login — перебор одного аккаунта и password spraying.
                Limit::perMinute(self::LOGIN_PER_ACCOUNT_PER_MINUTE)->by('login:' . hash('sha256', $loginKey . '|' . $request->ip())),

                // 20/мин на login со всех IP — распределённая атака через ботнет.
                Limit::perMinute(self::LOGIN_PER_ACCOUNT_GLOBAL_PER_MINUTE)->by('login-global:' . hash('sha256', $loginKey)),
            ];
        });

        RateLimiter::for('api', function (Request $request): Limit {
            $key = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(self::API_PER_USER_PER_MINUTE)->by((string) $key);
        });

        Gate::policy(RepairRequest::class, RepairRequestPolicy::class);
    }
}