<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Team\TeamService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Put someone on a team from the server, for when handing over an invite link
 * is impractical. Does everything the link does — creates the account if
 * needed, joins the team — so the result is a person who can actually log in.
 * Editing team_members by hand does not: an "accepted" row with no member_id
 * has no account behind it, so they can neither sign in nor be @mentioned.
 */
class AddTeamMember extends Command
{
    protected $signature = 'team:add {owner : email of the team owner} {member : email to add}';

    protected $description = 'Create (if needed) and add a member to an owner\'s team';

    public function handle(TeamService $team): int
    {
        $owner = User::where('email', $this->argument('owner'))->first();

        if (! $owner) {
            $this->error("No account for {$this->argument('owner')}.");

            return self::FAILURE;
        }

        try {
            $invitation = $team->addMember($owner, $this->argument('member'));
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $member = $invitation->member;

        $this->info("{$member->name} is on {$owner->name}'s team.");
        $this->table(['Field', 'Value'], [
            ['Login email', $member->email],
            ['Password', config('lifeos.invite_password').'  (change under Security)'],
            ['Mention as', '@'.$member->username],
        ]);

        return self::SUCCESS;
    }
}
