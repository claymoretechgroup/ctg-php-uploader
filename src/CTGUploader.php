<?php
declare(strict_types=1);

namespace CTG\Uploader;

// Server-side file upload handler with validation and safe naming
class CTGUploader {

    /* Static Properties */

    // Server-executable extensions — always rejected regardless of config
    protected static array $_deniedExtensions = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'phps',
        'cgi', 'pl',
        'sh', 'bash',
        'htaccess', 'htpasswd',
    ];

    // MIME type to expected extensions mapping for cross-validation
    protected static array $_mimeExtensionMap = [
        'image/jpeg' => ['jpg', 'jpeg', 'jpe'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
        'image/svg+xml' => ['svg', 'svgz'],
        'image/bmp' => ['bmp'],
        'image/tiff' => ['tif', 'tiff'],
        'image/x-icon' => ['ico'],
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt', 'csv', 'log', 'md'],
        'text/csv' => ['csv'],
        'text/html' => ['html', 'htm'],
        'text/css' => ['css'],
        'text/xml' => ['xml'],
        'application/json' => ['json'],
        'application/xml' => ['xml'],
        'application/zip' => ['zip'],
        'application/gzip' => ['gz', 'gzip'],
        'application/x-tar' => ['tar'],
        'application/x-7z-compressed' => ['7z'],
        'application/x-rar-compressed' => ['rar'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'audio/mpeg' => ['mp3'],
        'audio/wav' => ['wav'],
        'audio/ogg' => ['ogg'],
        'video/mp4' => ['mp4'],
        'video/webm' => ['webm'],
        'video/ogg' => ['ogv'],
        'application/javascript' => ['js'],
        'application/octet-stream' => [], // generic binary — skip cross-check
    ];

    /* Instance Properties */
    private string $_destination;
    private array $_allowedTypes;
    private array $_allowedExtensions;
    private int $_maxSize;
    private string $_naming;
    private bool $_overwrite;
    private bool $_createDir;
    private int $_permissions;

    // CONSTRUCTOR :: STRING, ARRAY -> $this
    // Creates an uploader with a destination directory and config
    public function __construct(string $destination, array $config = []) {
        $this->_destination = rtrim($destination, '/');
        $this->_allowedTypes = $config['allowed_types'] ?? [];
        $this->_allowedExtensions = array_map('strtolower', $config['allowed_extensions'] ?? []);
        $this->_maxSize = $config['max_size'] ?? 0;
        $this->_naming = $config['naming'] ?? 'uuid';
        $this->_overwrite = $config['overwrite'] ?? false;
        $this->_createDir = $config['create_dir'] ?? true;
        $this->_permissions = $config['permissions'] ?? 0755;

        $validNaming = ['uuid', 'timestamp', 'original'];
        if (!in_array($this->_naming, $validNaming, true)) {
            throw new CTGUploaderError('INVALID_CONFIG',
                "Invalid naming strategy: {$this->_naming}. Allowed: " . implode(', ', $validNaming),
                ['naming' => $this->_naming, 'allowed' => $validNaming]
            );
        }
    }

    /**
     *
     * Instance Methods
     *
     */

    // :: ARRAY -> ARRAY
    // Handle a single file upload from $_FILES
    public function handle(array $file): array {
        $originalName = $file['name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 1. PHP upload error
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->_errorResult('UPLOAD_ERROR', $this->_mapUploadError($file['error'] ?? UPLOAD_ERR_NO_FILE), [
                'original_name' => $originalName,
                'php_error' => $file['error'] ?? UPLOAD_ERR_NO_FILE,
            ]);
        }

        // 2. File exists check
        $tmpName = $file['tmp_name'] ?? '';
        if (empty($tmpName) || !is_uploaded_file($tmpName)) {
            return $this->_errorResult('NO_FILE', 'No uploaded file found', [
                'original_name' => $originalName,
            ]);
        }

        // 3. Executable deny list
        if (in_array($extension, static::$_deniedExtensions, true)) {
            return $this->_errorResult('EXECUTABLE_DENIED',
                "Extension .{$extension} is not allowed (server-executable)",
                ['original_name' => $originalName, 'extension' => $extension]
            );
        }

        // 4. MIME type validation (server-detected, not client-reported)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedType = $finfo->file($tmpName);

        if (!empty($this->_allowedTypes) && !in_array($detectedType, $this->_allowedTypes, true)) {
            return $this->_errorResult('INVALID_TYPE',
                "File type {$detectedType} is not allowed",
                ['original_name' => $originalName, 'type' => $detectedType, 'allowed' => $this->_allowedTypes]
            );
        }

        // 5. Extension validation
        if (!empty($this->_allowedExtensions) && !in_array($extension, $this->_allowedExtensions, true)) {
            return $this->_errorResult('INVALID_EXTENSION',
                "Extension .{$extension} is not allowed",
                ['original_name' => $originalName, 'extension' => $extension, 'allowed' => $this->_allowedExtensions]
            );
        }

        // 6. MIME-extension cross-validation
        if (isset(static::$_mimeExtensionMap[$detectedType])) {
            $expectedExtensions = static::$_mimeExtensionMap[$detectedType];
            if (!empty($expectedExtensions) && !in_array($extension, $expectedExtensions, true)) {
                return $this->_errorResult('TYPE_MISMATCH',
                    "MIME type {$detectedType} is not consistent with extension .{$extension}",
                    ['original_name' => $originalName, 'type' => $detectedType, 'extension' => $extension,
                     'expected_extensions' => $expectedExtensions]
                );
            }
        }

        // 7. File size
        $fileSize = $file['size'] ?? filesize($tmpName);
        if ($this->_maxSize > 0 && $fileSize > $this->_maxSize) {
            return $this->_errorResult('FILE_TOO_LARGE',
                "File size {$fileSize} bytes exceeds limit of {$this->_maxSize} bytes",
                ['original_name' => $originalName, 'size' => $fileSize, 'max_size' => $this->_maxSize]
            );
        }

        // 8. Destination directory
        $this->_ensureDirectory();

        // 9. Generate stored name and check traversal
        $storedName = $this->_generateName($originalName, $extension);
        $destDir = realpath($this->_destination);
        $fullPath = $destDir . DIRECTORY_SEPARATOR . $storedName;

        // Check file exists (for 'original' naming)
        if (!$this->_overwrite && file_exists($fullPath)) {
            return $this->_errorResult('FILE_EXISTS',
                "File {$storedName} already exists",
                ['original_name' => $originalName, 'stored_name' => $storedName]
            );
        }

        // Move the file
        if (!move_uploaded_file($tmpName, $fullPath)) {
            throw new CTGUploaderError('MOVE_FAILED',
                "Failed to move uploaded file to {$fullPath}",
                ['original_name' => $originalName, 'destination' => $fullPath]
            );
        }

        // Set non-executable permissions
        chmod($fullPath, 0644);

        // Directory traversal check — verify file landed where expected
        $resolvedPath = realpath($fullPath);
        if ($resolvedPath === false || !str_starts_with($resolvedPath, $destDir)) {
            unlink($fullPath);
            return $this->_errorResult('PATH_TRAVERSAL',
                'Resolved path escapes destination directory',
                ['original_name' => $originalName, 'stored_name' => $storedName]
            );
        }

        return [
            'success' => true,
            'file' => [
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'path' => $resolvedPath,
                'size' => $fileSize,
                'type' => $detectedType,
                'extension' => $extension,
            ],
            'error' => null,
        ];
    }

    // :: [ARRAY] -> [ARRAY]
    // Handle multiple file uploads from $_FILES
    public function handleMultiple(array $files): array {
        $results = [];
        foreach ($files as $file) {
            $results[] = $this->handle($file);
        }
        return $results;
    }

    /**
     *
     * Static Methods
     *
     */

    // Static Factory Method :: STRING, ARRAY -> ctgUploader
    // Creates and returns a new CTGUploader instance
    public static function init(string $destination, array $config = []): static {
        return new static($destination, $config);
    }

    // :: ARRAY -> [ARRAY]
    // Normalize a $_FILES entry for multi-file inputs into individual file arrays
    public static function normalize(array $files): array {
        // Single file — values are scalars
        if (isset($files['name']) && !is_array($files['name'])) {
            return [$files];
        }

        // Multi-file — restructure
        if (isset($files['name']) && is_array($files['name'])) {
            $normalized = [];
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $normalized[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
            }
            return $normalized;
        }

        return [];
    }

    /**
     *
     * Private Methods
     *
     */

    // :: STRING, STRING -> STRING
    // Generate stored filename based on naming strategy
    private function _generateName(string $originalName, string $extension): string {
        return match($this->_naming) {
            'uuid' => $this->_generateUuid() . '.' . $extension,
            'timestamp' => time() . '_' . bin2hex(random_bytes(2)) . '.' . $extension,
            'original' => $this->_sanitizeFilename($originalName, $extension),
            default => $this->_generateUuid() . '.' . $extension,
        };
    }

    // :: VOID -> STRING
    // Generate UUID v4 using random_bytes
    private function _generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // :: STRING, STRING -> STRING
    // Sanitize original filename for safe storage
    private function _sanitizeFilename(string $originalName, string $extension): string {
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = strtolower(trim($name, '-'));
        if (empty($name)) {
            $name = 'unnamed';
        }
        return $name . '.' . $extension;
    }

    // :: VOID -> VOID
    // Ensure destination directory exists and is writable
    private function _ensureDirectory(): void {
        if (!is_dir($this->_destination)) {
            if (!$this->_createDir) {
                throw new CTGUploaderError('DIRECTORY_CREATE_FAILED',
                    "Destination directory does not exist: {$this->_destination}",
                    ['destination' => $this->_destination]
                );
            }
            if (!mkdir($this->_destination, $this->_permissions, true)) {
                throw new CTGUploaderError('DIRECTORY_CREATE_FAILED',
                    "Failed to create directory: {$this->_destination}",
                    ['destination' => $this->_destination]
                );
            }
        }

        if (!is_writable($this->_destination)) {
            throw new CTGUploaderError('DIRECTORY_NOT_WRITABLE',
                "Destination directory is not writable: {$this->_destination}",
                ['destination' => $this->_destination]
            );
        }
    }

    // :: STRING, STRING, ARRAY -> ARRAY
    // Build an error result structure
    private function _errorResult(string $type, string $message, array $data = []): array {
        return [
            'success' => false,
            'file' => null,
            'error' => [
                'type' => $type,
                'message' => $message,
                'data' => $data,
            ],
        ];
    }

    // :: INT -> STRING
    // Map PHP upload error code to human message
    private function _mapUploadError(int $code): string {
        return match($code) {
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by PHP extension',
            default               => 'Unknown upload error',
        };
    }
}
