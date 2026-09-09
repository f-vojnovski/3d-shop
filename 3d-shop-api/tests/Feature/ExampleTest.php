<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_root_returns_the_api_identity(): void
    {
        $this->getJson('/')
            ->assertStatus(200)
            ->assertJsonStructure(['name', 'api']);
    }
}
