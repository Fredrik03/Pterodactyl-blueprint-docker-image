<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\Http\Middleware\Activity\ServerSubject;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use Pterodactyl\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use Pterodactyl\BlueprintFramework\Extensions\modmanager\Controllers\ModManagerController;

/*
|--------------------------------------------------------------------------
| Mod Manager client API
|--------------------------------------------------------------------------
|
| Mounted by Blueprint under /api/client/extensions/modmanager. Every route is
| server-scoped, so the panel's own server middleware is applied here exactly
| like routes/api-client.php does for the built-in file endpoints.
|
*/
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [ServerSubject::class, AuthenticateServerAccess::class, ResourceBelongsToServer::class],
], function () {
    Route::get('/status', [ModManagerController::class, 'status']);
    Route::get('/search', [ModManagerController::class, 'search']);
    Route::get('/versions', [ModManagerController::class, 'versions']);
    Route::get('/installed', [ModManagerController::class, 'installed']);
    Route::get('/updates', [ModManagerController::class, 'updates']);

    Route::post('/context', [ModManagerController::class, 'saveContext']);
    Route::post('/install', [ModManagerController::class, 'install']);
    Route::post('/toggle', [ModManagerController::class, 'toggle']);
    Route::post('/remove', [ModManagerController::class, 'remove']);
});
