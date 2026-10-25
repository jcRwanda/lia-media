<x-mail::message>
# Request Submitted

Hello {{ $request->full_name }},

Your material/equipment request has been submitted successfully.

**Reference Code:** {{ $request->reference_code }}  
**Status:** {{ ucfirst($request->status) }}  
**Requester Type:** {{ ucfirst($request->requester_type) }}

You can check your request status using your reference code and email/phone.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
