<x-mail::message>
# Equipment Issued

Hello {{ $request->full_name }},

Equipment for your request **{{ $request->reference_code }}** has been issued.

**Return By:** {{ $request->return_by?->format('Y-m-d') }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
