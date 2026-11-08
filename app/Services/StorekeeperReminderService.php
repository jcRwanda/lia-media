<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Support\BookingSchedule;
use Illuminate\Support\Collection;

/**
 * Admin-reviewed WhatsApp text for the storekeeper. Opening the link does not
 * send a message from the server and does not change the booking.
 */
class StorekeeperReminderService
{
    public function __construct(
        private MaterialRequestDecisionService $decisions,
        private BookingSchedule $schedule,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forBooking(MaterialRequest $request, string $purpose): array
    {
        $request->loadMissing('items.equipmentItem');
        $contact = $this->decisions->storekeeperContact();
        $base = $this->envelope($contact, $purpose);
        if ($base['setup_required']) {
            return $base;
        }

        $preview = match ($purpose) {
            'return' => $this->returnMessage($request),
            'confirm_return' => $this->confirmReturnMessage($request),
            default => $this->pickupMessage($request),
        };
        $base['preview'] = $preview;
        $base['whatsapp_url'] = 'https://wa.me/'.$contact['whatsapp'].'?text='.rawurlencode($preview);
        $base['staff_url'] = url('/material-requests/'.$request->id);
        $base['reference_code'] = $request->reference_code;

        return $base;
    }

    /**
     * @param  Collection<int, MaterialRequest>  $requests
     * @return array<string, mixed>
     */
    public function outstanding(Collection $requests): array
    {
        $contact = $this->decisions->storekeeperContact();
        $base = $this->envelope($contact, 'outstanding');
        $lines = [];
        foreach ($requests as $request) {
            $request->loadMissing('items.equipmentItem');
            $outstanding = $this->outstandingItems($request);
            if ($outstanding === []) {
                continue;
            }
            $due = $this->schedule->present($request->return_by, $request->return_by_at);
            $lines[] = [
                'id' => $request->id,
                'reference_code' => $request->reference_code,
                'borrower_name' => $request->full_name,
                'due' => $due,
                'outstanding' => $outstanding,
                'staff_url' => url('/material-requests/'.$request->id),
                'recorded_return' => false,
            ];
        }
        $base['bookings'] = $lines;
        if ($base['setup_required']) {
            return $base;
        }
        $text = "LIA store reminder\nOutstanding equipment (not a return record):\n";
        foreach ($lines as $line) {
            $items = collect($line['outstanding'])->map(fn (array $item) => $item['name'].' x'.$item['qty'])->implode(', ');
            $text .= '- '.$line['reference_code'].' '.$line['borrower_name'].': '.$items.' due '.$line['due']['label']."\n".$line['staff_url']."\n";
        }
        if ($lines === []) {
            $text .= "None.\n";
        }
        $base['preview'] = trim($text);
        $base['whatsapp_url'] = 'https://wa.me/'.$contact['whatsapp'].'?text='.rawurlencode($base['preview']);

        return $base;
    }

    /**
     * @return list<array{name: string, qty: int, serial: ?string}>
     */
    public function outstandingItems(MaterialRequest $request): array
    {
        return $request->items->map(function (MaterialRequestItem $item) {
            $issued = (int) ($item->qty_issued ?? 0);
            $returned = (int) ($item->qty_returned ?? 0);
            $qty = $issued - $returned;
            if ($qty <= 0) {
                return null;
            }

            return [
                'name' => $item->equipmentItem?->name ?? 'Item',
                'qty' => $qty,
                'serial' => $item->equipmentItem?->serial_number,
            ];
        })->filter()->values()->all();
    }

    /**
     * @param  array<string, mixed>|null  $contact
     * @return array<string, mixed>
     */
    private function envelope(?array $contact, string $purpose): array
    {
        if (! $contact || empty($contact['whatsapp'])) {
            return [
                'purpose' => $purpose,
                'setup_required' => true,
                'recipient' => null,
                'whatsapp_url' => null,
                'preview' => null,
                'sent' => false,
                'records_return' => false,
                'opens_external_whatsapp' => false,
                'guidance' => 'Set Storekeeper WhatsApp in Settings, or add a phone number on the storekeeper profile. The borrower’s number is never used for this reminder.',
            ];
        }

        return [
            'purpose' => $purpose,
            'setup_required' => false,
            'recipient' => [
                'role' => 'storekeeper',
                'name' => $contact['name'],
                'whatsapp' => $contact['whatsapp'],
            ],
            'sent' => false,
            'records_return' => false,
            'opens_external_whatsapp' => true,
            'guidance' => null,
        ];
    }

    private function pickupMessage(MaterialRequest $request): string
    {
        $when = $this->schedule->present($request->needed_from, $request->needed_from_at);

        return $this->logisticsMessage($request, 'Hand-out summary', 'Pick-up '.$when['label'], 'Please prepare this booking. Opening WhatsApp does not mark it handed out.');
    }

    private function returnMessage(MaterialRequest $request): string
    {
        $when = $this->schedule->present($request->return_by, $request->return_by_at);

        return $this->logisticsMessage($request, 'Return reminder', 'Due '.$when['label'], 'Ask for this equipment back, or record the return in LIA if it is already in. This message does not record a return.');
    }

    private function confirmReturnMessage(MaterialRequest $request): string
    {
        $when = $this->schedule->present($request->return_by, $request->return_by_at);

        return $this->logisticsMessage($request, 'Confirm return in LIA', 'Due '.$when['label'], 'If the equipment is back, open the booking and check it in with photo proof. WhatsApp does not confirm the return.');
    }

    private function logisticsMessage(MaterialRequest $request, string $title, string $when, string $instruction): string
    {
        $items = collect($this->outstandingItems($request));
        if ($items->isEmpty()) {
            $items = $request->items->map(fn (MaterialRequestItem $item) => [
                'name' => $item->equipmentItem?->name ?? 'Item',
                'qty' => (int) ($item->qty_approved ?? $item->qty_requested ?? 1),
                'serial' => $item->equipmentItem?->serial_number,
            ]);
        }
        $lines = $items->map(function (array $item) {
            $serial = $item['serial'] ? ' SN '.$item['serial'] : '';

            return '- '.$item['name'].' x'.$item['qty'].$serial;
        })->implode("\n");

        return $title."\n"
            .'Ref: '.$request->reference_code."\n"
            .'Borrower: '.$request->full_name."\n"
            .$when."\n"
            .$lines."\n"
            .$instruction."\n"
            .url('/material-requests/'.$request->id);
    }
}
