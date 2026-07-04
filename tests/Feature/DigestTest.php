<?php

namespace Tests\Feature;

use App\Models\CareTask;
use App\Models\Contact;
use App\Models\LedgerEntry;
use App\Models\Todo;
use App\Services\DigestBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_digest_contains_all_sections(): void
    {
        $contact = Contact::factory()->create(['name' => 'Gon Khaung']);
        LedgerEntry::factory()->create([
            'contact_id' => $contact->id, 'direction' => 'payable', 'amount_mmk' => 500000,
        ]);
        LedgerEntry::factory()->create(['direction' => 'receivable', 'title' => 'Cargo Pro fee', 'amount_mmk' => 780000]);
        Todo::factory()->create(['title' => 'Renew insurance', 'due_date' => today()->subDays(2)]);
        Todo::factory()->create(['title' => 'Ship order', 'due_date' => today()]);
        CareTask::factory()->create(['title' => 'Send flowers', 'next_run_at' => now()]);

        $digest = app(DigestBuilder::class)->build();

        $this->assertStringContainsString('Send flowers', $digest);
        $this->assertStringContainsString('Renew insurance', $digest);
        $this->assertStringContainsString('Ship order', $digest);
        $this->assertStringContainsString('Gon Khaung — 500,000 Ks', $digest);
        $this->assertStringContainsString('Cargo Pro fee — 780,000 Ks', $digest);
    }

    public function test_empty_digest_celebrates(): void
    {
        $this->assertStringContainsString('Nothing needs you today', app(DigestBuilder::class)->build());
    }
}
