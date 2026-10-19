<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLoginOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (!is_string($origin) || !in_array($origin, $this->allowedOrigins(), true)) {
            throw new ApiException(
                Response::HTTP_FORBIDDEN,
                'ORIGIN_DENIED',
                'Источник запроса входа не разрешён',
            );
        }

        return $next($request);
    }

    /**
     * Список разрешённых источников: конфиг фронтенда и URL приложения.
     *
     * @return array<int, string>
     */
    private function allowedOrigins(): array
    {
        $frontendOrigins = config('sto.frontend_origins');
        $appUrl = config('app.url');

        $origins = is_array($frontendOrigins) ? $frontendOrigins : [];

        if (is_string($appUrl) && $appUrl !== '') {
            $origins[] = rtrim($appUrl, '/');
        }

        return array_values(array_unique($origins));
    }
}
