# Deployment Guide

Production deployment checklist for the flipbook project.

## 1) Server Requirements

- PHP 8.0+ with:
  - `pdo`
  - `pdo_mysql`
  - `fileinfo`
- MySQL / MariaDB
- Web server (Apache or Nginx)
- HTTPS certificate (recommended for admin session security)

## 2) Database Setup

1. Create DB and tables from `schema.sql`.
2. If upgrading an existing installation, run migrations in order:
   - `migrations/2026-02-24-book-versions.sql`
   - `migrations/2026-02-24-soft-delete-metadata.sql`
   - `migrations/2026-02-25-admin-audit-trail.sql`
3. Run each migration once per environment.
4. Create at least one admin user in `users`:
   - generate hash using `password_hash(...)`
   - insert username + hash into `users`

Notes:

- Replaced books populate `book_versions` automatically.
- Existing pre-migration books may have `file_size_bytes` / `page_count` as `NULL` until replaced or backfilled.

Example hash generation:

```bash
php -r "echo password_hash('StrongPasswordHere', PASSWORD_DEFAULT), PHP_EOL;"
```

## 3) Configure Environment Variables

Configure these for your PHP runtime:

- `DB_HOST`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `DB_CHARSET` (typically `utf8mb4`)

Notes:

- `db.php` has defaults, but explicit production values are safer.
- Do not commit secrets to source control.

## 4) File Permissions

- Ensure `uploads/` exists and is writable by web server user.
- Uploaded files are stored with random names and `0644` mode.
- Keep directory listing disabled on web server for `uploads/`.

## 5) Web Server Routing

No framework router is required; direct file mapping is used:

- `/login.php`
- `/admin.php`
- `/upload.php`
- `/replace_book.php`
- `/update_book.php`
- `/list_books.php`
- `/list_audit_logs.php`
- `/delete_book.php`
- `/restore_book.php`
- `/hard_delete_book.php`
- `/list_book_versions.php`
- `/get_book.php`
- `/index.html`

Make sure static assets under `assets/` are publicly readable.

## 6) Security Hardening (Recommended)

Current app provides session auth + upload validation + CSRF for login/admin POST actions, but for production add:

- Login throttling/rate limiting
- Secure session cookie settings:
  - `session.cookie_httponly=1`
  - `session.cookie_secure=1` (HTTPS)
  - `session.cookie_samesite=Lax` or `Strict`
- HTTP security headers:
  - `Content-Security-Policy`
  - `X-Frame-Options`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy`
- Restrict access to `admin.php` by IP/VPN if needed

## 7) Backup Strategy

Minimum backup scope:

- MySQL database (`users`, `books`, `book_versions`)
- `uploads/` directory

Suggested schedule:

- Daily DB backup
- Daily/weekly uploads backup (depending on upload volume)

Optional storage maintenance:

- Run orphan cleanup in dry-run mode first: `php cleanup_orphan_uploads.php`
- Run delete mode only after review: `php cleanup_orphan_uploads.php --delete`

## 8) Smoke Test After Deploy

1. Login with admin user.
2. Upload a valid PDF.
3. Confirm non-PDF upload is rejected.
4. Confirm book list appears in admin with metadata (size/pages/upload user/time).
5. Test search, status filters (`active`/`trashed`/`all`), and pagination.
6. Replace one PDF and verify version history increments while `book_id` link stays the same.
7. Move a book to trash, confirm it is hidden from active list, then restore it.
8. Open viewer link `index.html?id=<book_id>`.
9. Test flip, zoom, jump, fullscreen, and download.
10. Move a trashed book through hard-delete flow and verify second confirmation is required.
11. Update one book title via `Edit Title` modal (without file replacement) and verify list/search reflects new title.
12. Verify audit records exist for `login`, `upload`, `replace`, `update_title`, `trash`, `restore`, and `hard_delete`.
13. Test mobile viewport (`<=768px`) to confirm control bar remains usable and does not overlap critical content.
14. On iOS/mobile, confirm controls remain accessible near safe-area/notch/home-indicator regions.
15. While zoomed on touch device, verify drag-to-pan works without unwanted browser bounce/scroll.
16. Reopen same book and verify last page restore.

## 9) Troubleshooting

### "Database connection failed."

- Verify DB credentials and host.
- Confirm DB server is running and reachable from PHP.

### Upload fails with validation message

- Check file type is real PDF (`application/pdf`).
- Check file size <= 100MB.
- Ensure PHP upload limits are high enough (for example: `upload_max_filesize=100M`, `post_max_size=110M`).
- Important: apply these limits on the Ubuntu web server runtime (Apache/PHP-FPM), not only on local MacBook development PHP.
- After updating Ubuntu PHP config, reload the web stack (for example `systemctl reload php8.2-fpm` and your web server service).
- Verify `uploads/` write permission.

### Reader says "No valid book id was provided."

- Link must include `?id=<integer>`.
- Confirm `book_id` exists in `books` table.

### Reader says book missing/not found

- Record may exist while file was deleted from `uploads/`.
- Re-upload the PDF or restore from backup.

### Reader returns 410 / "book is currently in trash"

- Restore the book from admin (`restore_book.php` action).
- Or switch admin filter to `Trash` and restore from list.

### Mobile behavior differs by browser

- Landscape mode on small phones reduces usable reading area; validate portrait fallback in QA.
- Fullscreen behavior varies by browser/platform and may be limited in iOS Safari contexts.
