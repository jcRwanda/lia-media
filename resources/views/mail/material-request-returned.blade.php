<x-mail::message>
# Equipment Returned

Hello {{ $request->full_name }},

Equipment for your request **{{ $request->reference_code }}** has been recorded as returned.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
