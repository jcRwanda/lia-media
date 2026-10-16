<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ConsentRecord;
use App\Models\Person;
use App\Services\AssetPresenter;
use App\Services\AuditLogger;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConsentController extends Controller
{
    public function __construct(private ConsentService $consent, private AuditLogger $audit, private AssetPresenter $present) {}

    public function people(Request $request): JsonResponse
    {
        $people = Person::query()->with(['consents' => fn ($q) => $q->latest('signed_on')])->withCount('assets')
            ->when($request->query('q'), fn ($q, $t) => $q->where('full_name', 'like', "%{$t}%"))->orderBy('full_name')->limit(200)->get();

        return response()->json(['data' => $people->map(fn (Person $p) => [
            'id' => $p->id, 'full_name' => $p->full_name, 'organisation' => $p->organisation, 'is_minor' => $p->is_minor, 'assets_count' => $p->assets_count,
            'consents' => $p->consents->map(fn (ConsentRecord $c) => ['id' => $c->id, 'scope' => $c->scope, 'signed_on' => $c->signed_on?->toDateString(), 'expires_on' => $c->expires_on?->toDateString(), 'guardian_name' => $c->guardian_name, 'withdrawn_at' => $c->withdrawn_at?->toIso8601String(), 'active' => $c->isActive()]),
        ])]);
    }

    public function storePerson(Request $request): JsonResponse
    {
        $d = $request->validate(['full_name' => ['required', 'string', 'max:160'], 'organisation' => ['nullable', 'string', 'max:160'], 'is_minor' => ['boolean'], 'contact' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string']]);
        $p = Person::query()->create($d);
        $this->audit->log('person.created', $p);

        return response()->json(['data' => ['id' => $p->id, 'full_name' => $p->full_name]], 201);
    }

    public function storeConsent(Request $request, Person $person): JsonResponse
    {
        $d = $request->validate([
            'scope' => ['required', Rule::in(['photo', 'video', 'photo_video', 'event_general'])],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'signed_on' => ['required', 'date', 'before_or_equal:today'],
            'expires_on' => ['nullable', 'date', 'after:signed_on'],
            'guardian_name' => [$person->is_minor ? 'required' : 'nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string'],
        ]);
        $c = $person->consents()->create($d + ['recorded_by' => $request->user()->id]);
        foreach ($person->assets as $a) {
            if (! $a->pivot->consent_record_id) {
                $a->people()->updateExistingPivot($person->id, ['consent_record_id' => $c->id]);
            }
            $this->consent->recompute($a);
        }
        $this->audit->log('consent.recorded', $c, ['person' => $person->id]);

        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    public function withdraw(Request $request, ConsentRecord $consent): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_if($consent->withdrawn_at, 422, 'Already withdrawn.');

        return response()->json(['assets_restricted' => $this->consent->withdraw($consent, $request->user(), $d['reason'])]);
    }

    public function linkPerson(Request $request, Asset $asset): JsonResponse
    {
        $d = $request->validate(['person_id' => ['required', 'integer', Rule::exists('people', 'id')], 'consent_record_id' => ['nullable', 'integer', Rule::exists('consent_records', 'id')->where('person_id', $request->input('person_id'))]]);
        $person = Person::query()->findOrFail($d['person_id']);
        $consentId = $d['consent_record_id'] ?? $person->consents()->whereNull('withdrawn_at')->latest('signed_on')->value('id');
        $asset->people()->syncWithoutDetaching([$person->id => ['consent_record_id' => $consentId]]);
        $this->consent->recompute($asset);
        $this->audit->log('asset.person_linked', $asset, ['person' => $person->id, 'consent' => $consentId]);

        return response()->json(['asset' => $this->present->detail($asset->fresh())]);
    }

    public function unlinkPerson(Asset $asset, Person $person): JsonResponse
    {
        $asset->people()->detach($person->id);
        $this->consent->recompute($asset);

        return response()->json(['asset' => $this->present->detail($asset->fresh())]);
    }

    public function setStatus(Request $request, Asset $asset): JsonResponse
    {
        $d = $request->validate(['consent_status' => ['required', Rule::in(['unknown', 'not_needed'])]]);
        abort_if($asset->people()->exists(), 422, 'Consent is derived from the people linked to this item.');
        $asset->update($d);
        $this->audit->log('asset.consent_status', $asset, $d);

        return response()->json(['asset' => $this->present->detail($asset->fresh())]);
    }
}
