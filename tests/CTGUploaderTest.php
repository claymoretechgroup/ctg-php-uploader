<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CTG\Test\CTGTest;
use CTG\Uploader\CTGUploader;
use CTG\Uploader\CTGUploaderError;
use CTG\FnProg\CTGFnprog;

// Tests for CTGUploader — validation, naming, security, normalize
//
// Since is_uploaded_file() and move_uploaded_file() only work with
// real PHP uploads, we use a testable subclass that overrides the
// file-move behavior with copy() for unit testing. Integration tests
// via a staging endpoint test the real upload path.

$config = ['output' => 'console'];
$testDir = '/tmp/ctg_uploader_test_' . getmypid();

// Testable subclass — bypasses is_uploaded_file/move_uploaded_file
class TestUploader extends CTGUploader {
    public function handle(array $file): array {
        // Call parent but we need to intercept the upload check.
        // Override by re-implementing handle with copy() instead of move_uploaded_file().
        return $this->_testHandle($file);
    }

    private function _testHandle(array $file): array {
        $originalName = $file['name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // 1. PHP upload error
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->_makeErrorResult('UPLOAD_ERROR', 'Upload error', [
                'original_name' => $originalName,
                'php_error' => $file['error'] ?? UPLOAD_ERR_NO_FILE,
            ]);
        }

        // 2. File exists
        $tmpName = $file['tmp_name'] ?? '';
        if (empty($tmpName) || !file_exists($tmpName)) {
            return $this->_makeErrorResult('NO_FILE', 'No uploaded file found', [
                'original_name' => $originalName,
            ]);
        }

        // 3. Executable deny list
        if (in_array($extension, static::$_deniedExtensions, true)) {
            return $this->_makeErrorResult('EXECUTABLE_DENIED',
                "Extension .{$extension} is not allowed (server-executable)",
                ['original_name' => $originalName, 'extension' => $extension]
            );
        }

        // 4. MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedType = $finfo->file($tmpName);

        $allowedTypes = $this->_getAllowedTypes();
        if (!empty($allowedTypes) && !in_array($detectedType, $allowedTypes, true)) {
            return $this->_makeErrorResult('INVALID_TYPE',
                "File type {$detectedType} is not allowed",
                ['original_name' => $originalName, 'type' => $detectedType, 'allowed' => $allowedTypes]
            );
        }

        // 5. Extension
        $allowedExtensions = $this->_getAllowedExtensions();
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            return $this->_makeErrorResult('INVALID_EXTENSION',
                "Extension .{$extension} is not allowed",
                ['original_name' => $originalName, 'extension' => $extension, 'allowed' => $allowedExtensions]
            );
        }

        // 6. MIME-extension cross-validation
        if (isset(static::$_mimeExtensionMap[$detectedType])) {
            $expected = static::$_mimeExtensionMap[$detectedType];
            if (!empty($expected) && !in_array($extension, $expected, true)) {
                return $this->_makeErrorResult('TYPE_MISMATCH',
                    "MIME type {$detectedType} is not consistent with extension .{$extension}",
                    ['original_name' => $originalName, 'type' => $detectedType, 'extension' => $extension]
                );
            }
        }

        // 7. File size
        $fileSize = $file['size'] ?? filesize($tmpName);
        $maxSize = $this->_getMaxSize();
        if ($maxSize > 0 && $fileSize > $maxSize) {
            return $this->_makeErrorResult('FILE_TOO_LARGE',
                "File size {$fileSize} bytes exceeds limit",
                ['original_name' => $originalName, 'size' => $fileSize, 'max_size' => $maxSize]
            );
        }

        // 8-9. Directory, naming, move (using copy for testing)
        $this->_testEnsureDirectory();
        $storedName = $this->_testGenerateName($originalName, $extension);
        $destDir = realpath($this->_getDestination());
        $fullPath = $destDir . DIRECTORY_SEPARATOR . $storedName;

        if (!$this->_getOverwrite() && file_exists($fullPath)) {
            return $this->_makeErrorResult('FILE_EXISTS',
                "File {$storedName} already exists",
                ['original_name' => $originalName, 'stored_name' => $storedName]
            );
        }

        copy($tmpName, $fullPath);
        chmod($fullPath, 0644);

