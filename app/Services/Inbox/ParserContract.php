<?php

namespace App\Services\Inbox;

interface ParserContract
{
    /**
     * Convert one life-management command into the action schema:
     * ['action' => ..., 'target' => ..., 'amount_mmk' => int|null,
     *  'due' => 'Y-m-d'|null, 'bucket' => 'work'|'personal'|null,
     *  'confidence' => float]
     */
    public function parse(string $text): array;
}
