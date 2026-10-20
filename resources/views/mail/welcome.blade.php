<x-mail::message>
# Welcome to LIA Media Archive

Hi {{ $user->name }},

Your staff account for the Love In Arts Media Archive has been created. Here are your sign-in details:

**Name:** {{ $user->name }}

**Login email:** {{ $user->email }}

**Temporary password:** `{{ $temporaryPassword }}`

<x-mail::button :url="$loginUrl">
Sign in to the archive
</x-mail::button>

**You must change this temporary password the first time you sign in.** Sign in with the button above (or open {{ $loginUrl }}), then choose a new password of at least 10 characters. Until you do, the archive stays locked for your account.

If the button doesn't work, copy and paste this URL into your browser: {{ $loginUrl }}

Thanks,<br>
{{ config('app.name') }} — Love In Arts
</x-mail::message>
