<?php

declare(strict_types = 1);

use App\Exceptions\ApiErrorRenderer;
use App\Exceptions\ApiException;
use App\Http\Middleware\ApiHeaders;
use App\Http\Middleware\EnsureApiUser;
use App\Http\Middleware\EnsureJsonBody;
use App\Http\Middleware\EnsureLoginOrigin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))

/* ────────────────────────────────────────────────────────────
 | Маршруты
 ──────────────────────────────────────────────────────────── */

    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
    )

/* ────────────────────────────────────────────────────────────
 | Middleware
 ──────────────────────────────────────────────────────────── */

    ->withMiddleware(function (Middleware $middleware): void {
        // Login не имеет предварительного токена. Origin проверяется
        // отдельным middleware EnsureLoginOrigin.
        $middleware->preventRequestForgery(except: ['api/*']);

        $middleware->redirectGuestsTo(null);

        $middleware->append(ApiHeaders::class);

        // Пустая строка значит «очистить поле». Для API это допустимо.
        $middleware->convertEmptyStringsToNull(
            except: [static fn (Request $request) => $request->is('api/*')],
        );

        $middleware->trimStrings(
            except: [static fn (Request $request) => $request->is('api/*')],
        );

        $middleware->alias([
            'api.user'     => EnsureApiUser::class,
            'api.json'     => EnsureJsonBody::class,
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

            Log::error('render called', ['exception' => get_class($e), 'status' => $e instanceof HttpExceptionInterface ? $e->getStatusCode() : null]);

            return ApiErrorRenderer::render($e, $request);
        });

        $exceptions->respond(function ($response) {
            if (request()->is('api/*')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }

            return $response;
        });
    })

    ->create();