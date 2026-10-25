<x-mail::message>
# New Material Request

A new material/equipment request has been submitted.

**Reference Code:** {{ $request->reference_code }}  
**Requester:** {{ $request->full_name }} ({{ $request->email }})  
**Type:** {{ ucfirst($request->requester_type) }}

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
