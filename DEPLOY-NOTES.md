# Deploy notes — Material Requests platform API v2, sources, webhooks & equipment calendar

Branch: `feature/requests-api-v2`.

## What changed
- **Strict server-to-server API** under `/api/v1/material-requests`: `GET /form-schema`,
  `GET /availability`, `GET /{reference}` and a public `GET /openapi.json`, all documented in
  `docs/material-requests-api.md`. Auth is `X-LIA-Key` against active `api_clients`; unknown/missing keys get `401`,
  browser origins that do not match an allowed origin get `403`. Existing hosted-form routes are unchanged.
- **Source tracking.** Every request now stores `source` (API client name, or `Direct/Hosted form` for the hosted/plain
  form) and optional `source_detail`. Staff can filter by source tab with per-source counts on the requests list; the
  detail page shows the source. Existing rows with no source are counted under `Direct/Hosted form`.
- **Booking availability and rules.** `GET /availability` reports reserved, blocked and unavailable dates per item.
  Submission now rejects lead-time/max-length/closed-weekday violations, global or item date blocks, and insufficient
  stock for **approved/issued** reservations. Only approved/issued requests reserve stock. Defaults preserve current
  behaviour: lead time `0`, max `30` days, no closed weekdays.
- **Webhooks.** Optional `api_clients.webhook_url`; status changes are queued with retries and signed with
  `X-LIA-Signature: sha256=<HMAC of raw body>` using the client key. See the API doc for verification examples.
- **Equipment calendar (staff UI + API).** Month view of approved/issued bookings per item, global/item date blocks,
  and editable booking rules at `/equipment-calendar` (nav: **Equipment calendar**).
- **New staff API routes:** `GET /api/equipment-calendar`, `PATCH /api/equipment-calendar/rules`,
  `POST /api/equipment-blocks`, `DELETE /api/equipment-blocks/{block}`.
- **Artisan helpers:** `material-requests:client --webhook=` and
  `material-requests:webhook {client} {url?} [--clear]`.

## Database
```bash
php artisan migrate --force
```
Two migrations:
- `2026_10_26_144827_add_api_client_source_and_webhook_to_material_requests.php` — adds `api_clients.webhook_url`,
  `material_requests.api_client_id`, `source`, `source_detail`, plus source and booking-window indexes.
- `2026_10_26_144828_create_equipment_blocks_table.php` — creates `equipment_blocks` with an optional item foreign
  key (null = all equipment), inclusive dates, reason and creator.
No backfill is required. Existing requests remain valid and show as `Direct/Hosted form` until new requests arrive.

## Configuration
No new required environment variables. Optional:
- `MATERIAL_REQUEST_ALLOWED_ORIGINS` continues to control browser CORS/frame origins.
- Set a webhook per client with the commands below (or update `api_clients.webhook_url` directly).

```bash
# Create a client, optionally with a webhook
php artisan material-requests:client "Learning Portal" \
  --origin=https://learning.loveinarts.com \
  --webhook=https://learning.loveinarts.com/hooks/lia-material-requests

# Set or clear a webhook later
php artisan material-requests:webhook "Learning Portal" https://learning.loveinarts.com/hooks/lia-material-requests
php artisan material-requests:webhook "Learning Portal" --clear
```

The webhook signing secret is the client's plain `X-LIA-Key` (the command prints it once). Ensure the receiving
endpoint verifies the signature against the raw request body and tolerates retries.

## Post-deploy checks
```bash
php artisan test          # 93 tests, 2 skipped
npm test                  # vitest 27 passed
npm run build
```

Smoke test:
- `GET /api/v1/material-requests/form-schema?type=alumni` with a valid key returns fields and cohorts; without a key
  returns `401`.
- `GET /api/v1/material-requests/openapi.json` is public.
- Create an approved request, then submit an overlapping request from the same client: `422` with an
  `items.0.qty` error. Unapproved requests do not reserve stock.
