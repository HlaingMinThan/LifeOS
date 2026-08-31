<?php

namespace App\Services\Team;

use App\Mail\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeamService
{
    public function __construct(private TelegramClient $telegram) {}

    /**
     * Invite by email. The invitee may not have an account yet, which is why
     * the invitation is keyed on the address and only linked to a user later.
     */
    public function invite(User $owner, string $email): TeamMember
    {
        $email = mb_strtolower(trim($email));

        if ($email === mb_strtolower($owner->email)) {
            throw ValidationException::withMessages([
                'email' => 'That is your own account.',
            ]);
        }

        $existing = $owner->teamMembers()->where('email', $email)->first();

        if ($existing?->isAccepted()) {
            throw ValidationException::withMessages([
                'email' => 'They are already on your team.',
            ]);
        }

        $invitee = User::where('email', $email)->first();

        // Re-inviting a revoked or still-pending address reissues the token
        // rather than colliding with the (owner_id, email) unique key.
        $invitation = $existing ?? $owner->teamMembers()->make();
        $invitation->fill([
            'email' => $email,
            'member_id' => $invitee?->id,
            'status' => 'pending',
            'token' => TeamMember::newToken(),
            'accepted_at' => null,
        ]);
        $invitation->owner()->associate($owner);
        $invitation->save();

        $this->deliver($invitation, $owner, $invitee);

        return $invitation;
    }

    /** Accept by token. The signed-in account becomes the member. */
    public function accept(string $token, User $user): TeamMember
    {
        $invitation = TeamMember::where('token', $token)->firstOrFail();

        if ($invitation->status === 'revoked') {
            throw ValidationException::withMessages([
                'token' => 'That invitation was withdrawn.',
            ]);
        }

        if ($invitation->owner_id === $user->id) {
            throw ValidationException::withMessages([
                'token' => 'You cannot join your own team.',
            ]);
        }

        // The link is the credential, but the address must still line up so a
        // forwarded invite cannot be claimed by whoever opens it first.
        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages([
                'token' => "This invitation was sent to {$invitation->email}. Sign in with that account to accept it.",
            ]);
        }

        $invitation->member()->associate($user);
        $invitation->status = 'accepted';
        $invitation->accepted_at = now();
        $invitation->save();

        $this->notify(
            $invitation->owner,
            "🤝 {$user->name} joined your team. Assign them work with @{$user->username}",
        );

        return $invitation;
    }

    /**
     * Leave the team's todos alone: they belong to the member's own Life OS.
     * Revoking only ends the relationship (and with it the owner's view).
     */
    public function revoke(TeamMember $invitation): void
    {
        $invitation->update(['status' => 'revoked', 'accepted_at' => null]);
    }

    private function deliver(TeamMember $invitation, User $owner, ?User $invitee): void
    {
        try {
            Mail::to($invitation->email)->send(new TeamInvitation($invitation, $owner));
        } catch (Throwable $e) {
            // Mail may be unconfigured; the copyable link in the UI is the
            // reliable path, so a failed send must not lose the invitation.
            report($e);
        }

        if ($invitee) {
            $this->notify($invitee, "🤝 {$owner->name} invited you to their Life OS team.");
        }
    }

    private function notify(?User $user, string $message): void
    {
        if (! $user?->hasTelegram()) {
            return;
        }

        try {
            $this->telegram->forUser($user)->send($message);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