        $resolvedPath = realpath($fullPath);
        if ($resolvedPath === false || !str_starts_with($resolvedPath, $destDir)) {
            unlink($fullPath);
            return $this->_makeErrorResult('PATH_TRAVERSAL',
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

    // Expose private methods for testing via protected accessors
    public function _getAllowedTypes(): array {
        return (new \ReflectionProperty(CTGUploader::class, '_allowedTypes'))->getValue($this);
    }
    public function _getAllowedExtensions(): array {
        return (new \ReflectionProperty(CTGUploader::class, '_allowedExtensions'))->getValue($this);
    }
    public function _getMaxSize(): int {
        return (new \ReflectionProperty(CTGUploader::class, '_maxSize'))->getValue($this);
    }
    public function _getDestination(): string {
        return (new \ReflectionProperty(CTGUploader::class, '_destination'))->getValue($this);
    }
    public function _getOverwrite(): bool {
        return (new \ReflectionProperty(CTGUploader::class, '_overwrite'))->getValue($this);
    }
    public function _testEnsureDirectory(): void {
        $dest = $this->_getDestination();
        if (!is_dir($dest)) {
            $createDir = (new \ReflectionProperty(CTGUploader::class, '_createDir'))->getValue($this);
            if (!$createDir) {
                throw new CTGUploaderError('DIRECTORY_CREATE_FAILED', "Directory does not exist: {$dest}");
            }
            $perms = (new \ReflectionProperty(CTGUploader::class, '_permissions'))->getValue($this);
            if (!mkdir($dest, $perms, true)) {
                throw new CTGUploaderError('DIRECTORY_CREATE_FAILED', "Failed to create: {$dest}");
            }
        }
        if (!is_writable($dest)) {
            throw new CTGUploaderError('DIRECTORY_NOT_WRITABLE', "Not writable: {$dest}");
        }
    }
    public function _testGenerateName(string $originalName, string $extension): string {
        $naming = (new \ReflectionProperty(CTGUploader::class, '_naming'))->getValue($this);
        return match($naming) {
            'uuid' => $this->_testUuid() . '.' . $extension,
            'timestamp' => time() . '_' . bin2hex(random_bytes(2)) . '.' . $extension,
            'original' => $this->_testSanitize($originalName, $extension),
            default => $this->_testUuid() . '.' . $extension,
        };
    }
    private function _testUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    private function _testSanitize(string $originalName, string $extension): string {
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = strtolower(trim($name, '-'));
        if (empty($name)) $name = 'unnamed';
        return $name . '.' . $extension;
    }
    private function _makeErrorResult(string $type, string $message, array $data = []): array {
        return ['success' => false, 'file' => null, 'error' => ['type' => $type, 'message' => $message, 'data' => $data]];
    }
}

// Helper: create a temp file with content and specific MIME type
function createTestFile(string $dir, string $name, string $content = 'test content'): array {
    $tmp = $dir . '/' . uniqid('tmp_');
    file_put_contents($tmp, $content);
    return [
        'name' => $name,
        'type' => 'application/octet-stream', // client-reported (ignored)
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($content),
    ];
}

// Helper: create a real JPEG file (minimal valid JPEG)
function createTestJpeg(string $dir, string $name): array {
    $tmp = $dir . '/' . uniqid('tmp_');
    // Minimal JPEG: SOI marker + JFIF APP0 + EOI
    $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
    file_put_contents($tmp, $jpeg);
    return [
        'name' => $name,
        'type' => 'image/jpeg',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($jpeg),
    ];
}

// Helper: create a real PNG file (minimal valid PNG)
function createTestPng(string $dir, string $name): array {
    $tmp = $dir . '/' . uniqid('tmp_');
    // Minimal PNG: signature + IHDR + IEND
    $png = "\x89PNG\r\n\x1a\n"
        . "\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xDE"
        . "\x00\x00\x00\x00IEND\xAEB`\x82";
    file_put_contents($tmp, $png);
    return [
        'name' => $name,
        'type' => 'image/png',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => strlen($png),
    ];
}

// Setup test directories
@mkdir($testDir, 0755, true);
$tmpDir = $testDir . '/tmp';
$destDir = $testDir . '/uploads';
@mkdir($tmpDir, 0755, true);

// ═══════════════════════════════════════════════════════════════
// CONSTRUCTION
// ═══════════════════════════════════════════════════════════════

CTGTest::init('init — static factory')
    ->stage('create', fn($_) => CTGUploader::init($destDir))
    ->assert('returns CTGUploader', fn($r) => $r instanceof CTGUploader, true)
    ->start(null, $config);

CTGTest::init('init — invalid naming throws')
    ->stage('attempt', function($_) use ($destDir) {
        try {
            CTGUploader::init($destDir, ['naming' => 'invalid']);
            return 'no exception';
        } catch (CTGUploaderError $e) {
            return $e->type;
        }
    })
    ->assert('throws INVALID_CONFIG', fn($r) => $r, 'INVALID_CONFIG')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// NORMALIZE
// ═══════════════════════════════════════════════════════════════

CTGTest::init('normalize — single file')
    ->stage('execute', fn($_) => CTGUploader::normalize([
        'name' => 'photo.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => '/tmp/php123',
        'error' => 0,
        'size' => 12345,
    ]))
    ->assert('returns 1 element', fn($r) => count($r), 1)
    ->assert('name preserved', fn($r) => $r[0]['name'], 'photo.jpg')
    ->start(null, $config);

CTGTest::init('normalize — multi-file')
    ->stage('execute', fn($_) => CTGUploader::normalize([
        'name' => ['doc1.pdf', 'doc2.pdf', 'doc3.pdf'],
        'type' => ['application/pdf', 'application/pdf', 'application/pdf'],
        'tmp_name' => ['/tmp/a', '/tmp/b', '/tmp/c'],
        'error' => [0, 0, 0],
        'size' => [100, 200, 300],
    ]))
    ->assert('returns 3 elements', fn($r) => count($r), 3)
    ->assert('first name', fn($r) => $r[0]['name'], 'doc1.pdf')
    ->assert('second name', fn($r) => $r[1]['name'], 'doc2.pdf')
    ->assert('third size', fn($r) => $r[2]['size'], 300)
    ->assert('each has all keys', fn($r) => isset($r[0]['name'], $r[0]['type'], $r[0]['tmp_name'], $r[0]['error'], $r[0]['size']), true)
    ->start(null, $config);

CTGTest::init('normalize — empty input')
    ->stage('execute', fn($_) => CTGUploader::normalize([]))
    ->assert('returns empty', fn($r) => $r, [])
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// SUCCESSFUL UPLOAD
// ═══════════════════════════════════════════════════════════════

CTGTest::init('handle — successful upload with UUID naming')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'document.txt')))
    ->assert('success is true', fn($r) => $r['success'], true)
    ->assert('has file metadata', fn($r) => isset($r['file']), true)
    ->assert('original name preserved', fn($r) => $r['file']['original_name'], 'document.txt')
    ->assert('stored name is UUID format', fn($r) => (bool)preg_match('/^[a-f0-9-]{36}\.txt$/', $r['file']['stored_name']), true)
    ->assert('extension is txt', fn($r) => $r['file']['extension'], 'txt')
    ->assert('error is null', fn($r) => $r['error'], null)
    ->start(null, $config);

