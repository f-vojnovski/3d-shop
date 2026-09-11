<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttestationController;
use App\Support\ModelFormats;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CustomViewController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SalesController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Public routes

// Products
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/products/search/{name}', [ProductController::class, 'search']);
Route::get('/products/{id}/preview/{format}', [ProductController::class, 'previewModel'])
    ->where(['id' => '[0-9]+', 'format' => ModelFormats::pattern()]);
Route::get('/products/{id}/download/{format}', [ProductController::class, 'download'])
    ->middleware('signed:relative')
    ->name('products.download')
    ->where(['id' => '[0-9]+', 'format' => ModelFormats::pattern()]);
Route::get('/products/{id}/versions/{file}/download', [ProductController::class, 'downloadVersion'])
    ->middleware('signed:relative')
    ->name('products.download-version')
    ->where(['id' => '[0-9]+', 'file' => '[0-9]+']);
Route::get('/products-by-user/{userId}', [ProductController::class, 'getProductsForUser']);
Route::get('/previews/{preview}/attestation', [AttestationController::class, 'show'])
    ->name('previews.attestation');

// The gateway calls this, not a browser: no session, no token, and a signature
// instead of either.
Route::post('/payments/webhook', [PaymentWebhookController::class, 'handle'])
    ->withoutMiddleware(['throttle:api']);

// Auth
// Login refuses a wrong name and a wrong password identically, which only
// slows an attacker down if the guesses are also rate limited.
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Protected routes
Route::group(['middleware' => ['auth:sanctum']], function() {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductController::class, 'update'])
        ->where('id', '[0-9]+');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])
        ->where('id', '[0-9]+');
    Route::post('/products/{id}/replace', [ProductController::class, 'replace'])
        ->where('id', '[0-9]+');
    Route::get('/current-user-products', [ProductController::class, 'getCurrentUserProducts']);
    Route::get('/owned-products', [ProductController::class, 'getPurchasedProductsForUser']);
    Route::get('/products-authenticated/{id}', [ProductController::class, 'show']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::post('/checkout/session', [CheckoutController::class, 'session']);
    Route::get('/products/{id}/views', [CustomViewController::class, 'index'])
        ->where('id', '[0-9]+');
    Route::post('/products/{id}/views', [CustomViewController::class, 'store'])
        ->middleware('throttle:20,1')
        ->where('id', '[0-9]+');

    Route::get('/orders/{id}', [CheckoutController::class, 'show'])->where('id', '[0-9]+');

    Route::post('/sales/buy', [SalesController::class, 'makeSale']);
    Route::get('/sales/', [SalesController::class, 'getSalesForAuthenticatedUser']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
