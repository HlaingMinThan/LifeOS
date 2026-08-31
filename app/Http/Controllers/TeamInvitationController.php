<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Services\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    public function __construct(private TeamService $team) {}

    /**
     * Landing page for an invite link. Guests are sent to register/login first
     * with the token remembered, so accepting works before an account exists.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = TeamMember::where('token', $token)->with('owner')->first();

        // A stale link (withdrawn, or from before this invite was reissued)
        // deserves an explanation rather than a bare 404 — the person opening
        // it did nothing wrong and has no idea what happened.
        if (! $invitation) {
            return Inertia::render('os/Invitation', [
                'token' => $token,
                'ownerName' => null,
                'email' => null,
                'status' => 'invalid',
                'mismatch' => false,
            ]);
        }

        if (! $request->user()) {
            // Fortify consumes url.intended after login *or* registration, so
            // whichever route they take, they come back here to accept.
            $request->session()->put('url.intended', route('team.invitation.show', $token));

            return redirect()->route('register', ['email' => $invitation->email]);
        }

        return Inertia::render('os/Invitation', [
            'token' => $token,
            'ownerName' => $invitation->owner?->name,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'mismatch' => mb_strtolower($invitation->email) !== mb_strtolower($request->user()->email),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        try {
            $this->team->accept($token, $request->user());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('team.index');
    }
}
