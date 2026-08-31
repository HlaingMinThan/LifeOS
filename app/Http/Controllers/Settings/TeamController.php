<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Services\Team\TeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(private TeamService $team) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('os/Team', [
            'members' => $user->teamMembers()->with('member')->latest()->get()
                ->map(fn (TeamMember $m) => $this->present($m, $request)),
            // Teams I belong to, so it is visible who can assign work to me.
            'memberOf' => $user->teamMemberships()->accepted()->with('owner')->get()
                ->map(fn (TeamMember $m) => [
                    'id' => $m->id,
                    'owner_name' => $m->owner?->name,
                    'owner_email' => $m->owner?->email,
                ]),
            'myUsername' => $user->username,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->team->invite($request->user(), $validated['email']);

        return back();
    }

    /** The member's page: only the todos this owner assigned to them. */
    public function show(Request $request, int $member): Response
    {
        $invitation = $request->user()->teamMembers()->with('member')->findOrFail($member);
        abort_unless($invitation->isAccepted() && $invitation->member, 404);

        $todos = $request->user()->assignedTodos()
            ->where('user_id', $invitation->member_id)
            ->orderByRaw("status = 'open' desc")
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get();

        return Inertia::render('os/TeamMember', [
            'member' => $this->present($invitation, $request),
            'todos' => $todos,
            'openCount' => $todos->where('status', 'open')->count(),
            'doneCount' => $todos->where('status', 'done')->count(),
        ]);
    }

    public function destroy(Request $request, int $member): RedirectResponse
    {
        $invitation = $request->user()->teamMembers()->findOrFail($member);

        $this->team->revoke($invitation);

        return redirect()->route('team.index');
    }

    /** Re-send: a fresh token, and another mail attempt. */
    public function resend(Request $request, int $member): RedirectResponse
    {
        $invitation = $request->user()->teamMembers()->findOrFail($member);

        $this->team->invite($request->user(), $invitation->email);

        return back();
    }

    /** @return array<string, mixed> */
    private function present(TeamMember $invitation, Request $request): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $invitation->status,
            'name' => $invitation->displayName(),
            'username' => $invitation->member?->username,
            'has_account' => $invitation->member_id !== null,
            'invite_url' => $invitation->status === 'pending'
                ? route('team.invitation.show', $invitation->token)
                : null,
            'open_count' => $invitation->member_id
                ? $request->user()->assignedTodos()
                    ->where('user_id', $invitation->member_id)->where('status', 'open')->count()
                : 0,
        ];
    }
}
