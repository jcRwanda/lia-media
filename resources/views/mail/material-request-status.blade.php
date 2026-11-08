@php($decision = app(\App\Services\MaterialRequestDecisionService::class)->decision($request))
<x-mail::message>
# Request Update

Hello {{ $request->full_name }},

@switch($request->status)
@case('approved')
Good news: your material/equipment request **{{ $request->reference_code }}** has been **approved**.
@break
@case('rejected')
Your material/equipment request **{{ $request->reference_code }}** was **not approved**.
@break
@case('changes_requested')
Your material/equipment request **{{ $request->reference_code }}** needs **some changes** before it can be approved.
@break
@default
Your material/equipment request **{{ $request->reference_code }}** status has been updated to: **{{ ucfirst(str_replace('_', ' ', $request->status)) }}**.
@endswitch

@if($request->review_note)
**{{ $request->status === 'rejected' ? 'Reason' : 'Note' }}:** {{ $request->review_note }}
@endif

@if(in_array($request->status, ['approved', 'issued'], true) && !empty($decision['storekeeper']['whatsapp_url']))
**Pick-up contact:** {{ $decision['storekeeper']['name'] }} ({{ $decision['storekeeper']['phone'] }})

<x-mail::button :url="$decision['storekeeper']['whatsapp_url']" color="success">
Message the storekeeper on WhatsApp
</x-mail::button>
@endif

@if($decision['status_url'])
<x-mail::button :url="$decision['status_url']">
{{ $request->status === 'changes_requested' ? 'Edit and resubmit' : 'View request status' }}
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
