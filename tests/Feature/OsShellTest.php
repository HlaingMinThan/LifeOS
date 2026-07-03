<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OsShellTest extends TestCase
{
    use RefreshDatabase;

    public static function screens(): array
    {
        return [
            ['/', 'os/Home'],
            ['/money', 'os/Money'],
            ['/todos', 'os/Todos'],
            ['/care', 'os/Care'],
            ['/ideas', 'os/Ideas'],
        ];
    }

    #[DataProvider('screens')]
    public function test_screen_renders_for_authenticated_user(string $url, string $component): void
    {
        $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component($component));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