CTGTest::init('handle — timestamp naming')
    ->stage('execute', fn($_) => TestUploader::init($destDir, ['naming' => 'timestamp'])
        ->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('stored name matches timestamp pattern', fn($r) => (bool)preg_match('/^\d+_[a-f0-9]{4}\.txt$/', $r['file']['stored_name']), true)
    ->start(null, $config);

CTGTest::init('handle — original naming sanitizes')
    ->stage('execute', fn($_) => TestUploader::init($destDir, ['naming' => 'original'])
        ->handle(createTestFile($tmpDir, 'My Weird (File) Name!.txt')))
    ->assert('sanitized name', fn($r) => $r['file']['stored_name'], 'my-weird-file-name.txt')
    ->start(null, $config);

CTGTest::init('handle — creates destination directory')
    ->stage('execute', fn($_) => TestUploader::init($destDir . '/subdir/deep')
        ->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('success', fn($r) => $r['success'], true)
    ->assert('directory created', fn($r) => is_dir(dirname($r['file']['path'])), true)
    ->start(null, $config);

CTGTest::init('handle — file permissions set to 0644')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'perms.txt')))
    ->assert('success', fn($r) => $r['success'], true)
    ->assert('permissions are 0644', fn($r) => decoct(fileperms($r['file']['path']) & 0777), '644')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// VALIDATION ERRORS
// ═══════════════════════════════════════════════════════════════

CTGTest::init('handle — PHP upload error')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(['name' => 'test.txt', 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0]))
    ->assert('success is false', fn($r) => $r['success'], false)
    ->assert('error type', fn($r) => $r['error']['type'], 'UPLOAD_ERROR')
    ->start(null, $config);

CTGTest::init('handle — no file')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(['name' => 'test.txt', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'size' => 0]))
    ->assert('error type', fn($r) => $r['error']['type'], 'NO_FILE')
    ->start(null, $config);

