# ctg-php-uploader — Library Specification

## Overview

A server-side file upload handler for PHP. Takes an incoming file from
`$_FILES`, validates it, and moves it to a destination. Handles type
checking, size limits, naming, and directory creation. Designed to be
called from an endpoint after authentication and authorization have
already been resolved.

No authentication. No routing. No storage abstraction. Just: receive
file, validate it, put it where it belongs, report what happened.

---

## Design Principles

1. **Post-auth** — this class assumes the request is already
   authenticated and authorized. It only handles the file itself.
2. **Validate before moving** — type, size, and extension are checked
   before the file touches the destination directory
3. **Configurable constraints** — allowed types, max size, and
   naming strategy are set at construction, not hardcoded
4. **Structured results** — every upload returns the same shape
   with success status, file metadata, and any errors
5. **Safe naming** — files are renamed by default to prevent
   collisions and path traversal. Original name is preserved in
   metadata but never used for storage.
6. **No exceptions on validation failure** — a rejected file is a
   result, not an exception. Exceptions are for system failures
   (permissions, disk space)
7. **Defense in depth** — MIME-extension cross-validation, hardcoded
   executable deny list, directory traversal prevention, and
   non-executable file permissions

---

## Class Interface

```php
namespace CTG\Uploader;

class CTGUploader
{
    // ─── Construction ──────────────────────────────────────

    // CONSTRUCTOR :: STRING, ARRAY -> $this
    // Creates an uploader with a destination directory and config
    public function __construct(string $destination, array $config = []);

    // Static Factory Method :: STRING, ARRAY -> ctgUploader
    public static function init(string $destination, array $config = []): static;

    // ─── Upload ────────────────────────────────────────────

    // :: ARRAY -> ARRAY
    // Handle a single file upload from $_FILES
    public function handle(array $file): array;

    // :: ARRAY -> [ARRAY]
    // Handle multiple file uploads from $_FILES
    public function handleMultiple(array $files): array;

    // ─── Static ────────────────────────────────────────────

    // :: ARRAY -> ARRAY
    // Normalize a $_FILES entry for multi-file inputs into
    // an array of individual file arrays
    public static function normalize(array $files): array;
}
```

---

## Constructor & Factory

```php
// Basic — just a destination directory
$uploader = CTGUploader::init('/var/www/uploads');

// With config
$uploader = CTGUploader::init('/var/www/uploads', [
    'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
    'max_size' => 5 * 1024 * 1024,  // 5MB
    'naming' => 'uuid',              // 'uuid', 'timestamp', 'original'
    'overwrite' => false,            // reject if file exists (when naming => 'original')
    'create_dir' => true,            // create destination if it doesn't exist
    'permissions' => 0755,           // directory permissions when creating
]);
```

### Config Options

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `allowed_types` | `array` | `[]` | MIME types to accept. Empty = accept all |
| `allowed_extensions` | `array` | `[]` | File extensions to accept (without dot). Empty = accept all |
| `max_size` | `int` | `0` | Max file size in bytes. 0 = no limit |
| `naming` | `string` | `'uuid'` | Naming strategy: `'uuid'`, `'timestamp'`, `'original'` |
| `overwrite` | `bool` | `false` | Allow overwriting existing files |
| `create_dir` | `bool` | `true` | Create destination directory if missing |
| `permissions` | `int` | `0755` | Directory permissions when `create_dir` is true |

### Naming Strategies

| Strategy | Output | Example |
|----------|--------|---------|
| `uuid` | UUID v4 + original extension | `a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg` |
| `timestamp` | Unix timestamp + random suffix + extension | `1711123456_a3f2.jpg` |
| `original` | Original filename, sanitized | `my-photo.jpg` |

The `original` strategy sanitizes the filename: strips path
components, replaces unsafe characters with hyphens, and lowercases.
When `overwrite` is false and a file with the same name exists, the
upload is rejected.

---

## handle() — Single File Upload

### Signature

```php
// :: ARRAY -> ARRAY
// Handle a single file upload from $_FILES
public function handle(array $file): array;
```

Accepts a single `$_FILES` entry (the associative array with
`tmp_name`, `name`, `size`, `type`, `error` keys).

### Validation Order

1. **PHP upload error** — check `$file['error']` for PHP-level
   upload failures (`UPLOAD_ERR_INI_SIZE`, `UPLOAD_ERR_NO_FILE`, etc.)
2. **File exists** — verify `tmp_name` exists and `is_uploaded_file()`
3. **Executable deny list** — reject files with server-executable
   extensions (`.php`, `.phtml`, `.phar`, `.cgi`, `.sh`, `.htaccess`,
   etc.) regardless of config. This cannot be overridden by
   `allowed_extensions`, but the list itself can be overridden by
   subclasses.
