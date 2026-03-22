# CTGUploaderError

Typed error class for upload system failures. Extends `\Exception`
with a string type code, structured context data, and a chainable
handler pattern. Thrown only on system-level failures — validation
errors are returned as result structures.

### Properties

| Property | Type | Description |
|----------|------|-------------|
| type | STRING | Error type name (e.g. `'MOVE_FAILED'`) |
| msg | STRING | Human-readable error message |
| data | MIXED | Structured context (destination path, etc.) |
| _handled | BOOL | Whether an `on()` handler has matched |

The `code` and `message` properties are inherited from `\Exception`
and accessible via `getCode()` and `getMessage()`.

---

### Error Codes

| Code | Type | Description |
|------|------|-------------|
| 1000 | DIRECTORY_CREATE_FAILED | Destination directory cannot be created |
| 1001 | DIRECTORY_NOT_WRITABLE | Destination directory is not writable |
| 2000 | MOVE_FAILED | `move_uploaded_file()` failed |
| 3000 | INVALID_CONFIG | Invalid configuration value |

---

## Construction

### CONSTRUCTOR :: STRING|INT, ?STRING, MIXED -> ctgUploaderError

Creates a new error. Accepts either a type name or integer code.
Throws `\InvalidArgumentException` if the type or code is unknown.

```php
$e = new CTGUploaderError('MOVE_FAILED', 'Cannot move file', [
    'destination' => '/var/www/uploads/file.jpg',
]);
```

---

## Instance Methods

### ctgUploaderError.on :: STRING|INT, (ctgUploaderError -> VOID) -> $this

Handles the error if it matches the given type. Chainable.
Short-circuits after the first match.

```php
try {
    $result = $uploader->handle($_FILES['file']);
} catch (CTGUploaderError $e) {
    $e->on('DIRECTORY_NOT_WRITABLE', fn($e) => alertOps($e))
      ->on('MOVE_FAILED', fn($e) => logError($e))
      ->otherwise(fn($e) => throw $e);
}
```

### ctgUploaderError.otherwise :: (ctgUploaderError -> VOID) -> VOID

Handles the error if no previous `on()` call matched. Not chainable.

---

## Static Methods

### CTGUploaderError.lookup :: STRING|INT -> INT|STRING|NULL

Bidirectional lookup between type names and codes.

```php
CTGUploaderError::lookup('MOVE_FAILED');  // 2000
CTGUploaderError::lookup(2000);           // 'MOVE_FAILED'
```
