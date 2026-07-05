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

    public function test_money_reply_always_shows_a_date_for_confirmation(): void
    {
        $bridge = app(InboxBridge::class);

        // Fake parser sets no due date → the reply must say so explicitly.
        $reply = $bridge->handle($this->message('arkar ဆီက 1 သိန်း ရစရာရှိတယ်'));

        $this->assertStringContainsString('✅ Income', $reply);
        $this->assertStringContainsString('📅 no date', $reply);
    }

    public function test_care_command_lists_tasks_with_schedules(): void
    {
        \App\Models\CareTask::factory()->random(7, 20)->create(['title' => 'Send flowers']);
        \App\Models\CareTask::factory()->create(['title' => 'Paused one', 'active' => false]);

        $reply = app(InboxBridge::class)->handle($this->message('/care'));

        $this->assertStringContainsString('Send flowers (every 7–20 days 🎲)', $reply);
        $this->assertStringContainsString('Paused one', $reply);
        $this->assertStringContainsString('paused', $reply);
    }

    public function test_idea_command_lists_parking_lot(): void
    {
        \App\Models\Idea::factory()->create(['title' => 'mushroom idea']);

        $reply = app(InboxBridge::class)->handle($this->message('/idea'));

        $this->assertStringContainsString('💡 Ideas:', $reply);
        $this->assertStringContainsString('mushroom idea', $reply);
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

    public function test_bare_word_commands_work_without_slash(): void
    {
        $bridge = app(InboxBridge::class);

        $this->assertStringContainsString('Nothing needs you today', $bridge->handle($this->message('today')));
        $this->assertStringContainsString('Nothing needs you today', $bridge->handle($this->message('Tdy')));
        $this->assertStringContainsString('Nothing on this day', $bridge->handle($this->message('tmr')));
        $this->assertStringContainsString('Nothing to undo', $bridge->handle($this->message('undo')));
        // Bare command words must NOT become todos.
        $this->assertSame(0, InboxEvent::count());
    }

    public function test_tomorrow_previews_next_day(): void
    {
        Todo::factory()->create(['title' => 'laundry တင်ရန်', 'due_date' => today()->addDay()]);
        Todo::factory()->create(['title' => 'not tomorrow', 'due_date' => today()->addDays(3)]);

        $reply = app(InboxBridge::class)->handle($this->message('/tomorrow'));

        $this->assertStringContainsString('laundry တင်ရန်', $reply);
        $this->assertStringNotContainsString('not tomorrow', $reply);
    }

    public function test_yesterday_shows_done_and_open_marks(): void
    {
        Todo::factory()->done()->create(['title' => 'finished thing', 'due_date' => today()->subDay()]);
        Todo::factory()->create(['title' => 'missed thing', 'due_date' => today()->subDay()]);

        $reply = app(InboxBridge::class)->handle($this->message('/yesterday'));

        $this->assertStringContainsString('✅ finished thing', $reply);
        $this->assertStringContainsString('⭕ missed thing', $reply);
    }

    public function test_todobydate_asks_then_answers(): void
    {
        Todo::factory()->create(['title' => 'monday plan', 'due_date' => '2026-07-06']);
        $bridge = app(InboxBridge::class);

        $ask = $bridge->handle($this->message('/todobydate'));
        $this->assertStringContainsString('Which date?', $ask);

        $reply = $bridge->handle($this->message('July 6'));
        $this->assertStringContainsString('Mon, 6 Jul 2026', $reply);
        $this->assertStringContainsString('monday plan', $reply);

        // The date answer must not have been treated as an inbox command.
        $this->assertSame(0, InboxEvent::count());
    }

    public function test_todobydate_reprompts_on_bad_date(): void
    {
        $bridge = app(InboxBridge::class);
        $bridge->handle($this->message('/todobydate'));

        $retry = $bridge->handle($this->message('blah blah blah'));
        $this->assertStringContainsString("Couldn't read that date", $retry);

        $reply = $bridge->handle($this->message('6.7'));
        $this->assertStringContainsString('6 Jul 2026', $reply);
    }
}