4. **MIME type** — check against `allowed_types` if configured,
   using `finfo_file()` (not client-reported type)
5. **Extension** — check against `allowed_extensions` if configured
6. **MIME-extension cross-validation** — verify the detected MIME
   type is consistent with the file extension. A file with MIME
   `image/jpeg` but extension `.php` is rejected. Uses an internal
   mapping of MIME types to expected extensions.
7. **File size** — check against `max_size` if configured
8. **Destination** — verify/create destination directory
9. **Directory traversal check** — resolve the final storage path
   via `realpath()` and verify it is still within the destination
   directory

If any check fails, the upload is rejected with an error result.
No partial work — the file is not moved.

### Success Result

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

### Error Result

```php
[
    'success' => false,
    'file' => null,
    'error' => [
        'type' => 'INVALID_TYPE',
        'message' => 'File type application/pdf is not allowed',
        'data' => [
            'original_name' => 'document.pdf',
            'type' => 'application/pdf',
            'allowed' => ['image/jpeg', 'image/png', 'image/webp'],
        ],
    ],
]
```

### Error Types

| Type | When |
|------|------|
| `UPLOAD_ERROR` | PHP upload error (`$file['error'] !== UPLOAD_ERR_OK`) |
| `NO_FILE` | No file was uploaded or `tmp_name` is missing |
| `EXECUTABLE_DENIED` | File has an executable extension (hardcoded deny list) |
| `INVALID_TYPE` | MIME type not in `allowed_types` |
| `INVALID_EXTENSION` | Extension not in `allowed_extensions` |
| `TYPE_MISMATCH` | MIME type and extension are inconsistent |
| `FILE_TOO_LARGE` | File exceeds `max_size` |
| `FILE_EXISTS` | File already exists and `overwrite` is false |
| `PATH_TRAVERSAL` | Resolved path escapes the destination directory |
| `MOVE_FAILED` | `move_uploaded_file()` failed (permissions, disk) |
| `DIRECTORY_ERROR` | Destination directory cannot be created or is not writable |

---

## handleMultiple() — Multiple File Uploads

### Signature

```php
// :: ARRAY -> [ARRAY]
// Handle multiple file uploads from $_FILES
public function handleMultiple(array $files): array;
```

Accepts an array of individual `$_FILES` entries (already
normalized — one array per file). Returns an array of results,
one per file. Each result has the same structure as `handle()`.

```php
$files = CTGUploader::normalize($_FILES['documents']);

$uploader = CTGUploader::init('/var/www/uploads', [
    'allowed_types' => ['application/pdf'],
    'max_size' => 10 * 1024 * 1024,
]);

$results = $uploader->handleMultiple($files);
// [
//     ['success' => true, 'file' => [...], 'error' => null],
//     ['success' => false, 'file' => null, 'error' => [...]],
//     ['success' => true, 'file' => [...], 'error' => null],
// ]
```

Each file is handled independently — one failure does not stop
the others.

---

## normalize() — $_FILES Normalization

### The Problem

PHP structures multi-file uploads differently from single-file
uploads. A single file input:

```php
$_FILES['avatar'] = [
    'name' => 'photo.jpg',
    'type' => 'image/jpeg',
    'tmp_name' => '/tmp/php123',
    'error' => 0,
    'size' => 12345,
];
```

A multi-file input (`<input type="file" name="documents[]" multiple>`):

```php
$_FILES['documents'] = [
    'name' => ['doc1.pdf', 'doc2.pdf'],
    'type' => ['application/pdf', 'application/pdf'],
    'tmp_name' => ['/tmp/php456', '/tmp/php789'],
    'error' => [0, 0],
    'size' => [54321, 67890],
];
```

### normalize() Solution

```php
// :: ARRAY -> [ARRAY]
// Normalize a $_FILES entry into an array of individual file arrays
public static function normalize(array $files): array;
```

If the input is already a single file (values are scalars), it
returns `[$files]` — a one-element array. If the input is multi-file
(values are arrays), it restructures into an array of individual
file entries:

```php
$normalized = CTGUploader::normalize($_FILES['documents']);
// [
//     ['name' => 'doc1.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php456', ...],
//     ['name' => 'doc2.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php789', ...],
// ]
```

---

## Error Handling — CTGUploaderError

System-level failures throw `CTGUploaderError`. Validation failures
return error results (they are not thrown).

### CTGUploaderError Class

