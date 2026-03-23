# CTGUploader

Server-side file upload handler. Receives files from `$_FILES`,
validates type, size, and extension, generates safe filenames, and
moves files to a destination directory.

### Properties

| Property | Type | Description |
|----------|------|-------------|
| _destination | STRING | Target directory for stored files |
| _allowedTypes | [STRING] | Accepted MIME types (empty = accept all — see XSS note below) |
| _allowedExtensions | [STRING] | Accepted extensions without dot (empty = accept all) |
| _maxSize | INT | Max file size in bytes (0 = no limit) |
| _naming | STRING | Naming strategy: `'uuid'`, `'timestamp'`, `'original'` |
| _overwrite | BOOL | Allow overwriting existing files |
| _createDir | BOOL | Create destination directory if missing |
| _permissions | INT | Directory permissions when creating |

### Static Properties

| Property | Type | Description |
|----------|------|-------------|
| $_deniedExtensions | [STRING] | Server-executable extensions always rejected |
| $_mimeExtensionMap | ARRAY<STRING, [STRING]> | MIME type to expected extensions mapping |

---

## Construction

### CONSTRUCTOR :: STRING, ARRAY -> ctgUploader

Creates an uploader with a destination directory and optional config.
Throws `INVALID_CONFIG` if the naming strategy is unrecognized.

```php
$uploader = new CTGUploader('/var/www/uploads', [
    'allowed_types' => ['image/jpeg', 'image/png'],
    'max_size' => 5 * 1024 * 1024,
    'naming' => 'uuid',
]);
```

**XSS Note:** The MIME extension map includes client-side executable
types (`text/html`, `application/javascript`, `image/svg+xml`).
These are not on the server-executable deny list because they may
be legitimate upload targets. However, if uploaded files are served
directly from a web-accessible directory, these types can execute
in the user's browser (stored XSS). Always configure `allowed_types`
to restrict uploads to the types your application actually needs,
or serve uploads from a separate domain with
`Content-Disposition: attachment` headers.

### CTGUploader.init :: STRING, ARRAY -> ctgUploader

Static factory method. Returns `new static(...)` so subclasses
inherit the factory correctly.

```php
$uploader = CTGUploader::init('/var/www/uploads', [
    'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
    'max_size' => 5 * 1024 * 1024,
]);
```

---

## Instance Methods

### ctgUploader.handle :: ARRAY -> ARRAY

Handles a single file upload. Accepts a `$_FILES` entry (the
associative array with `tmp_name`, `name`, `size`, `type`, `error`
keys). Validates in order: PHP upload error, file existence,
executable deny list, MIME type (via `finfo_file`), extension,
MIME-extension cross-check, file size, destination directory, and
directory traversal. Returns a structured result with `success`,
`file` metadata, and `error`. Throws `CTGUploaderError` only on
system failures (directory creation, move failure).

```php
$result = $uploader->handle($_FILES['avatar']);

if ($result['success']) {
    $path = $result['file']['path'];
    // save to database, return to client
} else {
    $error = $result['error'];
    // return error to client
}
```

### ctgUploader.handleMultiple :: [ARRAY] -> [ARRAY]

Handles multiple file uploads. Accepts an array of individual
`$_FILES` entries (already normalized via `normalize()`). Returns
an array of results, one per file. Each file is handled
independently — one failure does not stop the others.

```php
$files = CTGUploader::normalize($_FILES['documents']);
$results = $uploader->handleMultiple($files);
```

---

## Static Methods

### CTGUploader.normalize :: ARRAY -> [ARRAY]

Normalizes a `$_FILES` entry for multi-file inputs into an array
of individual file arrays. Single-file inputs (scalar values) are
wrapped in a one-element array. Multi-file inputs (array values)
are restructured so each file is its own associative array.

```php
// Single file — returns [$_FILES['avatar']]
$files = CTGUploader::normalize($_FILES['avatar']);

// Multi-file — restructures into individual entries
$files = CTGUploader::normalize($_FILES['documents']);
```

---

## Result Structure

### Success

```php
[
    'success' => true,
    'file' => [
        'original_name' => 'My Photo.jpg',
        'stored_name' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg',
        'path' => '/var/www/uploads/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg',
        'size' => 245760,
        'type' => 'image/jpeg',
        'extension' => 'jpg',
    ],
    'error' => null,
]
```

### Error

```php
[
    'success' => false,
    'file' => null,
    'error' => [
        'type' => 'INVALID_TYPE',
        'message' => 'File type application/pdf is not allowed',
        'data' => ['original_name' => 'doc.pdf', 'type' => 'application/pdf', ...],
    ],
]
```

### Validation Error Types

| Type | When |
|------|------|
| `UPLOAD_ERROR` | PHP upload error (`$file['error'] !== UPLOAD_ERR_OK`) |
| `NO_FILE` | No file uploaded or `tmp_name` missing |
| `EXECUTABLE_DENIED` | Extension on server-executable deny list |
| `INVALID_TYPE` | MIME type not in `allowed_types` |
| `INVALID_EXTENSION` | Extension not in `allowed_extensions` |
| `TYPE_MISMATCH` | MIME type inconsistent with extension |
| `FILE_TOO_LARGE` | File exceeds `max_size` |
| `FILE_EXISTS` | File exists and `overwrite` is false |
| `PATH_TRAVERSAL` | Resolved path escapes destination directory |
