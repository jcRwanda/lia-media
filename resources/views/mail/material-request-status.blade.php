<x-mail::message>
# Request Update

Hello {{ $request->full_name }},

Your material/equipment request **{{ $request->reference_code }}** status has been updated to: **{{ ucfirst($request->status) }}**.

@if($request->review_note)
**Note:** {{ $request->review_note }}
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