```php
namespace CTG\Uploader;

class CTGUploaderError extends \Exception
{
    const TYPES = [
        'DIRECTORY_CREATE_FAILED' => 1000,
        'DIRECTORY_NOT_WRITABLE'  => 1001,
        'MOVE_FAILED'             => 2000,
        'INVALID_CONFIG'          => 3000,
    ];

    public readonly string $type;
    public readonly string $msg;
    public readonly mixed  $data;

    private bool $_handled = false;

    // CONSTRUCTOR :: STRING|INT, ?STRING, MIXED -> $this
    public function __construct(
        string|int $type,
        ?string    $msg = null,
        mixed      $data = null
    );

    // :: STRING|INT -> INT|STRING|NULL
    public static function lookup(string|int $key): int|string|null;

    // :: STRING|INT, (ctgUploaderError -> VOID) -> $this
    public function on(string|int $type, callable $handler): static;

    // :: (ctgUploaderError -> VOID) -> VOID
    public function otherwise(callable $handler): void;
}
```

### When Exceptions Are Thrown vs Results Returned

| Scenario | Behavior |
|----------|----------|
| File too large, wrong type, wrong extension | **Result** with `success => false` |
| File already exists (overwrite off) | **Result** with `success => false` |
| PHP upload error | **Result** with `success => false` |
| Cannot create destination directory | **Throws** `DIRECTORY_CREATE_FAILED` |
| Destination not writable | **Throws** `DIRECTORY_NOT_WRITABLE` |
| `move_uploaded_file()` fails | **Throws** `MOVE_FAILED` |
| Invalid config value | **Throws** `INVALID_CONFIG` |

The distinction: validation failures are expected conditions that
the caller can act on (show a message, reject the request). System
failures mean the server environment is broken.

---

## Usage Examples

### Basic Image Upload

```php
$uploader = CTGUploader::init('/var/www/uploads/images', [
    'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
    'max_size' => 5 * 1024 * 1024,
]);

$result = $uploader->handle($_FILES['avatar']);

if ($result['success']) {
    $path = $result['file']['path'];
    $storedName = $result['file']['stored_name'];
    // save path to database, return to client, etc.
} else {
    $error = $result['error'];
    // return error to client
}
```

### Multiple Document Upload

```php
$uploader = CTGUploader::init('/var/www/uploads/documents', [
    'allowed_types' => ['application/pdf', 'application/msword'],
    'allowed_extensions' => ['pdf', 'doc', 'docx'],
    'max_size' => 25 * 1024 * 1024,
]);

$files = CTGUploader::normalize($_FILES['documents']);
$results = $uploader->handleMultiple($files);

$succeeded = array_filter($results, fn($r) => $r['success']);
$failed = array_filter($results, fn($r) => !$r['success']);
```

### Preserving Original Names

```php
$uploader = CTGUploader::init('/var/www/uploads', [
    'naming' => 'original',
    'overwrite' => false,
]);

$result = $uploader->handle($_FILES['document']);
// stored_name: 'my-document.pdf' (sanitized from 'My Document.pdf')
```

### With CTGFnprog

```php
use CTG\FnProg\CTGFnprog;

$files = CTGUploader::normalize($_FILES['photos']);
$results = $uploader->handleMultiple($files);

$uploadedPaths = CTGFnprog::pipe([
    CTGFnprog::filter(fn($r) => $r['success']),
    CTGFnprog::pluck('file'),
    CTGFnprog::pluck('path'),
])($results);
```

### With CTGDB

```php
$result = $uploader->handle($_FILES['avatar']);

if ($result['success']) {
    $db->update('users',
        ['avatar_path' => ['type' => 'str', 'value' => $result['file']['path']]],
        ['id' => ['type' => 'int', 'value' => $userId]]
    );
}
```

### Error Handling

```php
try {
    $result = $uploader->handle($_FILES['file']);
    if (!$result['success']) {
        // Validation failure — tell the user
        http_response_code(400);
        echo json_encode($result['error']);
    }
} catch (CTGUploaderError $e) {
    // System failure — log and return 500
    $e->on('DIRECTORY_NOT_WRITABLE', fn($e) => alertOps($e))
      ->on('MOVE_FAILED', fn($e) => logError($e))
      ->otherwise(fn($e) => throw $e);
}
```

---

## Security

### Executable Deny List

The following extensions are rejected regardless of configuration.
Even if `allowed_extensions` includes them, the uploader refuses.
This is a safety net that is always checked.

Implemented as a static property so it can be inspected and
overridden by subclasses for environments with different requirements:

```php
protected static array $_deniedExtensions = [
    'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'phps',
    'cgi', 'pl',
    'sh', 'bash',
    'htaccess', 'htpasswd',
];
```