- Add a global block from **Equipment calendar**, then submit a request covering those dates: `422`.
- Submit one request from the hosted form and one with an API key: staff list tabs show `Direct/Hosted form` and the
  client name with correct counts; filtering works.
- Configure a webhook and approve a request: the receiver gets `X-LIA-Signature` and `X-LIA-Event`; a signature check
  against the raw body passes.
- Change booking rules on **Equipment calendar** (for example close Sunday, lead time 2 days) and confirm new
  submissions are rejected until the dates comply.

## Rollback
```bash
php artisan migrate:rollback --step=2
```
Drops `equipment_blocks` and the source/webhook columns. Webhook URLs and source labels are lost; booking history in
`material_requests` is not. Hosted-form links and existing request data are unaffected.

---

# Deploy notes — upload defaults, day galleries & visitor downloads

Branch: `feature/daily-galleries` (based on `0a745f3`).

## What changed
- **Upload defaults (Settings → Upload defaults).** New uploads can be pre-filled from settings:
  `default_captured_at` (stamp "now" when the uploader didn't send a date), `default_review_state`,
  `default_tags` (comma-separated), `default_album_name` / `default_album_uuid`, `daily_galleries_enabled`,
  and `gallery_timezone` (default `Africa/Kigali`).
- **Default album.** A curated album named by `default_album_name` (default `Unsorted`) is guaranteed to exist and is
  the fallback album for uploads that don't choose one. Marked with `is_default`; it can't be deleted until another
  default is chosen in Settings.
- **Day galleries.** Every uploaded asset is also filed into an auto-created album for its capture day
  (`albums.kind = 'daily'`, `albums.day = YYYY-MM-DD`), titled like `Monday, 19 Oct 2026`. Day galleries are shown on
  the Albums page under **Day galleries**, newest day first, with today's gallery highlighted. Day galleries can't be
  deleted (they're managed automatically) and work with existing share links like any album.

- **Visitor gallery is mobile-first.** Responsive 2/3/4-column grid built from touch-friendly tiles, lazy-loaded
  thumbnails (`loading="lazy"`, fixed aspect ratio), and a full-screen viewer with swipe navigation (plus arrow keys
  and larger touch targets on desktop). Body scroll is locked while the viewer is open.
- **Select & download.** Visitors with the download permission can enter select mode, tap items, and use
  **All / Clear / Cancel / Download** from a bottom action bar that respects safe-area insets.
  - One item: direct download (short-lived presigned URL, original or preview as the link allows).
  - Several items on desktop: one **server-streamed zip** (`POST /api/s/{token}/download`, max 500 items) assembled
    from storage chunks with flat memory use, so the archive is never buffered on disk or in the browser.
  - Several items on phones/tablets (coarse pointer, no hover): **no zip**. The files are fetched over the app origin
    (`GET /api/s/{token}/items/{asset}/file`) and handed to the **Web Share API** so they can be saved to Photos/Files;
    when the share sheet is unavailable, the files are downloaded one by one. This avoids the mobile pain of unzipping.
  - Each downloaded item is audited as `share.downloaded` (`zip`, `stream`, or direct flag) against the share link.
- **Download permission defaults on.** New share links allow downloads unless staff uncheck *Allow downloads* in the
  share dialog. The flag was already stored and shown on the Shares page; this branch makes it on by default.

Nothing in this change removes or renames existing columns; curated albums keep working exactly as before and the
review/consent workflow and existing share links keep working.

## Database
```
php artisan migrate --force
```
- Adds three columns to `albums` (`kind`, `day`, `is_default`) with `kind` defaulting to `curated` for existing rows,
  plus a unique index on `day` and an index on `(kind, day)`. No data backfill is required for correctness.
- Changes the **default** of `share_links.allow_download` to `true`. Existing links keep their stored value; only links
  created from now on default to allowing downloads. No data backfill.

## One-time backfill (optional but recommended)
Group media that was uploaded before this change into day galleries using its `captured_at`:
```
php artisan gallery:backfill                 # all assets with a captured_at
php artisan gallery:backfill --from=2026-10-01 --to=2026-10-19
```
Idempotent and safe to re-run (e.g. after fixing capture dates). It also guarantees the default album exists.

## Settings to review after deploy
- Settings → **Upload defaults**: choose the default album, default review state, default tags, and whether to enable
  day galleries. The defaults take effect on the next upload.
- `gallery_timezone` controls which calendar day an upload belongs to; change it only if the archive isn't
  Africa/Kigali.

## Post-deploy checks
```
php artisan test          # 56 tests (2 skipped)
npx vitest run && npx tsc --noEmit && npx vite build
```
Smoke test in the app:
- Upload one image → it appears in the default album **and** in today's day gallery.
- Create a share link without touching the download checkbox → it allows downloads.
- Open a visitor link on a desktop browser: select several items → one zip arrives.
- Open the same link on a phone: select several items → the share sheet opens (or files save individually); the viewer
  swipes between items and thumbnails load lazily.
- Turn downloads off on a link → the visitor sees no Select/download controls and both download endpoints return 403.

**Operational notes:** the single-file and zip paths stream through the app, so no R2 bucket CORS rule is needed for
them. The Web Share API requires HTTPS (it is unavailable on plain HTTP except localhost). Grant cookies last 2 hours;
the zip/file endpoints return 401 once the grant expires, and the visitor is asked to reopen the link.

## Rollback
```
php artisan migrate:rollback --step=2   # or --step=1 if only the latest migration was run
```
The latest step restores `share_links.allow_download` to default `false`. The gallery step drops the three album
columns and their indexes; day-gallery rows then become ordinary (curated) albums. No assets or curated albums are
affected.

---

# Deploy notes — welcome/temp-password frontend & staff downloads

Branch: `feature/album-download` (based on `d3fe429`, which added the backend for welcome emails and the
must-change-password lock).

## What changed

### Welcome email & must-change-password (staff-facing UI)
- **No password field when creating a user.** *Users & roles* now only asks for name, email and role. The server
  generates a temporary password, stores the user with `must_change_password = true` and emails the brand welcome mail
  (`POST /api/users`). The page reports whether the welcome email actually went out; if mail is not configured yet the
  user is still created and a warning suggests "Resend welcome email".
- **Badges.** Every account shows *Not logged in yet* (no `last_login_at`) or *Must change password* while it is
  locked; disabled accounts keep their existing red badge.
- **Resend welcome email.** Button on every row → `POST /api/users/{id}/resend-welcome`, which rotates the temporary
  password, re-locks the account and re-sends the mail. It asks for confirmation first when it would lock an account
  that has already logged in.
- **`/set-password` screen.** Replacing the temporary password is now a first-class route (`SetPasswordPage`): it
  posts `POST /auth/change-password` (min 10 characters, confirmed), updates the cached `me` and continues to the page
  the user originally asked for (`?next=`).
- **Lock enforcement in the UI.** `LoginPage` sends flagged users straight to `/set-password`; `AppLayout` parks any
  flagged session there too; and any API response that carries `code: must_change_password` (the 403 from the
  `must_change` middleware, or a 423) flips the cached user so a session locked mid-navigation lands on the same
  screen instead of failing silently. After the password is changed the SPA and every API route unlock immediately —
  no re-login.
- `GET /auth/me` now surfaces `must_change_password` / `first_login_completed` in the SPA's `User` type; existing
  accounts are unaffected because the new column defaults to `false`.

### Staff downloads
- **Library: Select mode.** A *Select/Done* button (shown to anyone who can edit or download) plus a fixed bottom bar
  with the selected count, *Select all* (current page), *Clear*, *Cancel*, the bulk album/tag controls and
  **Download selected**. Downloads honour the phone-vs-desktop split: desktop streams one zip, phones/tablets fetch
  the files from our own origin and hand them to the Web Share API (falling back to one-by-one saves).
- **Album page: "Download album".** One button downloads everything in the album — a single streamed zip on desktop,
  shared files on phones — and the album page has the same select mode/bar as the library. The zip is named
  `{album-slug}-{Ymd-His}.zip`.
- **Visitor gallery: "Download all".** Desktop-only button next to *Select* that posts every item uuid to
  `POST /api/s/{token}/download` (hidden form, so the browser streams it straight to disk). Hidden when the share
  would exceed the 500-item zip cap, in which case select mode stays available.
- **Server side.** New `DownloadService` (selection filtering + audits) and `StreamedMedia` (chunked file/zip
  streaming) shared by the staff endpoints and the existing visitor share endpoints. Three new routes behind
  `can:assets.download_original`: `POST /api/assets/download` (1–500 uuids), `GET /api/assets/{asset}/file` and
  `POST /api/albums/{album}/download`. Downloads are audited as `asset.downloaded` (`variant: original`,
  `zip: true|stream: true`, album uuid in context where relevant). Nothing is buffered on disk or in memory.
- Selecting more than 500 items is rejected by the form (422 from `max:500`); an album zip has no item cap.

## Database
```
php artisan migrate --force
```
- Adds `users.must_change_password` (boolean, default `false`, after `password`) from `d3fe429`. Existing users keep
  `false`, so nobody is locked out by this deploy and no backfill is needed.

## Settings to review after deploy
- **Mail.** The welcome email needs working `MAIL_*` settings (and `MAIL_FROM_ADDRESS`). With `MAIL_MAILER=log` the
  mail lands in `storage/logs/laravel.log`. If sending fails the account is still created and marked to change its
  password — fix mail, then press *Resend welcome email*.
- **Queue.** The welcome mail is queued when `QUEUE_CONNECTION` is a real queue (run `queue:work`); with `sync` it
  sends inline.
- No bucket/CORS changes: staff downloads stream through the app like visitor downloads do.

## Post-deploy checks
```
php artisan test            # 72 tests, 70 passed, 2 skipped
npx vitest run && npx tsc --noEmit && npx vite build
```
Smoke test in the app:
- Users & roles → create a user → no password asked; the row shows *Not logged in yet*; the welcome mail (log driver:
  `storage/logs/laravel.log`) contains a temporary password.
- Sign in with it → `/set-password` → set a 10+ character password → you land in the app and every API call works
  without re-login. Try a weak or mismatched password first: the form refuses it.
- *Resend welcome email* on a logged-in account asks for confirmation, then the old password stops working.
- Library → *Select* → tick a few items → *Download selected* → one `library-*.zip` arrives (desktop), the share sheet
  opens (phone). Bulk "add to album"/"add tags" still work from the same bar.
- Album → *Download album* → a `{album}-*.zip` with every stored item; an album whose items are all still processing
  says "This album has no downloadable media yet."
- Visitor link on desktop → *Download all* → a single zip; the button is absent on a phone, where *Select* still
  shares files individually.
- A user mid-lock: any API call from another tab returns `403 {code: "must_change_password"}` and the open tab jumps
  to `/set-password`.

## Rollback
```
php artisan migrate:rollback --step=1   # drops users.must_change_password
```
Reverting the code leaves the SPA routes and the three download routes in place only until the previous commit is
deployed; no data is stored by this feature beyond the flag column.

---

# Deploy notes — Material / Equipment Requests module

Branch: `feature/material-requests`

## What changed
- **Public API (versioned)** at `/api/v1/material-requests`: `GET /catalog`, `GET /config`, `POST /submit`, `POST /status` (reference code + email/phone). CORS allowlist via `MATERIAL_REQUEST_ALLOWED_ORIGINS` (env), per-client API keys via header `X-LIA-Key` stored as hashed in `api_clients` (name, hashed key, allowed origin, active) with rate limiting and honeypot/basic anti-spam.
- **Embeddable hosted page** `/request-materials?type=alumni|trainee|client` (iframe-friendly with `CSP frame-ancestors` allowlisting the configured origins). Small JS embed snippet documented below.
- **Requester types**: client, trainee (student), alumni. Common required fields + addresses + project location + emergency contact + items + purpose + dates + terms acceptance (bilingual Kinyarwanda/English). Extra fields for trainee/alumni only (program/cohort, graduation/completion year, student/alumni ID, project title, project type, expected outcome, portfolio/social link). Field config is data-driven via `config/material-requests.php`.
- **Equipment inventory**: `equipment_items` managed by admin and new role **storekeeper** (added to roles/abilities). Staff API/UI support for inventory.
- **Workflow states**: submitted → approved/rejected (admin, with note, can adjust quantities) → issued (storekeeper: per item, **required photo proof at handover**, condition, date, issued-by) → returned per item (storekeeper: **required photo proof at return**, condition, date, received-by; partial returns supported; overdue flag after return-by) → closed. Proof images stored in existing storage (public disk/R2 compatible). Full audit log via `MaterialRequestAuditService` (and integrated flow).
- **Emails**: confirmation with reference code, approval/rejection, issue receipt, return receipt; admins notified of new requests; overdue reminder via scheduled command `material-requests:overdue-reminder` (daily).
- **Staff SPA pages (added)**: Requests list (filters by status/type, overdue), request detail with approve/reject and issue/return actions incl. photo capture from phone camera (`input capture`), Inventory page. Mobile-friendly.
- **Feature tests** added for API auth/CORS behavior, type-specific validation, workflow + role permissions, proof photo required.

## Database
```bash
php artisan migrate --force
```
Adds: `api_clients`, `equipment_items`, `material_requests`, `material_request_items`, `material_request_audits`.

## Environment variables
Add to `.env`:
```env
MATERIAL_REQUEST_ALLOWED_ORIGINS=https://learning.loveinarts.com,https://loveinarts.com
MATERIAL_REQUEST_HONEYPOT=website_url
```
(See `.env.example`.)

## Create API keys via artisan command
```bash
php artisan material-requests:client "Learning Portal" --origin=https://learning.loveinarts.com
php artisan material-requests:client "Alumni Portal" --origin=https://loveinarts.com
```
The command outputs the plain `X-LIA-Key` (store securely; not shown again). Keys are stored hashed in `api_clients`.

## Embed instructions for both portals
**Option 1: Iframe (hosted page)**
```html
<iframe src="https://your-domain.com/request-materials?type=alumni" width="100%" height="1200" frameborder="0" allow="camera"></iframe>
```
Use `type=trainee` or `type=client` as appropriate. The page sets `Content-Security-Policy: frame-ancestors` based on `MATERIAL_REQUEST_ALLOWED_ORIGINS` and is iframe-friendly.

**Option 2: Direct API calls from the portals**
Base URL: `https://your-domain.com/api/v1/material-requests`
Headers: `Content-Type: application/json`, `X-LIA-Key: <plain-key>`, `Origin: <allowed-origin>`
Endpoints:
- `GET /catalog` - list requestable items (available qty)
- `GET /config` - field config and bilingual terms
- `POST /submit` - submit request (include items array with equipment_item_id and qty)
- `POST /status` - check status by `reference_code` + (`email` or `phone`)

Example submit (JSON):
```json
{
  "requester_type": "alumni",
  "full_name": "...",
  "phone": "...",
  "email": "...",
  "emergency_contact_name": "...",
  "emergency_contact_phone": "...",
  "purpose_project_description": "...",
  "needed_from": "2026-11-01",
  "return_by": "2026-11-10",
  "terms_accepted": true,
  "items": [{"equipment_item_id": 1, "qty": 2}],
  "program_cohort": "...",
  "project_title": "..."
}
```

**JS embed snippet (for portals):**
```html
<div id="lia-mr-embed"></div>
<script>
(function(){
  const iframe = document.createElement('iframe');
  iframe.src = 'https://your-domain.com/request-materials?type=' + encodeURIComponent('alumni');
  iframe.style.width = '100%';
  iframe.style.minHeight = '1100px';
  iframe.style.border = '0';
  iframe.allow = 'camera';
  document.getElementById('lia-mr-embed').appendChild(iframe);
})();
</script>
```

## Roles/permissions
- Add `storekeeper` role to users as needed. Storekeepers can manage equipment and perform issue/return actions. Admins/management/communications can approve/reject and manage requests.

## Post-deploy checks
```bash
php artisan migrate --force
php artisan material-requests:client "Test" --origin=https://example.com  # test
php artisan test
npm run build
```

---

# Deploy notes — aligned request form, portal prefill & private ID/signature

Branch: `feature/material-requests` (follow-up).

## What changed
- **The hosted form now follows the exact question list** and is driven by `config/material-requests.php`
  (field groups + bilingual Kinyarwanda/English labels): Full Names, Phone Number, Email, Project Name,
  Residential Address (Province → District → Sector → Cell → Village), Emergency Contact Name/Phone,
  Relationship, Project Summary, Equipment Requested (catalog multi-select with quantity), Start Date
  (Pick-up) / End Date (Return), Project Location (Province → District → Sector → Cell with a
  **Same as residential** toggle), Select Cohort (trainee/alumni only, from `cohorts`), Terms & Credit
  agreement, Consent & Declaration, Upload Your ID, and Signature & Date.
- **Rwanda administrative divisions** are bundled at `public/data/rwanda-divisions.json` (compact JSON from
  `ngabovictor/Rwanda`) for the two cascading selects. Village has a free-text fallback ("Other / Ikindi").
- **ID upload** is required for `client` and `alumni` (optional for `trainee`), accepts images or PDF, and is
  written server-side to private object storage (R2 in production) under `material-requests/id-documents/`.
  ID files are never public or linked in the API; staff see a short-lived presigned URL, and only users with
  `material-requests.view` can fetch it (`GET /api/material-requests/{id}/id-document`).
- **Signature pad**: the form captures a drawn PNG on canvas, posts it with the request, and the server stores it
  privately under `material-requests/signatures/`. `signature_date` is always set to the server's current date.
  Staff can view it through `GET /api/material-requests/{id}/signature` (also gated by `material-requests.view`).
- **Project location helper**: checking *Same as residential* copies the residential Province/District/Sector/Cell;
  the API also honours `project_same_as_residential` when the fields are omitted/disabled.
- **Signed portal prefill**:
  - Hosts can put a signed token in the iframe URL (`/request-materials?type=alumni&prefill=<token>`) or pass it
    through the JS embed `prefill` option.
  - Token format is `v1.<base64url(payload)>.<base64url(HMAC-SHA256(payload, client secret))>`, where the client
    secret is the plain `X-LIA-Key` shown by `php artisan material-requests:client` (also stored encrypted as
    `api_clients.prefill_secret` so the server can verify it). Payload supports `full_name` (or `name`), `email`,
    `phone`, `type` (or `requester_type`), `cohort` (code/name/id), and `student_alumni_id` (or `student_id`).
    Tokens expire after `MATERIAL_REQUEST_PREFILL_TTL` seconds (default 900).
  - Prefilled identity fields are **locked** in the form. The endpoint `GET /api/v1/material-requests/prefill?token=...`
    verifies the signature before returning anything.
- **Returning requester prefill** (only after proof): a button beside the email/phone fields sends a one-time code
  to the email on the requester's last matching request (`POST /api/v1/material-requests/prefill/start`).
  `POST /api/v1/material-requests/prefill/verify` then returns the last residential address, emergency contact and
  an **ID reuse token**. The token is bound to the submitted email/phone and lets the new request reuse the old
  private ID without exposing the file. Nothing is returned publicly without a valid OTP or signed token.
- **Data model**: `material_requests` gained `project_name`, `village_res`, `relationship`, `consent_declaration`,
  `cohort_id`, `id_document_path`, `id_document_mime`, `id_document_name`, `signature_path`, and `signature_date`.
  `api_clients` gained encrypted `prefill_secret`.
- **Staff UI**: request detail now shows project/cohort/address/relationship, consent, the ID document link,
  signature image and signature date.
- **Tests** cover the exact config/labels, signed prefill tokens, the OTP + ID reuse flow, required ID rules,
  cohort validation, date ordering, same-as-residential copying, auto signature date, and staff-only document links.

## Database
```bash
php artisan migrate --force
```
Adds the columns above. Existing requests keep working; new columns are nullable except `consent_declaration`
(default `false`). Existing API clients created before this deploy have no `prefill_secret`; re-create them (or backfill
the column with the original key if you still have it) to enable signed prefill.

## Environment variables
Optional tuning (defaults shown):
```env
MATERIAL_REQUEST_PREFILL_TTL=900
MATERIAL_REQUEST_OTP_TTL=600
MATERIAL_REQUEST_ID_MAX_BYTES=10485760
```
Keep `MEDIA_DISK=r2` and the existing `R2_*` credentials in production. ID documents and signatures use the same private
R2 bucket/prefix as the archive, never the public disk. Mail must be configured for returning-requester OTP codes
(the same `MAIL_*` settings used by the other material-request emails).

## Signing a prefill token
The portal signs the payload with its API key (the same value used for `X-LIA-Key`).

PHP:
```php
$payload = [
    'client_id' => 1,
    'full_name' => $user->name,
    'email' => $user->email,
    'phone' => $user->phone,
    'type' => 'alumni',
    'cohort' => 'COH-2026-A',
    'student_alumni_id' => $user->alumni_id,
];
$encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
$signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $encoded, $apiKey, true)), '+/', '-_'), '=');
$token = 'v1.'.$encoded.'.'.$signature;
$url = 'https://media.loveinarts.com/request-materials?type=alumni&prefill='.urlencode($token);
```

JavaScript (browser/server, Web Crypto):
```js
const enc = new TextEncoder();
const b64url = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes)))
  .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const payload = b64url(enc.encode(JSON.stringify({
  client_id: 1,
  name: 'Portal Student',
  email: 'student@example.com',
  phone: '0780000000',
  type: 'trainee',
  cohort: 'COH-2026-A',
  student_id: 'STD-42',
})));
const key = await crypto.subtle.importKey('raw', enc.encode(apiKey), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
const sig = b64url(await crypto.subtle.sign('HMAC', key, enc.encode(payload)));
const token = `v1.${payload}.${sig}`;
```

Iframe:
```html
<iframe src="https://media.loveinarts.com/request-materials?type=alumni&prefill=TOKEN"
        width="100%" height="1600" style="border:0" allow="camera"></iframe>
```

JS embed:
```html
<div id="lia-mr-embed"></div>
<script src="https://media.loveinarts.com/embed/material-request.js"></script>
<script>
  LIA.materialRequest({ target: '#lia-mr-embed', type: 'alumni', prefill: TOKEN, height: 1600 });
</script>
```
`data-*` auto-mount is also supported: `<script src=".../embed/material-request.js" data-target="#lia-mr-embed" data-type="alumni">`.

## Post-deploy checks
```bash
php artisan test
npm run build
```
Smoke test:
- Open `/request-materials?type=client`; Province → District → Sector → Cell → Village cascades and Village accepts free text.
- Select equipment with quantities; an end date before the pick-up date is refused; signature and date are captured.
- Submit as a client without an ID is refused; with an image/PDF it succeeds. The file is only reachable from the
  staff request detail page as a short-lived link.
- Check *Same as residential* and confirm the project location fields copy across.
- Create a signed token from the portal key and open the iframe link: name/email/phone/type/cohort/student ID are filled
  and locked. A tampered or expired token returns 422.
- Enter the email/phone of an earlier request and use *Use my last request details*: the OTP email arrives, the code
  fills address + emergency contact, and submitting keeps the previous private ID attached.

## Rollback
```bash
php artisan migrate:rollback --step=2   # drops the new api_clients and material_requests columns
```
Rolling back removes the stored private ID/signature references and prefill secrets. The R2 objects themselves are not
deleted by the migration; remove the `material-requests/id-documents/` and `material-requests/signatures/` prefixes
manually if required.
