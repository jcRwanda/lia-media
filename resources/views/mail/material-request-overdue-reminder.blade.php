<x-mail::message>
# Overdue Return Reminder

Hello {{ $request->full_name }},

Your equipment for request **{{ $request->reference_code }}** was due to be returned by **{{ $request->return_by?->format('Y-m-d') }}** and is now overdue.

Please return the equipment as soon as possible.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
