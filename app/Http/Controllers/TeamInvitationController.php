<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\User;
use App\Services\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamInvitationController extends Controller
{
    public function __construct(private TeamService $team) {}

    /**
     * Landing page for an invite link. A brand-new invitee is signed up and
     * logged in on the spot — no form — so the link is the whole onboarding.
     */
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invitation = TeamMember::where('token', $token)->with('owner')->first();

        // A stale link (withdrawn, or from before this invite was reissued)
        // deserves an explanation rather than a bare 404 — the person opening
        // it did nothing wrong and has no idea what happened.
        if (! $invitation) {
            return $this->invalidPage($token);
        }

        if (! $request->user()) {
            $existing = User::where('email', $invitation->email)->first();

            // An invite link must never be a way into an account that already
            // exists — that would make a forwarded link an account takeover.
            // Those people sign in normally and come back here to accept.
            if ($existing) {
                $request->session()->put('url.intended', route('team.invitation.show', $token));

                return redirect()->route('login', ['email' => $invitation->email]);
            }

            return $this->createAccountAndAccept($invitation);
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

    /**
     * Sign the invitee up and log them straight in. The invite token is the
     * credential — it went to their address, which is also why the account is
     * treated as verified (otherwise they could not reach the password page to
     * replace the default).
     */
    private function createAccountAndAccept(TeamMember $invitation): RedirectResponse
    {
        $password = (string) config('lifeos.invite_password');
        $user = $this->team->createAccountFor($invitation->email);

        $this->team->accept($invitation->token, $user);

        Auth::login($user, remember: true);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __("Welcome! Your password is \"{$password}\" — change it under Security."),
        ]);

        // Land on settings the first (and only) time this runs, so their name
        // and password are the first things they can put right. Later sign-ins
        // go to the Life OS home like everyone else.
        return redirect()->route('profile.edit');
    }

    private function invalidPage(string $token): Response
    {
        return Inertia::render('os/Invitation', [
            'token' => $token,
            'ownerName' => null,
            'email' => null,
            'status' => 'invalid',
            'mismatch' => false,
        ]);
    }
}
