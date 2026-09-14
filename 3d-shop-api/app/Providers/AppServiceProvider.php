<?php

namespace App\Providers;

use App\Payments\Checkout;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Payments\PayPalGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Checkout::class, fn () => new Checkout(
            (int) config('services.commission.bps')
        ));

        // No credentials, no provider: the project has to run for someone who
        // has not signed up for one.
        $this->app->singleton(PaymentGateway::class, function () {
            return config('services.payments.enabled') ? $this->paypal() : $this->fake();
        });
    }

    private function fake(): PaymentGateway
    {
        return new FakeGateway((string) config('services.payments.webhook_secret'));
    }

    private function paypal(): PaymentGateway
    {
        $clientId = config('services.paypal.client_id');

        // Half-configured refuses, rather than granting every paid file for nothing.
        if (blank($clientId)) {
            throw new RuntimeException(
                'PAYMENTS_ENABLED is on but PAYPAL_CLIENT_ID is empty. Set the '
                .'credentials, or set PAYMENTS_ENABLED=false to grant without charging.'
            );
        }

        return new PayPalGateway(
            $this->app->make(Http::class),
            (string) config('services.paypal.base'),
            (string) $clientId,
            (string) config('services.paypal.secret'),
            (string) config('services.paypal.webhook_id'),
        );
    }

    public function boot(): void
    {
        // Single resources return the object directly; paginated collections
        // still carry the paginator's own data and meta keys.
        JsonResource::withoutWrapping();

        // throttleApi() in bootstrap/app.php resolves a limiter by this name.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
