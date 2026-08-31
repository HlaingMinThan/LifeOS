<?php

namespace App\Mail;

use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public TeamMember $invitation,
        public User $owner,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->owner->name} invited you to their Life OS team",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.team-invitation',
            with: [
                'url' => route('team.invitation.show', $this->invitation->token),
                'ownerName' => $this->owner->name,
            ],
        );
    }
}
