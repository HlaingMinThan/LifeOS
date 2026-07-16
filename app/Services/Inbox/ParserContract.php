<?php

namespace App\Services\Inbox;

use App\Models\User;

interface ParserContract
{
    /**
     * Convert one life-management command into the action schema:
     * ['action' => ..., 'target' => ..., 'amount_mmk' => int|null,
     *  'due' => 'Y-m-d'|null, 'bucket' => 'work'|'personal'|null,
     *  'confidence' => float]
     *
     * $user owns the command: matching and the prompt snapshot resolve
     * against their records only.
     */
    public function parse(string $text, User $user): array;
}
