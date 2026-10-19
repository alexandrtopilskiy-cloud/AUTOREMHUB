<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API-маршруты
|--------------------------------------------------------------------------
|
| Intentionally use the web session stack for this cookie-authenticated
| JSON API. Authentication is cookie-based, not token-based, so the
| standard web middleware group is applied instead of the API group.
|
*/

Route::prefix('api')
->middleware('api.json')
    ->group(__DIR__ . '/api.php');
