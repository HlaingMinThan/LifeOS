<?php

namespace App\Services\Team;

use App\Models\User;

/**
 * Pulls a leading "@handle" out of a magic-box command and resolves it to a
 * teammate. Done in PHP rather than taught to the parser: a mention is exact
 * (it must match one account or none), and resolving it here keeps the prompt —
 * and its cost — unchanged.
 */
class MentionResolver
{
    private const PATTERN = '/(?:^|\s)@([A-Za-z0-9._-]+)/u';

    /**
     * @return array{assignee: User|null, handle: string|null, text: string}
     *                                                                       `text` is the command with the mention removed, ready to parse.
     */
    public function resolve(string $text, User $owner): array
    {
        if (! preg_match(self::PATTERN, $text, $match)) {
            return ['assignee' => null, 'handle' => null, 'text' => $text];
        }

        $handle = $match[1];
        $stripped = trim(preg_replace(self::PATTERN, ' ', $text, 1) ?? $text);
        // Collapse the gap the mention left behind.
        $stripped = (string) preg_replace('/\s+/u', ' ', $stripped);

        return [
            'assignee' => $this->matchTeammate($handle, $owner),
            'handle' => $handle,
            'text' => $stripped,
        ];
    }

    public function hasMention(string $text): bool
    {
        return (bool) preg_match(self::PATTERN, $text);
    }

    /**
     * Only accepted teammates resolve. An unknown or not-yet-accepted handle
     * returns null so the caller can say so, rather than silently keeping the
     * task for the sender.
     */
    private function matchTeammate(string $handle, User $owner): ?User
    {
        $handle = mb_strtolower($handle);

        return $owner->teammates()->first(function (User $mate) use ($handle) {
            if (mb_strtolower((string) $mate->username) === $handle) {
                return true;
            }

            // Tolerate "@zayar.win" or the email local part for the same person.
            $normalized = preg_replace('/[^a-z0-9]/', '', $handle);
            $nameSlug = preg_replace('/[^a-z0-9]/', '', mb_strtolower($mate->name));
            $emailLocal = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) str($mate->email)->before('@')));

            return $normalized !== '' && in_array($normalized, [$nameSlug, $emailLocal], true);
        });
    }
}
