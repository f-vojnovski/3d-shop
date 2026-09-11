<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The guard must be named: broadcasting resolves the user through `web` by
// default, and this API authenticates with Sanctum tokens.
Broadcast::channel(
    'sellers.{sellerId}',
    fn (User $user, string $sellerId) => (int) $user->id === (int) $sellerId,
    ['guards' => ['sanctum']],
);

Broadcast::channel(
    'viewers.{viewerId}',
    fn (User $user, string $viewerId) => (int) $user->id === (int) $viewerId,
    ['guards' => ['sanctum']],
);
