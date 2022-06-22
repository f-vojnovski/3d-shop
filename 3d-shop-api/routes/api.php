<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
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
Route::get('/products/getProductModelUrl/{id}', [ProductController::class, 'getProductModelUrl']);
Route::get('/products-by-user/{userId}', [ProductController::class, 'getProductsForUser']);

// Auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Protected routes
Route::group(['middleware' => ['auth:sanctum']], function() {
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{id}', [ProductsController::class, 'update'])
        ->where('id', '[0-9+]');
    Route::get('/current-user-products', [ProductController::class, 'getCurrentUserProducts']);
    Route::get('/owned-products', [ProductController::class, 'getPurchasedProductsForUser']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::post('/sales/buy', [SalesController::class, 'makeSale']);
    Route::get('/sales/', [SalesController::class, 'getSalesForAuthenticatedUser']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