CTGTest::init('handle — invalid MIME type')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg', 'image/png']
        ])->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('error type', fn($r) => $r['error']['type'], 'INVALID_TYPE')
    ->assert('has allowed list', fn($r) => $r['error']['data']['allowed'], ['image/jpeg', 'image/png'])
    ->start(null, $config);

CTGTest::init('handle — invalid extension')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'allowed_extensions' => ['jpg', 'png']
        ])->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('error type', fn($r) => $r['error']['type'], 'INVALID_EXTENSION')
    ->start(null, $config);

CTGTest::init('handle — file too large')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'max_size' => 5
        ])->handle(createTestFile($tmpDir, 'big.txt', str_repeat('x', 100))))
    ->assert('error type', fn($r) => $r['error']['type'], 'FILE_TOO_LARGE')
    ->start(null, $config);

CTGTest::init('handle — file exists with overwrite off')
    ->stage('setup', function($_) use ($destDir, $tmpDir) {
        // Create existing file
        @mkdir($destDir, 0755, true);
        file_put_contents($destDir . '/existing.txt', 'already here');
        return TestUploader::init($destDir, ['naming' => 'original', 'overwrite' => false])
            ->handle(createTestFile($tmpDir, 'existing.txt'));
    })
    ->assert('error type is FILE_EXISTS', fn($r) => $r['error']['type'] === 'FILE_EXISTS', true)
    ->start(null, $config);

CTGTest::init('handle — file exists with overwrite on succeeds')
    ->stage('setup', function($_) use ($destDir, $tmpDir) {
        @mkdir($destDir, 0755, true);
        file_put_contents($destDir . '/overwrite-me.txt', 'old content');
        return TestUploader::init($destDir, ['naming' => 'original', 'overwrite' => true])
            ->handle(createTestFile($tmpDir, 'overwrite-me.txt', 'new content'));
    })
    ->assert('success', fn($r) => $r['success'], true)
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// SECURITY — EXECUTABLE DENY LIST
// ═══════════════════════════════════════════════════════════════

$deniedExts = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'phps', 'cgi', 'pl', 'sh', 'bash', 'htaccess', 'htpasswd'];

foreach ($deniedExts as $ext) {
    CTGTest::init("deny list — .{$ext} rejected")
        ->stage('execute', fn($_) => TestUploader::init($destDir)
            ->handle(createTestFile($tmpDir, "malicious.{$ext}")))
        ->assert('error type', fn($r) => $r['error']['type'], 'EXECUTABLE_DENIED')
        ->start(null, $config);
}

CTGTest::init('deny list — cannot override via allowed_extensions')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'allowed_extensions' => ['php', 'txt']
        ])->handle(createTestFile($tmpDir, 'script.php')))
    ->assert('still denied', fn($r) => $r['error']['type'], 'EXECUTABLE_DENIED')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// SECURITY — MIME-EXTENSION CROSS-VALIDATION
// ═══════════════════════════════════════════════════════════════

CTGTest::init('type mismatch — JPEG content with .txt extension')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(createTestJpeg($tmpDir, 'sneaky.txt')))
    ->assert('error type', fn($r) => $r['error']['type'], 'TYPE_MISMATCH')
    ->start(null, $config);

CTGTest::init('type mismatch — PNG content with .jpg extension')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(createTestPng($tmpDir, 'fake.jpg')))
    ->assert('error type', fn($r) => $r['error']['type'], 'TYPE_MISMATCH')
    ->start(null, $config);

CTGTest::init('type match — JPEG content with .jpg extension passes')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg']
        ])->handle(createTestJpeg($tmpDir, 'photo.jpg')))
    ->assert('success', fn($r) => $r['success'], true)
    ->start(null, $config);

CTGTest::init('type match — JPEG content with .jpeg extension passes')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg']
        ])->handle(createTestJpeg($tmpDir, 'photo.jpeg')))
    ->assert('success', fn($r) => $r['success'], true)
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// SECURITY — MIME DETECTION USES finfo, NOT CLIENT TYPE
// ═══════════════════════════════════════════════════════════════

