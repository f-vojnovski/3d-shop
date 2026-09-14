<?php

use App\Http\Controllers\AttestationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ClipController;
use App\Http\Controllers\CustomViewController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SalesController;
use App\Support\ModelFormats;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show'])
    ->where('id', '[0-9]+');
Route::get('/products/{id}/preview/{format}', [ProductController::class, 'previewModel'])
    ->where(['id' => '[0-9]+', 'format' => ModelFormats::pattern()]);
Route::get('/products/{id}/proxy/{format}', [ProductController::class, 'viewerProxy'])
    ->where(['id' => '[0-9]+', 'format' => ModelFormats::pattern()]);
Route::get('/products/{id}/download/{format}', [ProductController::class, 'download'])
    ->middleware('signed:relative')
    ->name('products.download')
    ->where(['id' => '[0-9]+', 'format' => ModelFormats::pattern()]);
Route::get('/products/{id}/versions/{file}/download', [ProductController::class, 'downloadVersion'])
    ->middleware('signed:relative')
    ->name('products.download-version')
    ->where(['id' => '[0-9]+', 'file' => '[0-9]+']);
Route::get('/previews/{preview}/attestation', [AttestationController::class, 'show'])
    ->name('previews.attestation');

// The gateway calls this, not a browser: no session, no token, and a signature
// instead of either.
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle'])
    ->withoutMiddleware(['throttle:api']);

// Login refuses a wrong name and a wrong password identically, which only
// slows an attacker down if the guesses are also rate limited.
// A session whether or not the caller looked like the frontend: without it a
// request with no Origin gets a 500 rather than an answer.
Route::middleware(StartSession::class)->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
});

Route::group(['middleware' => ['auth:sanctum']], function () {
    // Reads and unpacks every byte of every model inside the request, so it is
    // throttled the way the other endpoints that do container-shaped work are.
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::put('/products/{id}', [ProductController::class, 'update'])
        ->where('id', '[0-9]+');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])
        ->where('id', '[0-9]+');
    Route::post('/products/{id}/replace', [ProductController::class, 'replace'])
        ->where('id', '[0-9]+');
    Route::post('/products/{id}/publish', [ProductController::class, 'publish'])
        ->where('id', '[0-9]+');
    // Starts a container per call, like a render, so it is throttled the same way.
    Route::post('/products/{id}/clips', [ClipController::class, 'store'])
        ->where('id', '[0-9]+')
        ->middleware('throttle:10,1');
    Route::post('/products/{id}/thumbnails', [ProductController::class, 'addThumbnails'])
        ->where('id', '[0-9]+');
    Route::delete('/products/{id}/thumbnails/{file}', [ProductController::class, 'removeThumbnail'])
        ->where(['id' => '[0-9]+', 'file' => '[0-9]+']);
    // Starts a container per call, so it is throttled like a render.
    Route::post('/uploads/convert', [ProductController::class, 'convert'])
        ->middleware('throttle:10,1');
    Route::get('/current-user-products', [ProductController::class, 'getCurrentUserProducts']);
    Route::get('/owned-products', [ProductController::class, 'getPurchasedProductsForUser']);
    Route::get('/products-authenticated/{id}', [ProductController::class, 'show']);

    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware(StartSession::class);

    Route::post('/checkout/session', [CheckoutController::class, 'session']);
    Route::get('/products/{id}/views', [CustomViewController::class, 'index'])
        ->where('id', '[0-9]+');
    Route::post('/products/{id}/views', [CustomViewController::class, 'store'])
        ->middleware('throttle:20,1')
        ->where('id', '[0-9]+');
    Route::post('/products/{id}/views/{view}/publish', [CustomViewController::class, 'publish'])
        ->where(['id' => '[0-9]+', 'view' => '[0-9]+']);

    Route::get('/orders/{id}', [CheckoutController::class, 'show'])->where('id', '[0-9]+');

    Route::get('/sales/', [SalesController::class, 'getSalesForAuthenticatedUser']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
