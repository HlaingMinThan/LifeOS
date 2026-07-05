<?php

namespace App\Services\Inbox;

use Throwable;

/**
 * A pasted brain dump → one parsed action per item. With the Claude
 * parser this is a single batch call, so header lines (a date, a person)
 * give context to the lines below them; otherwise falls back to parsing
 * line by line with the bound parser.
 */
class BrainDumpParser
{
    public function __construct(private ParserContract $parser) {}

    public function parse(string $text): array
    {
        if ($this->parser instanceof ClaudeParser) {
            try {
                return $this->parser->parseMany($text);
            } catch (Throwable $e) {
                report($e); // fall through to line-by-line
            }
        }

        return collect(preg_split('/\r?\n/', $text))
            ->map(fn ($line) => trim(preg_replace('/^[-–—⁃•*၊။]+\s*/u', '', trim($line))))
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