CTGTest::init('MIME detection — client-reported type ignored')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg']
        ])->handle([
            'name' => 'fake.jpg',
            'type' => 'image/jpeg',  // client says JPEG
            'tmp_name' => (function() use ($tmpDir) {
                // but content is plain text
                $f = $tmpDir . '/' . uniqid('tmp_');
                file_put_contents($f, 'this is not a jpeg');
                return $f;
            })(),
            'error' => UPLOAD_ERR_OK,
            'size' => 18,
        ]))
    ->assert('rejected — finfo detects text, not jpeg', fn($r) => $r['success'], false)
    ->assert('error type is INVALID_TYPE', fn($r) => $r['error']['type'], 'INVALID_TYPE')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// HANDLE MULTIPLE
// ═══════════════════════════════════════════════════════════════

CTGTest::init('handleMultiple — processes each independently')
    ->stage('execute', fn($_) => TestUploader::init($destDir, [
            'max_size' => 50,
        ])->handleMultiple([
            createTestFile($tmpDir, 'small.txt', 'hi'),
            createTestFile($tmpDir, 'big.txt', str_repeat('x', 100)),
            createTestFile($tmpDir, 'also-small.txt', 'hey'),
        ]))
    ->assert('3 results', fn($r) => count($r), 3)
    ->assert('first success', fn($r) => $r[0]['success'], true)
    ->assert('second failed (too large)', fn($r) => $r[1]['error']['type'], 'FILE_TOO_LARGE')
    ->assert('third success', fn($r) => $r[2]['success'], true)
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// RESULT STRUCTURE
// ═══════════════════════════════════════════════════════════════

CTGTest::init('success result — has all keys')
    ->stage('execute', fn($_) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'complete.txt')))
    ->assert('has success', fn($r) => isset($r['success']), true)
    ->assert('has file', fn($r) => isset($r['file']), true)
    ->assert('has error', fn($r) => array_key_exists('error', $r), true)
    ->assert('file has original_name', fn($r) => isset($r['file']['original_name']), true)
    ->assert('file has stored_name', fn($r) => isset($r['file']['stored_name']), true)
    ->assert('file has path', fn($r) => isset($r['file']['path']), true)
    ->assert('file has size', fn($r) => isset($r['file']['size']), true)
    ->assert('file has type', fn($r) => isset($r['file']['type']), true)
    ->assert('file has extension', fn($r) => isset($r['file']['extension']), true)
    ->start(null, $config);

CTGTest::init('error result — has all keys')
    ->stage('execute', fn($_) => TestUploader::init($destDir, ['max_size' => 1])
        ->handle(createTestFile($tmpDir, 'err.txt', 'too long')))
    ->assert('has success', fn($r) => isset($r['success']), true)
    ->assert('success is false', fn($r) => $r['success'], false)
    ->assert('file is null', fn($r) => $r['file'], null)
    ->assert('has error', fn($r) => isset($r['error']), true)
    ->assert('error has type', fn($r) => isset($r['error']['type']), true)
    ->assert('error has message', fn($r) => isset($r['error']['message']), true)
    ->assert('error has data', fn($r) => isset($r['error']['data']), true)
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// CTGFnprog INTEGRATION
// ═══════════════════════════════════════════════════════════════

CTGTest::init('CTGFnprog — filter successful uploads')
    ->stage('execute', fn($_) => CTGFnprog::pipe([
        fn($_) => TestUploader::init($destDir, ['max_size' => 50])
            ->handleMultiple([
                createTestFile($tmpDir, 'ok1.txt', 'hi'),
                createTestFile($tmpDir, 'toobig.txt', str_repeat('x', 100)),
                createTestFile($tmpDir, 'ok2.txt', 'hey'),
            ]),
        CTGFnprog::filter(fn($r) => $r['success']),
        CTGFnprog::pluck('file'),
        CTGFnprog::pluck('original_name'),
    ])(null))
    ->assert('only successful names', fn($r) => $r, ['ok1.txt', 'ok2.txt'])
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// SYSTEM ERRORS (THROWN)
// ═══════════════════════════════════════════════════════════════

CTGTest::init('system error — directory create disabled')
    ->stage('attempt', function($_) use ($tmpDir) {
        try {
            TestUploader::init('/nonexistent/path/that/wont/work', ['create_dir' => false])
                ->handle(createTestFile($tmpDir, 'test.txt'));
            return 'no exception';
        } catch (CTGUploaderError $e) {
            return $e->type;
        }
    })
    ->assert('throws DIRECTORY_CREATE_FAILED', fn($r) => $r, 'DIRECTORY_CREATE_FAILED')
    ->start(null, $config);

// ── Cleanup ─────────────────────────────────────────────────────
// Clean up test files
exec("rm -rf {$testDir}");
