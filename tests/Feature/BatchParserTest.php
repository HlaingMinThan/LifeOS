<?php

namespace Tests\Feature;

use App\Services\Inbox\BrainDumpParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BatchParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_claude_batch_mode_parses_whole_dump_in_one_call(): void
    {
        config(['lifeos.parser' => 'claude', 'lifeos.anthropic.key' => 'test-key']);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        ['raw' => 'မနက် ၁၀ နာရီ မုန့်သွားစားရန်', 'action' => 'add_todo', 'target' => 'မနက် ၁၀ နာရီ မုန့်သွားစားရန်', 'due' => '2026-07-05', 'bucket' => 'personal', 'confidence' => 0.85],
                        ['raw' => '၁၁ နာရီ ရေချိုးရန်', 'action' => 'add_todo', 'target' => '၁၁ နာရီ ရေချိုးရန်', 'due' => '2026-07-05', 'bucket' => 'personal', 'confidence' => 0.85],
                    ], JSON_UNESCAPED_UNICODE),
                ]],
            ]),
        ]);

        $items = app(BrainDumpParser::class)->parse("မနက်ဖြန်\nမနက် ၁၀ နာရီ မုန့်သွားစားရန်\n၁၁ နာရီ ရေချိုးရန်");

        Http::assertSentCount(1); // one batch call, not one per line
        $this->assertCount(2, $items); // header folded in, not an item
        $this->assertSame('2026-07-05', $items[0]['parsed']['due']);
        $this->assertSame('2026-07-05', $items[1]['parsed']['due']);
        $this->assertSame('မနက် ၁၀ နာရီ မုန့်သွားစားရန်', $items[0]['raw_text']);
    }

    public function test_batch_failure_falls_back_to_line_by_line(): void
    {
        config(['lifeos.parser' => 'claude', 'lifeos.anthropic.key' => 'test-key']);

        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $items = app(BrainDumpParser::class)->parse("line one here\nline two here");

        // Batch call failed; per-line calls also fail → unknown placeholders,
        // but the dump still returns one reviewable row per line.
        $this->assertCount(2, $items);
        $this->assertSame('unknown', $items[0]['parsed']['action']);
    }
}
