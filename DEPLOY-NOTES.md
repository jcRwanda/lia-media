# Deploy notes — upload defaults & day galleries

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

Nothing in this change removes or renames existing columns; curated albums keep working exactly as before and the
review/consent workflow and share links are untouched.

## Database
```
php artisan migrate --force
```
Adds three columns to `albums` (`kind`, `day`, `is_default`) with `kind` defaulting to `curated` for existing rows,
plus a unique index on `day` and an index on `(kind, day)`. No data backfill is required for correctness.

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
php artisan test          # 44 tests (2 skipped)
npx vitest run && npx tsc --noEmit && npx vite build
```
Smoke test in the app: upload one image → it appears in the default album **and** in today's day gallery; open an
existing share link to confirm visitor galleries still render.

## Rollback
```
php artisan migrate:rollback --step=1
```
Drops the three album columns and their indexes. Day-gallery rows then become ordinary (curated) albums; no assets or
curated albums are affected.
