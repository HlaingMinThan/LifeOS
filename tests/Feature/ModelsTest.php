<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_contact_prompt_label_includes_aliases(): void
    {
        $contact = Contact::factory()->for($this->user)->create([
            'name' => 'Gon Khaung',
            'aliases' => ['ဂွန်ခေါင်'],
        ]);

        $this->assertSame('Gon Khaung (aliases: ဂွန်ခေါင်)', $contact->promptLabel());
    }

    public function test_ledger_scopes(): void
    {
        LedgerEntry::factory()->for($this->user)->create(['direction' => 'payable', 'status' => 'open']);
        LedgerEntry::factory()->for($this->user)->paid()->create(['direction' => 'payable']);
        LedgerEntry::factory()->for($this->user)->create(['direction' => 'receivable', 'status' => 'open']);

        $this->assertSame(1, LedgerEntry::open()->payable()->count());
        $this->assertSame(1, LedgerEntry::open()->receivable()->count());
    }

    public function test_todo_overdue_scope(): void
    {
        Todo::factory()->for($this->user)->create(['due_date' => today()->subDay()]);
        Todo::factory()->for($this->user)->create(['due_date' => today()->addDay()]);
        Todo::factory()->for($this->user)->done()->create(['due_date' => today()->subDay()]);

        $this->assertSame(1, Todo::overdue()->count());
    }

    public function test_daily_care_task_schedules_next_day_at_time(): void
    {
        $task = CareTask::factory()->for($this->user)->create(['time_of_day' => '07:30:00']);
        $next = $task->nextRunAfter(Carbon::parse('2026-07-03 07:30:00'));

        $this->assertSame('2026-07-04 07:30:00', $next->toDateTimeString());
    }

    public function test_random_care_task_stays_within_bounds(): void
    {
        $task = CareTask::factory()->for($this->user)->random(7, 20)->create();
        $from = Carbon::parse('2026-07-03 09:00:00');

        for ($i = 0; $i < 20; $i++) {
            $days = (int) $from->copy()->startOfDay()
                ->diffInDays($task->nextRunAfter($from)->startOfDay());
            $this->assertGreaterThanOrEqual(7, $days);
            $this->assertLessThanOrEqual(20, $days);
        }
    }

    public function test_inbox_event_round_trips_parsed_json(): void
    {
        $event = $this->user->inboxEvents()->create([
            'raw_text' => 'paid gon khaung 500k',
            'parsed_json' => ['action' => 'mark_paid', 'target' => 'Gon Khaung', 'amount_mmk' => 500000],
        ]);

        $this->assertSame('mark_paid', $event->fresh()->parsed_json['action']);
    }
}
