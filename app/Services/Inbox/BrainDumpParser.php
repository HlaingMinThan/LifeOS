<?php

namespace App\Services\Inbox;

use Throwable;

/**
 * Onboarding: one pasted brain dump → one parsed action per line.
 * Reuses the magic-box parser (and its learned examples) line by line,
 * so onboarding and daily use can never drift apart.
 */
class BrainDumpParser
{
    public function __construct(private ParserContract $parser) {}

    public function parse(string $text): array
    {
        return collect(preg_split('/\r?\n/', $text))
            ->map(fn ($line) => trim(preg_replace('/^[-•*၊။]+\s*/u', '', trim($line))))
            ->filter()
            ->values()
            ->map(function (string $line) {
                try {
                    $parsed = $this->parser->parse($line);
                } catch (Throwable) {
                    $parsed = [
                        'action' => 'unknown', 'target' => $line, 'amount_mmk' => null,
                        'due' => null, 'bucket' => null, 'confidence' => 0,
                    ];
                }

                return ['raw_text' => $line, 'parsed' => $parsed];
            })
            ->all();
    }
}
