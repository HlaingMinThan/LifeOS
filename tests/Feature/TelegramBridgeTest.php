<?php

namespace Tests\Feature;

use App\Models\InboxEvent;
use App\Models\Todo;
use App\Services\Telegram\InboxBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lifeos.parser' => 'fake',
            'lifeos.telegram.chat_id' => '12345',
        ]);
    }

    private function message(string $text, string $chatId = '12345'): array
    {
        return ['chat' => ['id' => $chatId], 'text' => $text];
    }

    public function test_text_is_parsed_and_applied_with_reply(): void
    {
        $reply = app(InboxBridge::class)->handle($this->message('mushroom idea မှတ်ထား'));

        $this->assertStringContainsString('✅ Idea parked', $reply);
        $this->assertSame(1, InboxEvent::where('applied', true)->count());
    }

    public function test_undo_reverts_latest_event(): void
    {
        $bridge = app(InboxBridge::class);
        $bridge->handle($this->message('buy dog food tomorrow'));
        $this->assertSame(1, Todo::count());

        $reply = $bridge->handle($this->message('/undo'));

        $this->assertStringContainsString('↩️ Undone', $reply);
        $this->assertSame(0, Todo::count());
    }

    public function test_unsure_parse_asks_instead_of_guessing(): void
    {
        $reply = app(InboxBridge::class)->handle($this->message('hi'));

        $this->assertStringContainsString('🤔', $reply);
        $this->assertSame(0, InboxEvent::count());
    }

    public function test_messages_from_other_chats_are_ignored(): void
    {
        $reply = app(InboxBridge::class)->handle($this->message('mushroom idea မှတ်ထား', '99999'));

        $this->assertNull($reply);
        $this->assertSame(0, InboxEvent::count());
    }

    public function test_multiline_message_applies_each_line(): void
    {
        $reply = app(InboxBridge::class)->handle($this->message(
            "mushroom idea မှတ်ထား\nbuy dog food tomorrow\nhi",
        ));

        $this->assertStringContainsString('✅ Idea parked', $reply);
        $this->assertStringContainsString('✅ Todo added', $reply);
        $this->assertStringContainsString('🤔 skipped: hi', $reply);
        $this->assertSame(2, InboxEvent::where('applied', true)->count());
        $this->assertSame(1, Todo::count());
    }

    public function test_today_returns_digest(): void
    {
        $reply = app(InboxBridge::class)->handle($this->message('/today'));

        $this->assertStringContainsString('Nothing needs you today', $reply);
    }
}
