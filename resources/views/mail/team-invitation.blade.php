@component('mail::message')
# {{ $ownerName }} invited you to their team

They can send you tasks, which land in your own Life OS todo list. Only the
tasks they assign are visible to them — the rest of your Life OS stays private.

@component('mail::button', ['url' => $url])
Accept invitation
@endcomponent

If the button does not work, open this link:
{{ $url }}

@endcomponent
