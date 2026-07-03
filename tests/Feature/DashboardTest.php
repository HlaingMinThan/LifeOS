<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_redirects_to_home()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // The old starter-kit dashboard now lives at the catch-up home screen.
        $response = $this->get(route('dashboard'));
        $response->assertRedirect('/');
    }
}
