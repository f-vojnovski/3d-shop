<?php

namespace Tests\Feature;

use App\Events\PreviewRenderFinished;
use App\Models\Product;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RenderBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * phpunit.xml runs on the null broadcaster, which authorises every channel.
     * Channels bind to whichever driver is default when the app boots, so this
     * has to happen before parent::setUp().
     */
    protected function setUp(): void
    {
        putenv('BROADCAST_CONNECTION=reverb');
        $_ENV['BROADCAST_CONNECTION'] = 'reverb';
        $_SERVER['BROADCAST_CONNECTION'] = 'reverb';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('BROADCAST_CONNECTION=null');
        $_ENV['BROADCAST_CONNECTION'] = 'null';
        $_SERVER['BROADCAST_CONNECTION'] = 'null';

        parent::tearDown();
    }

    public function test_it_reaches_the_seller_privately_and_the_listing_publicly(): void
    {
        $product = $this->productFor($this->seller('seller'));

        $event = PreviewRenderFinished::for($product, 'obj');

        $this->assertSame($product->user_id, $event->sellerId);
        $this->assertSame('preview.render.finished', $event->broadcastAs());
        $this->assertEquals(
            [
                new PrivateChannel('sellers.'.$product->user_id),
                new Channel('products.'.$product->id),
            ],
            $event->broadcastOn()
        );
    }

    public function test_a_seller_may_listen_to_their_own_channel(): void
    {
        $seller = $this->seller('seller');
        $this->productFor($seller);
        Sanctum::actingAs($seller);

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-sellers.{$seller->id}",
            'socket_id' => '1234.5678',
        ])
            ->assertSuccessful();
    }

    public function test_a_seller_cannot_listen_to_someone_elses_channel(): void
    {
        $owner = $this->seller('owner');
        $this->productFor($owner);

        Sanctum::actingAs($this->seller('intruder'));

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-sellers.{$owner->id}",
            'socket_id' => '1234.5678',
        ])
            ->assertForbidden();
    }

    public function test_the_channel_is_closed_to_guests(): void
    {
        $seller = $this->seller('seller');

        $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-sellers.{$seller->id}",
            'socket_id' => '1234.5678',
        ])
            ->assertUnauthorized();
    }

    private function seller(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => "{$name}@example.com",
            'password' => 'password123',
        ]);
    }

    private function productFor(User $user): Product
    {
        return Product::create([
            'name' => 'Half-track',
            'price_cents' => 2450,
            'preview_mode' => Product::PREVIEW_ATTESTED_STILLS,
            'preview_angles' => [['position' => [3, 2, 4], 'target' => [0, 0, 0], 'fov' => 75]],
            'user_id' => $user->id,
        ]);
    }
}
