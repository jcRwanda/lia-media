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
