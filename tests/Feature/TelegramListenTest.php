<?php

namespace Tests\Feature;

use App\Models\InboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramListenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lifeos.parser' => 'fake',
            'lifeos.telegram.token' => 'test-token',
            'lifeos.telegram.chat_id' => '12345',
        ]);
    }

    public function test_same_update_id_is_processed_only_once(): void
    {
        // Every getUpdates poll returns the SAME update — simulating a
        // second concurrent listener or a Telegram redelivery.
        Http::fake([
            'api.telegram.org/*getUpdates*' => Http::response([
                'result' => [[
                    'update_id' => 900,
                    'message' => ['chat' => ['id' => 12345], 'text' => 'mushroom idea test'],
                ]],
            ]),
            'api.telegram.org/*sendMessage*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('telegram:listen --once')->assertSuccessful();
        $this->artisan('telegram:listen --once')->assertSuccessful();

        // Two deliveries of update_id 900, but exactly one applied action.
        $this->assertSame(1, InboxEvent::where('applied', true)->count());
    }

    public function test_second_long_running_instance_exits_immediately(): void
    {
        Http::fake();
        // A live ownership heartbeat means one listener is already running.
        Cache::add('telegram:listen:owner', 'other-instance', 120);

        // The new (long-running, no --once) listener must bail before polling
        // instead of running a second forever-loop alongside the first.
        $this->artisan('telegram:listen')
            ->expectsOutputToContain('already running')
            ->assertSuccessful();

        // It never touched Telegram.
        Http::assertNothingSent();
    }
}
