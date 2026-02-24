# Flipbook Enhancement Task List

- [x] Replace hardcoded admin credentials with DB-backed users and `password_hash`/`password_verify` in `login.php`.
- [x] Move database credentials to environment config and remove sensitive values from `db.php`.
- [x] Stop using raw `?book=path` URLs; switch viewer links to `?id=<book_id>` and resolve file path server-side.
- [x] Add server-side validation endpoint for book access so only valid records can be loaded.
- [x] Harden PDF upload in `upload.php` with MIME validation (`finfo`), max file size, and safe filename sanitization.
- [x] Replace `mkdir(..., 0777, ...)` with safer directory permissions and error handling.
- [x] Remove `innerHTML` injection for book list rendering in `admin.php`; use DOM APIs with `textContent`.
- [x] Refactor PDF rendering in `assets/js/main.js` to lazy-render visible/nearby pages instead of rendering all pages at once.
- [x] Await `page.render(...).promise` before marking page complete and hiding loader.
- [x] Fix broken control symbols in `index.html` (zoom/menu icons) by using proper UTF-8 or SVG icons.
- [x] Add page counter UI (`current / total`) and keep it synced on flip events.
- [x] Add jump-to-page control with validation for page bounds.
- [x] Add keyboard navigation (`ArrowLeft`, `ArrowRight`, `Home`, `End`, `+`, `-`, `0`).
- [x] Add fullscreen toggle.
- [ ] Verify responsive behavior on desktop/mobile after feature changes.
- [x] Persist and restore last-read page per book (localStorage).
- [x] Add error UI state for invalid/missing PDFs instead of `alert(...)`.
- [x] Add basic logging and user-facing messages for upload/list/view failures.
- [x] Add a quick regression checklist: login, upload, list, open, flip, zoom, download, mobile view.

## Admin Library Management (New)

- [x] Add `Trash` flow using soft delete (`delete_book.php`) instead of immediate hard delete.
- [x] Add restore endpoint/action (`restore_book.php`) for trashed books.
- [x] Add search in admin list (`q` filter by title/file name).
- [x] Add status filters in admin list (`active`, `trashed`, `all`).
- [x] Add pagination in admin list (`page`, `per_page`) with summary + page controls.
- [x] Show metadata in admin list (file size, page count, uploaded by/time, updated by/time).
- [x] Extend version history with metadata per version (size/pages/uploader/time).

## Suggested Next Improvements

- [ ] Add optional hard-delete action for trash items (with second confirmation) for storage cleanup.
- [ ] Add audit trail table for admin actions (`upload`, `replace`, `trash`, `restore`, `login`).
- [ ] Add CSRF tokens to admin POST actions (`upload`, `replace`, `trash`, `restore`).
- [ ] Add permanent file cleanup job for orphaned uploads (old versions no longer referenced).

## Regression Checklist

- [ ] Login works with a DB user (`users` table + hashed password).
- [ ] PDF upload succeeds and rejects non-PDF / oversized files.
- [ ] Book list loads and view/copy link actions use `?id=<book_id>`.
- [ ] Admin search/filter/pagination works as expected with >20 books.
- [ ] Trash/restore flow works and trashed books are not served by `get_book.php` (HTTP 410).
- [ ] Metadata fields (size/pages/uploader/time) appear correctly in admin list.
- [ ] Viewer opens valid books and shows an inline error for invalid book id.
- [ ] Flip navigation works with buttons and keyboard shortcuts.
- [ ] Zoom in/out/reset and drag-to-pan work as expected.
- [ ] Jump-to-page works and rejects out-of-range values.
- [ ] Fullscreen toggle works and exits cleanly.
- [ ] Download link points to the resolved PDF.
- [ ] Last-read page restores when reopening the same book.
- [ ] Layout remains usable on desktop and mobile viewport sizes.
