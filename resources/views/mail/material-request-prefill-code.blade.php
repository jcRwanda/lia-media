<x-mail::message>
# Prefill your last request

Hello {{ $request->full_name }},

Use this one-time code to prefill your address, emergency contact and ID from your last material request:

**{{ $code }}**

The code expires in {{ intdiv((int) config('material-requests.otp_ttl'), 60) }} minutes. If you did not ask for this, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
