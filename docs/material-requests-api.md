# LIA Material Requests API v1

Server-to-server API for creating and tracking material/equipment requests. The existing hosted form
(`/request-materials`) and its permissive browser routes keep working; this document covers the strict platform API.

Base URL: `https://media.loveinarts.com` (use your deployment host)
API prefix: `/api/v1/material-requests`

> An always-current machine-readable schema is served at `GET /api/v1/material-requests/openapi.json` (no key needed).

## Authentication

Every endpoint except `openapi.json` accepts the client key in a header:

```http
X-LIA-Key: <plain key>
```

Create a client and print its key once:

```bash
php artisan material-requests:client "Learning Portal" \
  --origin=https://learning.loveinarts.com \
  --webhook=https://learning.loveinarts.com/hooks/lia-material-requests
```

- `--origin` is optional. When set, browser requests from a different `Origin` are rejected with `403`.
- `--webhook` is optional. See [Webhooks](#webhooks).
- Keys are hashed in `api_clients`; set a new webhook later with
  `php artisan material-requests:webhook "Learning Portal" https://...` or clear it with `--clear`.
- Camera/browser callers must be in `MATERIAL_REQUEST_ALLOWED_ORIGINS`; server-to-server calls do not need CORS.

Errors use HTTP status codes and this shape:

```json
{
  "message": "Validation failed",
  "errors": {
    "needed_from": ["The selected dates overlap a blocked period (Maintenance)."],
    "items.0.qty": ["Only 2 of Camera available on 2026-11-03 (requested 5)."]
  }
}
```

`401` means a missing/unknown/disabled key. `403` means the key is valid but the browser origin does not match.
`422` covers validation, booking rules, blocked dates and stock conflicts.

## Endpoints

### `GET /form-schema?type=client|trainee|alumni`

Returns the field list, bilingual labels, section grouping, requester types, cohorts, upload limits, terms and links
for building a native form. `type` defaults to `client`.

```bash
curl -s "https://media.loveinarts.com/api/v1/material-requests/form-schema?type=alumni" \
  -H "X-LIA-Key: $LIA_KEY"
```

Each field includes `key`, `type`, `required`, `section` and `label.en` / `label.kn`. Select fields include
`options`; Rwanda address fields use `options_source: "rwanda_divisions"` with `level` and `depends_on`.
Cohorts are also available directly in `options.cohorts`.

- Trainee forms are much shorter. In `config/material-requests.php` per-type `field_sets` define fields. Trainee fields (exact): `full_name`, `phone`, `email`, `cohort_id`, `student_alumni_id` (optional), `items`, `needed_from`, `end_date`/`return_by`, `shoot_location` (required text), `shoot_type` (required select: `video|photo|both` with bilingual labels), `project_name` (optional), `purpose_project_description`/`project_summary` (optional short), `terms_credit`, `consent_declaration`, `signature`. No residential address, emergency contact, project-location cascade or ID upload for trainees. `form-schema?type=trainee` returns only these; validation follows the same per-type config.

### `GET /catalog?type=client|trainee|alumni`

Returns requestable equipment with its total stock. For `type=trainee`, only items with `allowed_for_trainees=true` are returned, and each item's `accessories` list only contains trainee-allowed accessories. Each item always includes `allowed_for_trainees`.

- `photo_url`: main image (short-lived signed URL, refetch the catalog rather than caching it for hours).
- `image_urls`: **every** image of the item, in display order. Use it for a gallery/lightbox.
- `accessories`: related items staff linked to this one (e.g. camera → battery, charger, lens). Each accessory is itself an `id` in the same catalog, so a portal can show a reminder such as *"Don't forget: Battery, Charger, Lens"* with one-click "add" buttons when the parent item is selected. Accessories that are not in the returned catalog (e.g. not trainee-allowed) are omitted.

```json
{
  "items": [
    {
      "id": 12,
      "name": "Canon EOS R6",
      "category": "camera",
      "condition": "good",
      "available_qty": 4,
      "allowed_for_trainees": true,
      "photo_path": "material-requests/equipment/...",
      "photo_url": "https://...signed...",
      "image_urls": ["https://...signed-1...", "https://...signed-2..."],
      "accessories": [
        { "id": 14, "name": "LP-E6NH battery", "allowed_for_trainees": true },
        { "id": 15, "name": "RF 24-105mm lens", "allowed_for_trainees": true }
      ]
    }
  ]
}
```

`GET /form-schema?type=...` also includes a `catalog` array with exactly the same item shape (filtered the same way by `type`), so a portal can render the whole form, accessory reminders and image galleries from one call.

Suggested reminder logic (JavaScript):

```js
const byId = new Map(catalog.map((i) => [i.id, i]));
function reminders(selectedIds) {
  return selectedIds.flatMap((id) => {
    const missing = (byId.get(id)?.accessories || []).filter((a) => byId.has(a.id) && !selectedIds.includes(a.id));
    return missing.length ? [{ for: byId.get(id).name, missing }] : [];
  });
}
```

### `GET /availability?item_ids[]=12&item_ids[]=14&from=2026-11-01&to=2026-11-30&type=trainee`

Returns reservations, blocks and per-day availability for the requested items. `item_ids` is optional; if omitted, availability is returned for all active items. `from` defaults to today and `to`
defaults to 90 days later; the range may not exceed 366 days.

```bash
curl -sG "https://media.loveinarts.com/api/v1/material-requests/availability" \
  -H "X-LIA-Key: $LIA_KEY" \
  --data-urlencode "item_ids[]=12" \
  --data-urlencode "item_ids[]=14" \
  --data-urlencode "from=2026-11-01" \
  --data-urlencode "to=2026-11-30"
```

Response (abridged):

```json
{
  "from": "2026-11-01",
  "to": "2026-11-30",
  "rules": {
    "closed_weekdays": [0],
    "max_booking_days": 30,
    "lead_time_days": 0,
    "earliest_start_date": "2026-11-01"
  },
  "global_blocked_dates": [
    { "date": "2026-11-08", "reasons": ["Closed weekday"] }
  ],
  "items": [
    {
      "id": 12,
      "name": "Canon EOS R6",
      "total_qty": 4,
      "booked_dates": ["2026-11-03"],
      "blocked_dates": ["2026-11-08"],
      "unavailable_dates": ["2026-11-03", "2026-11-08"],
      "daily": {
        "2026-11-03": {
          "date": "2026-11-03",
          "reserved_qty": 2,
          "available_qty": 2,
          "blocked": false,
          "reasons": []
        }
      }
    }
  ]
}
```

Only **approved** and **issued** requests reserve stock. A submitted request does not block the dates until it is
approved. `booked_dates` means at least one unit is reserved; use `daily.*.available_qty` for partial availability. For `type=trainee`, availability is limited to `allowed_for_trainees` items. `/submit` rejects non-allowed items for trainees with a per-item validation error (e.g. `items.0.equipment_item_id`).

### `POST /submit`

Send JSON, or `multipart/form-data` when uploading `id_document` and/or `signature` as files. The hosted form's
`data:image/...` values are also accepted for signature/ID, but native platforms should upload files.

Required profile fields are defined by `form-schema`. A minimal client request:

```bash
curl -s "https://media.loveinarts.com/api/v1/material-requests/submit" \
  -H "X-LIA-Key: $LIA_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "requester_type": "client",
    "full_name": "Aline Uwase",
    "phone": "0780000000",
    "email": "aline@example.com",
    "project_name": "Short film",
    "emergency_contact_name": "Eric Uwase",
    "emergency_contact_phone": "0781111111",
    "relationship": "Sibling",
    "project_summary": "A 10-minute documentary.",
    "needed_from": "2026-11-03",
    "return_by": "2026-11-07",
    "terms_accepted": true,
    "consent_declaration": true,
    "source_detail": "Cohort 2026 intake",
    "items": [
      { "equipment_item_id": 12, "qty": 2 }
    ],
    "signature": "data:image/png;base64,...",
    "id_document": "data:image/png;base64,..."
  }'
```

Multipart example:

```bash
curl -s "https://media.loveinarts.com/api/v1/material-requests/submit" \
  -H "X-LIA-Key: $LIA_KEY" \
  -F "requester_type=alumni" \
  -F "full_name=Aline Uwase" \
  -F "phone=0780000000" \
  -F "email=aline@example.com" \
  -F "project_name=Short film" \
  -F "emergency_contact_name=Eric Uwase" \
  -F "emergency_contact_phone=0781111111" \
  -F "relationship=Sibling" \
  -F "project_summary=A 10-minute documentary." \
  -F "needed_from=2026-11-03" \
  -F "return_by=2026-11-07" \
  -F "terms_accepted=1" \
  -F "consent_declaration=1" \
  -F "cohort_id=4" \
  -F "items[0][equipment_item_id]=12" \
  -F "items[0][qty]=2" \
  -F "signature=@signature.png" \
  -F "id_document=@id.png"
```

Response `201`:

```json
{
  "message": "Request submitted",
  "reference_code": "MR-AB12CD34",
  "status": "submitted"
}
```

Submission rules applied by the server:

- `return_by` must be on/after `needed_from`.
- The date range must respect `lead_time_days`, `max_booking_days` and `closed_weekdays`.
- Global or item date blocks are rejected.
- Stock is checked for every day in the range against approved/issued reservations.
- `client` and `alumni` need an ID document; `trainee`/`alumni` need a `cohort_id`.
- The request is recorded with `source` = the API client name and `source_detail` from the payload.
  Requests from the hosted/plain form use `Direct/Hosted form`.

### `GET /{reference}`

Returns the status of a request created by the authenticated API client. References created by a different client
return `404`.

```bash
curl -s "https://media.loveinarts.com/api/v1/material-requests/MR-AB12CD34" \
  -H "X-LIA-Key: $LIA_KEY"
```

```json
{
  "request": {
    "reference_code": "MR-AB12CD34",
    "status": "approved",
    "requester_type": "client",
    "source": "Learning Portal",
    "source_detail": "Cohort 2026 intake",
    "review_note": null,
    "needed_from": "2026-11-03",
    "return_by": "2026-11-07",
    "created_at": "2026-10-26T08:15:00+00:00",
    "updated_at": "2026-10-26T09:00:00+00:00",
    "items": [
      {
        "name": "Canon EOS R6",
        "qty_requested": 2,
        "qty_approved": 2,
        "qty_issued": null,
        "qty_returned": null,
        "status": "approved"
      }
    ]
  }
}
```

Reference codes are matched at `/api/v1/material-requests/{reference}` and must look like `MR-...`.

### `GET /openapi.json`

Public OpenAPI 3.1 document for code generation and interactive clients.

## Booking rules and date blocks (staff)

These staff endpoints use the normal staff session, not `X-LIA-Key`:

- `GET /api/equipment-calendar?month=YYYY-MM` — items, approved/issued bookings, blocks and current rules.
- `PATCH /api/equipment-calendar/rules` — `closed_weekdays` (0=Sunday … 6=Saturday), `max_booking_days`,
  `lead_time_days`.
- `POST /api/equipment-blocks` — `equipment_item_id` (null = all equipment), `starts_on`, `ends_on`, `reason`.
- `DELETE /api/equipment-blocks/{id}`.

The staff UI exposes the same features at **Equipment calendar** and the tabs/counts on **Material Requests**.

## Webhooks

Set `api_clients.webhook_url` per client. Every status change (submitted, approved, rejected, issued, returned,
closed) is queued and POSTed to that URL. A missing URL means no webhook. Failed deliveries are retried up to three
times with backoff.

Payload:

```json
{
  "event": "material_request.status_changed",
  "reference_code": "MR-AB12CD34",
  "status": "approved",
  "previous_status": "submitted",
  "source": "Learning Portal",
  "source_detail": "Cohort 2026 intake",
  "requester_type": "client",
  "needed_from": "2026-11-03",
  "return_by": "2026-11-07",
  "updated_at": "2026-10-26T09:00:00+00:00",
  "items": [
    {
      "equipment_item_id": 12,
      "name": "Canon EOS R6",
      "qty_requested": 2,
      "qty_approved": 2,
      "qty_issued": null,
      "qty_returned": null,
      "status": "approved"
    }
  ]
}
```

Headers:

```http
X-LIA-Signature: sha256=<hex HMAC-SHA256 of the raw request body using the client's plain API key>
X-LIA-Event: material_request.status_changed
X-LIA-Request: MR-AB12CD34
Content-Type: application/json
```

Verify against the **raw body** before parsing JSON:

```php
$raw = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $raw, $clientApiKey);

if (! hash_equals($expected, $_SERVER['HTTP_X_LIA_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}

$payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
```

```js
import crypto from 'node:crypto';

const raw = req.rawBody; // keep the exact bytes, not re-serialized JSON
const expected = 'sha256=' + crypto.createHmac('sha256', clientApiKey).update(raw).digest('hex');

if (!crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.headers['x-lia-signature'] || ''))) {
  return res.status(401).end();
}

const event = JSON.parse(raw);
```

The signing secret is the plain `X-LIA-Key` (the server stores it encrypted as `prefill_secret`). Rotating the key
means updating both sides; use the `material-requests:webhook` command to change only the URL.

## JavaScript example

```js
const LIA_KEY = process.env.LIA_KEY;
const base = 'https://media.loveinarts.com/api/v1/material-requests';

async function lia(path, init = {}) {
  const response = await fetch(base + path, {
    ...init,
    headers: {
      'X-LIA-Key': LIA_KEY,
      Accept: 'application/json',
      ...(init.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
      ...init.headers,
    },
  });

  const data = await response.json();
  if (!response.ok) throw Object.assign(new Error(data.message), { status: response.status, errors: data.errors });
  return data;
}

const availability = await lia('/availability?item_ids[]=12&from=2026-11-01&to=2026-11-30');
const request = await lia('/submit', {
  method: 'POST',
  body: JSON.stringify({
    requester_type: 'client',
    full_name: 'Aline Uwase',
    phone: '0780000000',
    email: 'aline@example.com',
    project_name: 'Short film',
    emergency_contact_name: 'Eric Uwase',
    emergency_contact_phone: '0781111111',
    relationship: 'Sibling',
    project_summary: 'A 10-minute documentary.',
    needed_from: '2026-11-03',
    return_by: '2026-11-07',
    terms_accepted: true,
    consent_declaration: true,
    items: [{ equipment_item_id: 12, qty: 2 }],
    signature: signatureDataUrl,
    id_document: idDataUrl,
  }),
});

const status = await lia(`/${request.reference_code}`);
```



## Final work (trainee requests)

> **Link-only (2026-10-26):** trainees submit final work as a video/photo link only: `POST /{reference}/final-work` with `{"link": "https://...", "note": "..."}`. `link` is required; `file` and `key` are rejected (422), and `POST /{reference}/final-work/upload-url` returns 410 Gone.

Trainee requests require final work submission after equipment is issued/returned depending on workflow. Endpoints (staff session):

- `GET /api/material-requests/{id}` includes `final_work_status` (`not_required`, `pending`, `submitted`, `needs_changes`, `accepted`), `final_work_submitted_at`, `final_work_reviewed_at`, `final_work_review_note`, `final_work_reviewed_by`, and `final_works` array with `id`, `file_name`, `uploaded_at`, `download_url` (staff download via `GET /api/material-requests/{id}/final-work/{finalWork}/file`).
- `POST /api/material-requests/{materialRequest}/final-work/accept` (requires `material-requests.manage`) — marks as `accepted`. Optional JSON `{ "note": "..." }`.
- `POST /api/material-requests/{materialRequest}/final-work/request-changes` (requires `material-requests.manage`) — sets `needs_changes`. Body `{ "note": "..." }` required (max 2000).

Public/trainee submission (signed/token or session as implemented):

- `POST /api/v1/material-requests/{reference}/final-work/upload-url` — get presigned/upload URL for final work (R2/local).
- `POST /api/v1/material-requests/{reference}/final-work` — submit uploaded final work; moves status to `submitted` if not already `accepted`.
- When `issued` or `returned` and `final_work_status === 'not_required'`, the system auto-sets `final_work_status` to `pending` for trainee requests.

Webhook events: `final_work.submitted`, `final_work.accepted`, `final_work.needs_changes` are dispatched with `final_work_status` in payload.

## Hosted form compatibility

The hosted page and legacy browser routes remain available:

- `/request-materials?type=client|trainee|alumni` (iframe/embed with optional signed `prefill` token)
- `GET /api/v1/material-requests/config`
- `GET /api/v1/material-requests/catalog`
- `POST /api/v1/material-requests/submit`
- `POST /api/v1/material-requests/status`
- prefill/OTP endpoints

Platform clients should prefer the strict endpoints above. Direct/hosted submissions are labelled
`Direct/Hosted form` in staff source tabs so they are easy to separate from API clients.
