# LIA Media Archive

Private photo/video archive for Love In Arts: uploads straight to Cloudflare R2, review and consent workflow,
albums, and expiring share links for people outside the team.

**Stack:** Laravel 13 API (`/api`, Sanctum SPA cookie auth) · React 19 + TypeScript + Vite + Tailwind 4 (shadcn-style UI),
React Router, TanStack Query, React Hook Form + Zod · MariaDB 10.3+/MySQL · Cloudflare R2 (S3 API) · database queue run by cron.

## Features
- **Roles:** admin, multimedia (upload/edit), communications (review, publish, consent, shares), management (approve, dashboards, audit).
- **Uploads:** browser SHA-256 → dedupe → presigned single PUT (checksum signed) or resumable multipart for files over 100 MB.
  Thumbnails/video posters are made in the browser and uploaded as derivatives (no ffmpeg on the server).
  Server re-verifies hashes on a queued job; interrupted uploads resume from the parts R2 already holds.
- **Upload defaults & day galleries:** new uploads are auto-dated, tagged and filed into the configured default album,
  and every asset lands in an automatic gallery for its capture day (Africa/Kigali). `php artisan gallery:backfill`
  groups existing media into day galleries (idempotent).
- **Library:** filters (project, event, cohort, category, tags, type, review/consent state), bulk edit, albums, soft delete, audited downloads.
- **Review:** unreviewed → approved → published, plus restricted/archived; publishing is blocked while consent is missing/withdrawn;
  withdrawing consent restricts every linked item automatically; publication usage log.
- **Sharing:** public, access-code (hashed, rate-limited, 2-hour grant) or restricted personal links; expiry, max visitors, revoke, rotate.
  Visitors only ever see approved/published items with consent in order, through short-lived presigned URLs.
- **Dashboard, Storage** (DB totals + daily bucket count, cost estimate vs budget), **Settings**, **Audit log**.

## Local development
```
composer install && npm install
cp .env.example .env && php artisan key:generate
# MariaDB 10.3 (as on the VPS) and an S3-compatible server for local work:
docker run -d --name lia-media-mariadb -p 127.0.0.1:33103:3306 -e MARIADB_DATABASE=lia_media -e MARIADB_USER=lia_media \
  -e MARIADB_PASSWORD=devpass -e MARIADB_ROOT_PASSWORD=devroot mariadb:10.3
php artisan migrate --seed          # demo users {admin,multimedia,communications,management}@media.test / password (refuses in production)
npm run dev & php artisan serve --port=8020
```
Set `MEDIA_DISK=fake` to click around without object storage, or point `R2_ENDPOINT` at a local S3 server
(SeaweedFS `chrislusf/seaweedfs server -s3` works; MinIO likewise). `DEMO_MEDIA_DIR=<folder of demoNN.webp>` makes the
seeder upload demo pictures for screenshots.

## Tests
```
php artisan test                        # feature + unit (sqlite, fake storage)
E2E_S3_ENDPOINT=http://127.0.0.1:8333 E2E_S3_KEY=… E2E_S3_SECRET=… php artisan test --testsuite=E2E
npx vitest run && npx tsc --noEmit && npx vite build
```

## Production notes
- PHP 8.4+, MariaDB 10.3+, HTTPS. Cron: `* * * * * php /path/artisan schedule:run` (runs the queue, prunes uploads hourly,
  counts the bucket daily at 02:30).
- R2 bucket, token, CORS and lifecycle: see [docs/r2-setup.md](docs/r2-setup.md).
- `SANCTUM_STATEFUL_DOMAINS` and `SESSION_DOMAIN` must match the site host; `SESSION_SECURE_COOKIE=true`.
- No backups yet — R2 is the only copy. See `app/Backup` for the driver interface.
