# ctg-php-uploader

`ctg-php-uploader` is a server-side file upload handler for PHP. It
receives files from `$_FILES`, validates MIME type, extension, and size,
generates safe filenames, and moves files to a destination directory.
Designed to be called from an endpoint after authentication has been
resolved. Validation failures return as structured results; system
failures throw.

**Key Features:**

* **Validate before moving** — MIME type (server-detected via `finfo`),
  extension, size, and MIME-extension cross-check all pass before the
  file touches the destination
* **Safe naming** — UUID v4 by default prevents collisions and path
  traversal. Original name preserved in metadata.
* **Security hardened** — executable deny list, MIME-extension
  cross-validation, directory traversal prevention, non-executable
  file permissions
* **Structured results** — every upload returns the same shape with
  success status, file metadata, and any errors
* **Zero dependencies** — pure PHP 8.1+ with `ext-fileinfo`

## Install

```
composer require ctg/php-uploader
```

## Examples

### Basic Image Upload

```php
use CTG\Uploader\CTGUploader;

$uploader = CTGUploader::init('/var/www/uploads/images', [
    'allowed_types' => ['image/jpeg', 'image/png', 'image/webp'],
    'max_size' => 5 * 1024 * 1024,
]);

$result = $uploader->handle($_FILES['avatar']);

if ($result['success']) {
    $path = $result['file']['path'];
    $storedName = $result['file']['stored_name'];
} else {
    $error = $result['error'];
}
```

### Multiple File Upload

Normalize PHP's multi-file `$_FILES` structure, then handle each:

```php
$uploader = CTGUploader::init('/var/www/uploads/documents', [
    'allowed_types' => ['application/pdf'],
    'max_size' => 25 * 1024 * 1024,
]);

$files = CTGUploader::normalize($_FILES['documents']);
$results = $uploader->handleMultiple($files);

$succeeded = array_filter($results, fn($r) => $r['success']);
$failed = array_filter($results, fn($r) => !$r['success']);
```

### Naming Strategies

```php
// UUID (default) — a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg
$uploader = CTGUploader::init('/var/www/uploads');

// Timestamp — 1711123456_a3f2.jpg
$uploader = CTGUploader::init('/var/www/uploads', [
    'naming' => 'timestamp',
]);

// Original (sanitized) — my-photo.jpg
$uploader = CTGUploader::init('/var/www/uploads', [
    'naming' => 'original',
    'overwrite' => false,
]);
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

### Error Handling

Validation failures return as results. System failures throw:

```php
use CTG\Uploader\CTGUploaderError;

try {
    $result = $uploader->handle($_FILES['file']);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result['error']);
    }
} catch (CTGUploaderError $e) {
    $e->on('DIRECTORY_NOT_WRITABLE', fn($e) => alertOps($e))
      ->on('MOVE_FAILED', fn($e) => logError($e))
      ->otherwise(fn($e) => throw $e);
}
```

## Security

The uploader applies multiple layers of protection:

* **Server-side MIME detection** — uses `finfo_file()`, ignores
  client-reported type
* **Executable deny list** — `.php`, `.phtml`, `.phar`, `.cgi`, `.sh`,
  `.htaccess` and others are always rejected, even if `allowed_extensions`
  includes them. Implemented as a static property overridable by subclasses.
* **MIME-extension cross-validation** — a JPEG file named `.php` is
  rejected as `TYPE_MISMATCH`
* **Directory traversal prevention** — resolved path verified to be
  within destination directory after move
* **Non-executable permissions** — stored files are `chmod 0644`

## Notice

`ctg-php-uploader` is under active development. The core API is
stable. Content scanning for embedded executable code may be added
as a configurable option in a future version.
