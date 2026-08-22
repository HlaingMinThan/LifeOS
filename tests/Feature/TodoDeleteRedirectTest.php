<?php

namespace Tests\Feature;

use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodoDeleteRedirectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_deleting_from_the_detail_page_lands_on_the_day_not_a_404(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['due_date' => '2026-08-18']);

        $this->actingAs($this->user)
            ->delete("/todos/{$todo->id}?from=detail")
            ->assertRedirect('/todos/day/2026-08-18');

        // The destination must actually render — that is the whole bug.
        $this->actingAs($this->user)->get('/todos/day/2026-08-18')->assertOk();
        $this->assertSoftDeleted($todo);
    }

    public function test_detail_delete_works_without_a_referer_header(): void
    {
        // Referrer-Policy can strip the path; the explicit flag must carry it.
        $todo = Todo::factory()->for($this->user)->create(['due_date' => null]);

        $this->actingAs($this->user)
            ->delete("/todos/{$todo->id}?from=detail")
            ->assertRedirect('/todos/day/undated');
    }

    public function test_a_stale_bundle_without_the_flag_still_recovers_via_referer(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['due_date' => '2026-08-18']);

        $this->actingAs($this->user)
            ->from("/todos/{$todo->id}")
            ->delete("/todos/{$todo->id}")
            ->assertRedirect('/todos/day/2026-08-18');
    }

    public function test_deleting_from_the_day_list_keeps_your_place(): void
    {
        $todo = Todo::factory()->for($this->user)->create(['due_date' => '2026-08-18']);

        $this->actingAs($this->user)
            ->from('/todos/day/2026-08-18')
            ->delete("/todos/{$todo->id}")
            ->assertRedirect('/todos/day/2026-08-18');
    }
}
