# Cloudflare R2 setup

The archive stores **originals and derivatives only in R2** (no second copy yet; `MEDIA_BACKUP_DRIVER=null`).
Browsers upload straight to R2 with presigned URLs, so the bucket needs CORS. Nothing below has been created yet.

## 1. Bucket
- Cloudflare dashboard → R2 → Create bucket → name `lia-media-archive`, location hint *Europe* (or automatic).
- Keep the bucket **private**: no public `r2.dev` URL and no custom public domain. All reads go through short-lived presigned URLs.

## 2. API token
R2 → Manage API tokens → Create token:
- Permission: **Object Read & Write**, scoped to the `lia-media-archive` bucket only.
- Copy the Access Key ID / Secret into the server `.env` (never into git):

```
MEDIA_DISK=r2
R2_ACCOUNT_ID=<account id>
R2_ACCESS_KEY_ID=<key id>
R2_SECRET_ACCESS_KEY=<secret>
R2_BUCKET=lia-media-archive
R2_ENDPOINT=https://<account id>.r2.cloudflarestorage.com
R2_REGION=auto
R2_USE_PATH_STYLE=true
```

## 3. CORS policy
R2 → bucket → Settings → CORS policy:

```json
[
  {
    "AllowedOrigins": ["https://media.loveinarts.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["content-type", "x-amz-checksum-sha256", "x-amz-sdk-checksum-algorithm"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

- `ETag` must be exposed: the browser reads each multipart part's ETag to complete the upload.
- `x-amz-checksum-sha256` is signed into single-PUT URLs, so R2 rejects bytes that don't match the hash computed in the browser.
- Add `http://localhost:8020` only on a separate dev bucket, never on production.

## 4. Lifecycle rule
R2 → bucket → Settings → Object lifecycle rules → Add rule:
- *Abort incomplete multipart uploads* after **7 days** (whole bucket).
The app also aborts expired sessions hourly (`uploads:prune`); this rule is the safety net.
Do **not** add expiry rules for `originals/` or `derivatives/`.

## 5. Key layout
```
originals/YYYY/MM/<asset uuid>/<original filename>
derivatives/<asset uuid>/{thumb,preview,poster}.webp
```

## 6. Verify
```
php artisan storage:snapshot          # LIST the bucket, prints objects / GB / est. $
```
Then upload a small photo and a >100 MB video from the Upload page; both should end `stored`, and the
checksum job (queue run by cron `schedule:run`) should mark the multipart one verified.

## Costs (Oct 2026 list price)
Storage $0.015/GB-month after 10 GB free; Class A (writes) $4.50/million, Class B (reads) $0.36/million; **no egress fees**.
Price, free tier and budget are editable in Settings and drive the Storage page estimate.

## Later: backups
`App\Backup\BackupDriver` is the extension point (`backupObject`, `verifyObject`). Implement a driver
(e.g. a second R2 account or Backblaze B2), bind it for `MEDIA_BACKUP_DRIVER`, and Settings will show it as on.
