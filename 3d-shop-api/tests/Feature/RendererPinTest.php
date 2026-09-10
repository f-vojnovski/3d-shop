<?php

namespace Tests\Feature;

use Tests\TestCase;

class RendererPinTest extends TestCase
{
    /**
     * An attestation says an image came from a given model through a given
     * renderer. If the container and the viewer drift apart, past records
     * describe a renderer that no longer exists.
     */
    public function test_the_render_container_pins_the_same_three_version_as_the_viewer(): void
    {
        $this->assertSame($this->clientPin(), $this->containerPin());
    }

    public function test_the_viewer_pins_three_exactly(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $this->clientPin());
    }

    private function clientPin(): string
    {
        $package = json_decode(
            (string) file_get_contents(base_path('../3d-shop-client/package.json')),
            true
        );

        return $package['dependencies']['three'];
    }

    private function containerPin(): string
    {
        preg_match(
            '/^ARG THREE_VERSION=(\S+)/m',
            (string) file_get_contents(base_path('render/Dockerfile')),
            $matches
        );

        return $matches[1] ?? 'unset';
    }
}
