<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_requires_authentication()
    {
        // Single-user app: there is no public landing page.
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }
}
