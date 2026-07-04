<?php

namespace Tests\Feature;

use App\Models\Idea;
use App\Models\InboxEvent;
use App\Models\LedgerEntry;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config(['lifeos.parser' => 'fake']);
    }

    public function test_dump_parses_each_line_without_writing(): void
    {
        $response = $this->actingAs($this->user)->postJson('/onboard/dump', [
            'text' => "arkar ဆီက 1 သိန်း ရစရာ\n- mushroom idea မှတ်ထား\n• buy dog food",
        ])->assertOk();

        $items = $response->json('items');
        $this->assertCount(3, $items);
        $this->assertSame('add_receivable', $items[0]['parsed']['action']);
        $this->assertSame('add_idea', $items[1]['parsed']['action']);
        $this->assertSame('mushroom idea မှတ်ထား', $items[1]['raw_text']); // bullet stripped
        $this->assertSame(0, InboxEvent::count());
    }

    public function test_confirm_applies_reviewed_items(): void
    {
        $this->actingAs($this->user)->postJson('/onboard/confirm', [
            'items' => [
                ['raw_text' => 'arkar owes me', 'parsed' => ['action' => 'add_receivable', 'target' => 'Arkar', 'amount_mmk' => 100000]],
                ['raw_text' => 'mushroom idea', 'parsed' => ['action' => 'add_idea', 'target' => 'mushroom idea']],
                ['raw_text' => 'buy dog food', 'parsed' => ['action' => 'add_todo', 'target' => 'buy dog food', 'bucket' => 'personal']],
            ],
        ])->assertOk()->assertJsonPath('applied', 3);

        $this->assertSame(1, LedgerEntry::count());
        $this->assertSame(1, Idea::count());
        $this->assertSame(1, Todo::count());
        $this->assertSame(3, InboxEvent::where('applied', true)->count());
    }

    public function test_confirm_reports_failures_without_aborting(): void
    {
        $response = $this->actingAs($this->user)->postJson('/onboard/confirm', [
            'items' => [
                ['raw_text' => 'paid ghost 500k', 'parsed' => ['action' => 'mark_paid', 'target' => 'Ghost']],
                ['raw_text' => 'mushroom idea', 'parsed' => ['action' => 'add_idea', 'target' => 'mushroom idea']],
            ],
        ])->assertOk();

        $this->assertSame(1, $response->json('applied'));
        $this->assertSame(['paid ghost 500k'], $response->json('failed'));
    }
}
