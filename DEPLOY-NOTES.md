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
