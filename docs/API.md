# API Reference

This document describes the server endpoints used by the flipbook app.

## Conventions

- Content type: JSON for API endpoints unless stated otherwise.
- Auth model: PHP session (`$_SESSION['admin_logged_in']`).
- CSRF model: POST actions require `csrf_token` from active session.
- Base URL examples assume: `http://localhost/flipbook`.
- CSRF failures return HTTP `419` with `{"success":false,"message":"Invalid CSRF token."}` for JSON endpoints.

## Authentication

### `POST /login.php`

Authenticates an admin user using username/password from the `users` table.

Request:

- Content type: `application/x-www-form-urlencoded`
- Fields:
  - `username` (string)
  - `password` (string)
  - `csrf_token` (string, required)

Behavior:

- On success: sets session values, redirects to `/admin.php`.
- On failure: renders login page with error message.

### `GET /admin.php`

HTML admin dashboard. Requires authenticated session.

- If not authenticated: redirects to `/login.php`.
- `?logout=true` destroys session and redirects to `/login.php`.
- Exposes CSRF token via `<meta name="csrf-token">` for frontend POST requests.

## Books

### `GET /list_books.php`

Returns book records for admin dashboard.

Auth required: Yes

Query params:

- `page` (integer, default `1`)
- `per_page` (integer, default `10`, max `100`)
- `status` (`active` | `trashed` | `all`, default `active`)
- `q` (string, optional search on title/file name)

Success response:

```json
{
  "success": true,
  "books": [
    {
      "id": 1,
      "title": "sample_document",
      "file_name": "Sample v3.pdf",
      "file_size_bytes": 1203301,
      "page_count": 52,
      "uploaded_at": "2026-02-24 09:30:00",
      "deleted_at": null,
      "latest_version": 3,
      "latest_at": "2026-02-24 15:10:00",
      "latest_file_size_bytes": 1203301,
      "latest_page_count": 52,
      "latest_uploaded_by_username": "admin",
      "created_by_username": "admin",
      "deleted_by_username": null
    }
  ],
  "pagination": {
    "page": 1,
    "perPage": 10,
    "total": 1,
    "totalPages": 1,
    "hasPrev": false,
    "hasNext": false
  },
  "filters": {
    "q": "",
    "status": "active"
  }
}
```

### `GET /list_audit_logs.php`

Returns admin audit trail entries for dashboard review.

Auth required: Yes

Query params:

- `page` (integer, default `1`)
- `per_page` (integer, default `10`, max `100`)
- `action` (string, optional exact action filter; e.g. `login`, `upload`, `replace`, `update_title`, `trash`, `restore`, `hard_delete`)

Success response:

```json
{
  "success": true,
  "logs": [
    {
      "id": 101,
      "admin_user_id": 1,
      "admin_username": "admin",
      "action": "replace",
      "book_id": 12,
      "book_title": "sample_document",
      "details_json": "{\"version\":4}",
      "ip_address": "127.0.0.1",
      "user_agent": "Mozilla/5.0 ...",
      "created_at": "2026-02-25 10:30:00"
    }
  ],
  "pagination": {
    "page": 1,
    "perPage": 10,
    "total": 55,
    "totalPages": 6,
    "hasPrev": false,
    "hasNext": true
  },
  "filters": {
    "action": ""
  }
}
```

### `POST /upload.php`

Uploads a PDF and creates a `books` record with initial version `v1`.

Auth required: Yes

Request:

- Content type: `multipart/form-data`
- Field:
  - `pdf_file` (file)
  - `csrf_token` (string, required)

Validation:

- File must be uploaded via HTTP upload mechanism
- Max size: `25 MB`
- Extension: `.pdf`
- MIME: `application/pdf`

Success response:

```json
{
  "success": true,
  "message": "File uploaded successfully.",
  "bookId": 12,
  "filePath": "uploads/53dbe9f2257f45f5a1d6f6f36c4f920d.pdf",
  "fileSizeBytes": 1203301,
  "pageCount": 52
}
```

### `POST /replace_book.php`

Replaces the current PDF for an existing `book_id` and creates a new version entry.

Auth required: Yes

Request:

- Content type: `multipart/form-data`
- Fields:
  - `id` (integer, required)
  - `pdf_file` (file, required)
  - `csrf_token` (string, required)

Behavior:

- `book_id` stays the same.
- `books.file_path` is updated to the new PDF.
- A new row is inserted into `book_versions` with incremented `version_number`.
- Replacement is blocked if the book is currently in trash.

Success response:

```json
{
  "success": true,
  "message": "PDF replaced successfully.",
  "bookId": 12,
  "version": 4,
  "filePath": "uploads/9f0fd8f9e34f4497b8f2a618dd5c8d58.pdf",
  "fileSizeBytes": 1210000,
  "pageCount": 53
}
```

### `POST /update_book.php`

Updates book metadata (currently title only) without replacing the PDF file.

Auth required: Yes

Request:

- Content type: `multipart/form-data` or `application/x-www-form-urlencoded`
- Fields:
  - `id` (integer, required)
  - `title` (string, required, max 255 chars)
  - `csrf_token` (string, required)

Success response:

```json
{
  "success": true,
  "message": "Title updated.",
  "bookId": 12,
  "title": "Updated Catalog 2026"
}
```

### `POST /delete_book.php`

Moves a book to trash (soft delete).

Auth required: Yes

Request:

- Content type: `multipart/form-data` or `application/x-www-form-urlencoded`
- Fields:
  - `id` (integer, required)
  - `csrf_token` (string, required)

Success response:

```json
{
  "success": true,
  "message": "Book moved to trash."
}
```

### `POST /restore_book.php`

Restores a trashed book back to active state.

Auth required: Yes

Request:

- Content type: `multipart/form-data` or `application/x-www-form-urlencoded`
- Fields:
  - `id` (integer, required)
  - `csrf_token` (string, required)

Success response:

```json
{
  "success": true,
  "message": "Book restored from trash."
}
```

### `POST /hard_delete_book.php`

Permanently deletes a trashed book and its version history, then attempts to remove unreferenced files from `uploads/`.

Auth required: Yes

Request:

- Content type: `multipart/form-data` or `application/x-www-form-urlencoded`
- Fields:
  - `id` (integer, required)
  - `confirm_phrase` (must equal `DELETE`)
  - `csrf_token` (string, required)

Success response:

```json
{
  "success": true,
  "message": "Book permanently deleted.",
  "deletedFilesCount": 2,
  "missingFilesCount": 0,
  "skippedFilesCount": 0
}
```

### `GET /list_book_versions.php?id=<book_id>`

Returns version history for a book.

Auth required: Yes

Success response:

```json
{
  "success": true,
  "book": {
    "id": 12,
    "title": "sample_document",
    "isDeleted": false,
    "currentVersion": 4
  },
  "versions": [
    {
      "version": 4,
      "fileName": "Sample v4.pdf",
      "fileSizeBytes": 1210000,
      "pageCount": 53,
      "createdAt": "2026-02-24 15:10:00",
      "uploadedByUserId": 1,
      "uploadedByUsername": "admin",
      "isCurrent": true,
      "isAvailable": true,
      "downloadUrl": "uploads/9f0fd8f9e34f4497b8f2a618dd5c8d58.pdf"
    }
  ]
}
```

## Reader Lookup

### `GET /get_book.php?id=<book_id>`

Resolves a public `book_id` to a validated file URL for the viewer.

Auth required: No

Query params:

- `id` (integer, required)

Success response:

```json
{
  "success": true,
  "book": {
    "id": 12,
    "title": "sample_document",
    "fileUrl": "uploads/53dbe9f2257f45f5a1d6f6f36c4f920d.pdf",
    "fileName": "Sample Document.pdf"
  }
}
```

Error responses:

- `400` invalid book id or invalid stored file path
- `404` book not found or file missing
- `410` book is currently in trash
- `500` database/lookup failure

## Reader Page Contract

### `GET /index.html?id=<book_id>`

Not an API response, but a required route contract for the frontend:

- The reader expects the query key `id`.
- The frontend calls `get_book.php?id=<id>` before loading PDF.js.
- If `id` is missing/invalid, reader shows inline error state.