A subclass could narrow or extend this list:

```php
class StrictUploader extends CTGUploader {
    protected static array $_deniedExtensions = [
        ...parent::$_deniedExtensions,
        'py', 'rb', 'exe', 'bat', 'cmd',
    ];
}
```

### MIME-Extension Cross-Validation

After MIME type and extension are independently validated, the
uploader verifies they are consistent. This prevents attacks like
`malware.php.jpg` where the extension passes but the MIME type
reveals the actual content, or vice versa.

The cross-check uses a static property mapping, also overridable
by subclasses:

```php
protected static array $_mimeExtensionMap = [
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png' => ['png'],
    'image/gif' => ['gif'],
    'image/webp' => ['webp'],
    'image/svg+xml' => ['svg'],
    'application/pdf' => ['pdf'],
    'text/plain' => ['txt', 'csv', 'log'],
    'text/csv' => ['csv'],
    'application/json' => ['json'],
    'application/xml' => ['xml'],
    'application/zip' => ['zip'],
    // ...additional mappings
];
```

If the detected MIME type has a mapping and the extension is not in
the expected list, the upload is rejected with `TYPE_MISMATCH`. If
the MIME type has no mapping (unknown type), the cross-check is
skipped — the individual MIME and extension checks still apply.

### Directory Traversal Prevention

After the stored filename is generated, the full destination path is
resolved and verified:

```php
$destDir = realpath($this->_destination);
$fullPath = $destDir . DIRECTORY_SEPARATOR . $storedName;

// After move, verify the file landed where expected
$resolvedPath = realpath($fullPath);
if (!str_starts_with($resolvedPath, $destDir)) {
    unlink($fullPath);  // remove the misplaced file
    // return PATH_TRAVERSAL error
}
```

This catches cases where a crafted filename could escape the
destination directory, even after sanitization.

### Stored File Permissions

After a successful `move_uploaded_file()`, the stored file is set to
`0644` (owner read/write, group/others read-only, no execute):

```php
chmod($fullPath, 0644);
```

This prevents uploaded files from being executed directly by the OS,
even if they somehow bypass extension and MIME checks.

### Future Consideration: Content Scanning

A future version may add optional content scanning that inspects
file bytes for embedded executable code (e.g., `<?php` in EXIF
metadata of image files). This would be configurable since it adds
overhead and can produce false positives. For v1, the combination
of MIME validation, extension validation, MIME-extension
cross-checking, the executable deny list, and non-executable file
permissions covers the realistic attack surface.

---

## Internal Implementation

### MIME Type Detection

MIME type is verified using `finfo_file()` on the temp file, not
the client-reported `$file['type']` (which can be spoofed). The
client-reported type is ignored for validation purposes.

```php
$finfo = new \finfo(FILEINFO_MIME_TYPE);
$detectedType = $finfo->file($file['tmp_name']);
```

### Extension Extraction

Extension is derived from the original filename, lowercased:

```php
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
```

### Filename Sanitization (original naming)

```php
$name = pathinfo($file['name'], PATHINFO_FILENAME);
$name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
$name = preg_replace('/-+/', '-', $name);
$name = strtolower(trim($name, '-'));
$storedName = $name . '.' . $extension;
```

### UUID Generation

Uses `random_bytes()` for UUID v4:

```php
$data = random_bytes(16);
$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
```

### PHP Upload Error Mapping

```php
$message = match($file['error']) {
    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize',
    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE',
    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
    UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
    UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
    UPLOAD_ERR_EXTENSION  => 'Upload stopped by PHP extension',
    default               => 'Unknown upload error',
};
```

---

## File Structure

```
ctg-php-uploader/
├── composer.json
├── docs/
│   └── spec.md
├── src/
│   ├── CTGUploader.php
│   └── CTGUploaderError.php
├── tests/
│   ├── CTGUploaderErrorTest.php
│   └── CTGUploaderTest.php
├── staging/
└── README.md
```

### composer.json

```json
{
    "name": "ctg/php-uploader",
    "description": "Server-side file upload handler with validation and safe naming",
    "type": "library",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "CTG\\Uploader\\": "src/"
        }
    },
    "require": {
        "php": ">=8.1",
        "ext-fileinfo": "*"
    }
}
```

---

## Implementation Order

1. **CTGUploaderError** — standalone error class (same pattern)
2. **Constructor + init()** — destination, config, validation of config
3. **normalize()** — static $_FILES restructuring
4. **handle()** — validation pipeline, naming, move
5. **handleMultiple()** — iterates handle() per file
