<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use App\Payments\Checkout;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use App\Payments\PayPalGateway;
use App\Payments\StripeEvents;
use App\Payments\StripeGateway;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Stripe\StripeClient;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

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
            if (! config('services.payments.enabled')) {
                return new FakeGateway((string) config('services.stripe.webhook_secret'));
            }

            return match (config('services.payments.gateway')) {
                'stripe' => $this->stripe(),
                default => $this->paypal(),
            };
        });
    }

    private function stripe(): PaymentGateway
    {
        $secret = config('services.stripe.secret');
        $events = new StripeEvents((string) config('services.stripe.webhook_secret'));

        return blank($secret)
            ? new FakeGateway((string) config('services.stripe.webhook_secret'))
            : new StripeGateway(new StripeClient($secret), $events);
    }

    private function paypal(): PaymentGateway
    {
        $clientId = config('services.paypal.client_id');

        if (blank($clientId)) {
            return new FakeGateway((string) config('services.stripe.webhook_secret'));
        }

        return new PayPalGateway(
            $this->app->make(Http::class),
            (string) config('services.paypal.base'),
            (string) $clientId,
            (string) config('services.paypal.secret'),
            (string) config('services.paypal.webhook_id'),
        );
    }

    /**
     * Bootstrap any application services.
     */
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
