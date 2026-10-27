<?php

declare(strict_types = 1);

use App\Console\Commands\Edo\EdoRuSmokeCommand;
use App\Console\Commands\Edo\RegisterEdoOrganizationCommand;
use App\Exceptions\ApiErrorRenderer;
use App\Exceptions\ApiException;
use App\Http\Middleware\ApiHeaders;
use App\Http\Middleware\EnsureJsonBody;
use App\Http\Middleware\EnsureLoginOrigin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schedule;

return Application::configure(basePath: dirname(__DIR__))

/* ────────────────────────────────────────────────────────────
 | Маршруты
 ──────────────────────────────────────────────────────────── */

    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        apiPrefix: 'api',
    )

    ->withCommands([
        RegisterEdoOrganizationCommand::class,
        EdoRuSmokeCommand::class,
    ])

/* ────────────────────────────────────────────────────────────
 | Middleware
 ──────────────────────────────────────────────────────────── */

    ->withMiddleware(function (Middleware $middleware): void {
        // API — только Bearer-токены. CSRF не применяется.
        $middleware->preventRequestForgery();

        $middleware->redirectGuestsTo(null);

        // Ставит Cache-Control и X-Content-Type-Options для /api/*.
        $middleware->append(ApiHeaders::class);

        // Пустая строка значит «очистить поле». Для API это допустимо.
        $middleware->convertEmptyStringsToNull(
            except: [static fn (Request $request) => $request->is('api/*')],
        );

        $middleware->trimStrings(
            except: [static fn (Request $request) => $request->is('api/*')],
        );

        // JSON-контракт API применяется ко всем маршрутам api-группы.
        $middleware->api(prepend: [
            EnsureJsonBody::class,
        ]);

        $middleware->alias([
            'login.origin' => EnsureLoginOrigin::class,
        ]);
    })

/* ────────────────────────────────────────────────────────────
 | Обработка исключений
 ──────────────────────────────────────────────────────────── */

    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $e): bool =>
            $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return ApiErrorRenderer::render($e, $request);
        });

        $exceptions->respond(function ($response) {
            if (request()->is('api/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });
    })

    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('edo:sync-status')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('edo:pull-events')->everyMinute()->withoutOverlapping();
    })

    ->create();
