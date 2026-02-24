# API Reference

This document describes the server endpoints used by the flipbook app.

## Conventions

- Content type: JSON for API endpoints unless stated otherwise.
- Auth model: PHP session (`$_SESSION['admin_logged_in']`).
- Base URL examples assume: `http://localhost/flipbook`.

## Authentication

### `POST /login.php`

Authenticates an admin user using username/password from the `users` table.

Request:

- Content type: `application/x-www-form-urlencoded`
- Fields:
  - `username` (string)
  - `password` (string)

Behavior:

- On success: sets session values, redirects to `/admin.php`.
- On failure: renders login page with error message.

### `GET /admin.php`

HTML admin dashboard. Requires authenticated session.

- If not authenticated: redirects to `/login.php`.
- `?logout=true` destroys session and redirects to `/login.php`.

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

### `POST /upload.php`

Uploads a PDF and creates a `books` record with initial version `v1`.

Auth required: Yes

Request:

- Content type: `multipart/form-data`
- Field:
  - `pdf_file` (file)

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

### `POST /delete_book.php`

Moves a book to trash (soft delete).

Auth required: Yes

Request:

- Content type: `multipart/form-data` or `application/x-www-form-urlencoded`
- Fields:
  - `id` (integer, required)

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

Success response:

```json
{
  "success": true,
  "message": "Book restored from trash."
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
