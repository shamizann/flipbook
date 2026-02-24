# Flipbook PDF Viewer

A lightweight PHP + JavaScript application to upload PDFs and read them in a page-flip experience.

The project includes:
- Admin login and library management
- PDF upload with server-side validation
- Trash + restore workflow (soft delete, no immediate permanent removal)
- PDF replacement with stable link (`book_id` stays the same)
- Version history for each book in admin dashboard
- Search/filter/pagination for large libraries
- PDF metadata in admin (file size, page count, uploader, timestamps)
- Link sharing with `book_id` (not raw file path)
- Flipbook viewer with consistent prev/next flip animation, zoom, page jump, fullscreen, keyboard shortcuts, last-page restore, and mobile-optimized controls

## Stack

- PHP (server endpoints + session auth)
- MySQL (users and books metadata)
- PDF.js (`assets/js/pdf.min.js`)
- StPageFlip (`assets/js/page-flip.browser.js`)
- Plain HTML/CSS/JavaScript (no build step)

## Project Layout

```text
flipbook/
  admin.php            # Admin UI (upload/list/copy links)
  login.php            # Admin authentication
  upload.php           # Authenticated PDF upload endpoint
  replace_book.php     # Authenticated replace endpoint (new version, same book id)
  restore_book.php     # Authenticated restore-from-trash endpoint
  list_books.php       # Authenticated library listing endpoint
  list_book_versions.php # Authenticated version history endpoint
  delete_book.php      # Authenticated move-to-trash endpoint
  get_book.php         # Public endpoint to resolve book id -> validated file URL
  index.html           # Reader UI
  db.php               # DB connection (env-driven)
  schema.sql           # DB schema
  migrations/          # Upgrade SQL migrations
  uploads/             # Stored PDF files
  assets/
    css/style.css
    js/admin.js
    js/main.js
```

## Requirements

- PHP 8.0+ with extensions:
  - `pdo`
  - `pdo_mysql`
  - `fileinfo`
- MySQL / MariaDB
- A web server (Apache/Nginx or Laragon)

## Quick Start

### 1) Create the database

Run `schema.sql`:

```sql
SOURCE schema.sql;
```

or copy/paste its contents in your SQL client.

If you already had this project running before version history was added, also run:

```sql
SOURCE migrations/2026-02-24-book-versions.sql;
SOURCE migrations/2026-02-24-soft-delete-metadata.sql;
```

### 2) Configure DB connection

`db.php` reads these environment variables:

- `DB_HOST` (default `127.0.0.1`)
- `DB_NAME` (default `flipbook`)
- `DB_USER` (default `root`)
- `DB_PASS` (default empty)
- `DB_CHARSET` (default `utf8mb4`)

PowerShell example:

```powershell
$env:DB_HOST = "127.0.0.1"
$env:DB_NAME = "flipbook"
$env:DB_USER = "root"
$env:DB_PASS = ""
$env:DB_CHARSET = "utf8mb4"
```

### 3) Create an admin user

Generate a password hash:

```powershell
php -r "echo password_hash('ChangeMeNow!', PASSWORD_DEFAULT), PHP_EOL;"
```

Insert into DB:

```sql
INSERT INTO users (username, password_hash)
VALUES ('admin', '<paste_hash_here>');
```

### 4) Ensure uploads directory is writable

The app writes uploaded files to `uploads/`.

### 5) Open the app

- Admin login: `/flipbook/login.php`
- Admin dashboard: `/flipbook/admin.php`
- Reader: `/flipbook/index.html?id=<book_id>`

For a quick local test without Apache/Nginx, you can also run:

```powershell
php -S localhost:8000
```

Then open `http://localhost:8000/login.php`.

## Usage Flow

1. Login at `/login.php`.
2. Upload PDF in `/admin.php`.
3. Use `View` to open the book, `Copy Link` to share it, `Replace PDF` to publish a new version with same link, or `Move to Trash` to hide it.
4. Restore books from `Trash` when needed.
5. Reader resolves `id` via `get_book.php`, then loads the PDF.

## Reader Controls

- Previous/Next buttons
- Zoom in / out / reset
- Menu:
  - First page
  - Last page
  - Jump to page
  - Toggle fullscreen
  - Download PDF
- Keyboard:
  - `ArrowLeft` / `ArrowRight`: previous/next
  - `Home` / `End`: first/last
  - `+` / `-`: zoom in/out
  - `0`: reset zoom

## Mobile View Notes

- Responsive control bar for smaller screens (`<= 768px`)
- Touch target size tuned for mobile controls (`44x44px` minimum)
- Safe-area aware bottom spacing for iOS devices (`env(safe-area-inset-bottom)`)
- Uses dynamic viewport height (`100dvh`) to reduce Safari address-bar viewport jump
- Touch drag-to-pan is optimized for zoomed mode to reduce accidental page/bounce scrolling

## Security Notes

- Admin-only endpoints:
  - `upload.php`
  - `list_books.php`
  - `replace_book.php`
  - `delete_book.php`
  - `restore_book.php`
  - `list_book_versions.php`
- Upload validation includes:
  - extension check (`.pdf`)
  - MIME check (`application/pdf`)
  - max size (`25 MB`)
  - random stored file name
- Versioning model:
  - Each replacement creates a new row in `book_versions`.
  - `books.file_path` always points to the current version.
  - Shared link (`index.html?id=<book_id>`) remains unchanged across replacements.
- Trash model:
  - `delete_book.php` marks a book as trashed (`deleted_at`, `deleted_by_user_id`).
  - `restore_book.php` restores it without changing `book_id` or history.
- Viewer links use `id` lookup, not raw file paths.
- `get_book.php` validates that resolved files are inside `uploads/`.
- Trashed books are not served by `get_book.php`.

Current limitations to consider for production:
- No CSRF protection on admin actions yet
- No rate limiting / brute-force protection
- `get_book.php` is public (good for share links, not for private documents)

## API Reference

See [docs/API.md](docs/API.md) for request/response details.

## Deployment Guide

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for production checklist and hardening.

## Maintenance

- Main enhancement checklist: [TASKS.md](TASKS.md)
- Keep `uploads/` and DB backups in sync
- Re-run regression checklist after each significant change
