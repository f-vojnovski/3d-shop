<?php

use Illuminate\Support\Facades\Route;

// API-only application: no Blade views are shipped, so the root is an identity
// payload rather than a landing page. The client is served separately by Vite.
Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'api' => url('/api/products'),
    ]);
});
