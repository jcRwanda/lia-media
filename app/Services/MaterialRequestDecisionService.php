<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Support\Roles;

class MaterialRequestDecisionService
{
    /**
     * Borrower may open their own WhatsApp chat to the storekeeper only while the
     * request is admin-approved, including after it is issued for pickup.
     * submitted, changes_requested, rejected, returned, and closed do not expose the number.
     *
     * @var list<string>
     */
    public const BORROWER_STOREKEEPER_CONTACT_STATUSES = ['approved', 'issued'];

    public function __construct(private SettingsService $settings) {}

    public function record(MaterialRequest $request, ?string $from, string $to, ?string $note, ?User $actor = null, string $actorType = 'user'): MaterialRequestStatusHistory
    {
        return MaterialRequestStatusHistory::query()->create([
            'material_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? $actorType : ($actorType === 'user' ? 'system' : $actorType),
            'created_at' => now(),
        ]);
    }

    public function statusUrl(MaterialRequest $request): ?string
    {
        return $request->uuid ? url('/request-status/'.$request->uuid) : null;
    }

    /** Storekeeper contact: settings override, else first active storekeeper with a profile phone. */
    public function storekeeperContact(): ?array
    {
        $phone = trim((string) $this->settings->get('storekeeper_whatsapp'));
        $name = trim((string) $this->settings->get('storekeeper_contact_name'));
        $email = null;

        if ($phone === '') {
            $roleId = Role::query()->where('slug', Roles::STOREKEEPER)->value('id');
            $user = User::query()
                ->where('status', 'active')
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->where(fn ($q) => $q->where('role', Roles::STOREKEEPER)->when($roleId, fn ($q) => $q->orWhere('role_id', $roleId)))
                ->orderBy('id')->first();
            if (! $user) {
                return null;
            }
            $phone = (string) $user->phone;
            $name = $name !== '' ? $name : $user->name;
            $email = $user->email;
        }

        $digits = self::waDigits($phone);

        return [
            'name' => $name !== '' ? $name : 'LIA Storekeeper',
            'phone' => $phone,
            'email' => $email,
            'whatsapp' => $digits,
        ];
    }

    public function whatsappUrl(MaterialRequest $request, ?array $contact = null): ?string
    {
        $contact ??= $this->storekeeperContact();
        if (! $contact || ! $contact['whatsapp']) {
            return null;
        }
        $request->loadMissing('items.equipmentItem');
        $items = $request->items->filter(fn ($i) => ($i->qty_approved ?? 0) > 0)
            ->map(fn ($i) => $i->qty_approved.' x '.($i->equipmentItem?->name ?? 'item'))->implode(', ');
        $msg = "Hello {$contact['name']}, my equipment request {$request->reference_code} was approved"
            .($items ? " ({$items})" : '')
            .'. Pick-up: '.($request->needed_from?->format('Y-m-d') ?? '-')
            .', return by: '.($request->return_by?->format('Y-m-d') ?? '-')
            .". Name: {$request->full_name}.";

        return 'https://wa.me/'.$contact['whatsapp'].'?text='.rawurlencode($msg);
    }

    /** Normalise a phone to wa.me digits; Rwandan local numbers (07xxxxxxxx) get 250. */
    public static function waDigits(string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 10 && str_starts_with($d, '0')) {
            $d = '250'.substr($d, 1);
        } elseif (strlen($d) === 9 && str_starts_with($d, '7')) {
            $d = '250'.$d;
        }

        return strlen($d) >= 8 ? $d : null;
    }

    /** Decision block used by webhook, email and the public status page. */
    public function decision(MaterialRequest $request): array
    {
        $last = $request->statusHistories()->latest('id')->first();
        $contact = $this->borrowerStorekeeperContact($request);

        return [
            'status' => $request->status,
            'reason' => $request->status === 'rejected' ? $request->review_note : null,
            'note' => $request->review_note,
            'changes_requested' => $request->status === 'changes_requested' ? $request->review_note : null,
            'decided_at' => $last?->created_at?->toIso8601String(),
            'can_resubmit' => $request->status === 'changes_requested',
            'status_url' => $this->statusUrl($request),
            'storekeeper' => $contact,
        ];
    }

    /**
     * Public contact block. Null hides the number and the wa.me link.
     * The link only opens the borrower's own WhatsApp composer; this app does not send it.
     *
     * @return array{name: string, phone: string, whatsapp_url: string}|null
     */
    public function borrowerStorekeeperContact(MaterialRequest $request): ?array
    {
        if (! in_array($request->status, self::BORROWER_STOREKEEPER_CONTACT_STATUSES, true)) {
            return null;
        }

        $contact = $this->storekeeperContact();
        if (! $contact || empty($contact['whatsapp'])) {
            return null;
        }

        $url = $this->whatsappUrl($request, $contact);
        if ($url === null) {
            return null;
        }

        return [
            'name' => $contact['name'],
            'phone' => $contact['whatsapp'],
            'whatsapp_url' => $url,
        ];
    }
}
