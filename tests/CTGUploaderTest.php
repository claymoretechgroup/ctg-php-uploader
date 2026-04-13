<?php
declare(strict_types=1);


use CTG\Test\CTGTest;
use CTG\Test\CTGTestState;
use CTG\Test\Predicates\CTGTestPredicates;
use CTG\Uploader\CTGUploader;
use CTG\Uploader\CTGUploaderError;

$pipelines = [];

// Tests for CTGUploader — validation, naming, security, normalize
//
// Since is_uploaded_file() and move_uploaded_file() only work with
// real PHP uploads, we use a testable subclass that overrides the
// file-move behavior with copy() for unit testing. Integration tests
// via a staging endpoint test the real upload path.

$testDir = '/tmp/ctg_uploader_test_' . getmypid();

// Testable subclass — overrides only the file system operations
// All validation logic runs in the parent's handle() method
class TestUploader extends CTGUploader {
    protected function _validateFile(string $tmpName): bool {
        return file_exists($tmpName);
    }

    protected function _moveFile(string $tmpName, string $fullPath): bool {
        return copy($tmpName, $fullPath);
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

$pipelines[] = CTGTest::init('init — static factory')
    ->stage('create', fn(CTGTestState $state) => CTGUploader::init($destDir))
    ->assert('returns CTGUploader', fn(CTGTestState $state) => $state->getSubject() instanceof CTGUploader, CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('init — invalid naming throws')
    ->stage('attempt', function(CTGTestState $state) use ($destDir){
        try {
            CTGUploader::init($destDir, ['naming' => 'invalid']);
            return 'no exception';
        } catch (CTGUploaderError $e) {
            return $e->type;
        }
    })
    ->assert('throws INVALID_CONFIG', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('INVALID_CONFIG'))
    ;

// ═══════════════════════════════════════════════════════════════
// NORMALIZE
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('normalize — single file')
    ->stage('execute', fn(CTGTestState $state) => CTGUploader::normalize([
        'name' => 'photo.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => '/tmp/php123',
        'error' => 0,
        'size' => 12345,
    ]))
    ->assert('returns 1 element', fn(CTGTestState $state) => count($state->getSubject()), CTGTestPredicates::equals(1))
    ->assert('name preserved', fn(CTGTestState $state) => $state->getSubject()[0]['name'], CTGTestPredicates::equals('photo.jpg'))
    ;

$pipelines[] = CTGTest::init('normalize — multi-file')
    ->stage('execute', fn(CTGTestState $state) => CTGUploader::normalize([
        'name' => ['doc1.pdf', 'doc2.pdf', 'doc3.pdf'],
        'type' => ['application/pdf', 'application/pdf', 'application/pdf'],
        'tmp_name' => ['/tmp/a', '/tmp/b', '/tmp/c'],
        'error' => [0, 0, 0],
        'size' => [100, 200, 300],
    ]))
    ->assert('returns 3 elements', fn(CTGTestState $state) => count($state->getSubject()), CTGTestPredicates::equals(3))
    ->assert('first name', fn(CTGTestState $state) => $state->getSubject()[0]['name'], CTGTestPredicates::equals('doc1.pdf'))
    ->assert('second name', fn(CTGTestState $state) => $state->getSubject()[1]['name'], CTGTestPredicates::equals('doc2.pdf'))
    ->assert('third size', fn(CTGTestState $state) => $state->getSubject()[2]['size'], CTGTestPredicates::equals(300))
    ->assert('each has all keys', fn(CTGTestState $state) => isset($state->getSubject()[0]['name'], $state->getSubject()[0]['type'], $state->getSubject()[0]['tmp_name'], $state->getSubject()[0]['error'], $state->getSubject()[0]['size']), CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('normalize — empty input')
    ->stage('execute', fn(CTGTestState $state) => CTGUploader::normalize([]))
    ->assert('returns empty', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals([]))
    ;

// ═══════════════════════════════════════════════════════════════
// SUCCESSFUL UPLOAD
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('handle — successful upload with UUID naming')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'document.txt')))
    ->assert('success is true', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ->assert('has file metadata', fn(CTGTestState $state) => isset($state->getSubject()['file']), CTGTestPredicates::isTrue())
    ->assert('original name preserved', fn(CTGTestState $state) => $state->getSubject()['file']['original_name'], CTGTestPredicates::equals('document.txt'))
    ->assert('stored name is UUID format', fn(CTGTestState $state) => (bool)preg_match('/^[a-f0-9-]{36}\.txt$/', $state->getSubject()['file']['stored_name']), CTGTestPredicates::isTrue())
    ->assert('extension is txt', fn(CTGTestState $state) => $state->getSubject()['file']['extension'], CTGTestPredicates::equals('txt'))
    ->assert('error is null', fn(CTGTestState $state) => $state->getSubject()['error'], CTGTestPredicates::isNull())
    ;

$pipelines[] = CTGTest::init('handle — timestamp naming')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, ['naming' => 'timestamp'])
        ->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('stored name matches timestamp pattern', fn(CTGTestState $state) => (bool)preg_match('/^\d+_[a-f0-9]{4}\.txt$/', $state->getSubject()['file']['stored_name']), CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('handle — original naming sanitizes')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, ['naming' => 'original'])
        ->handle(createTestFile($tmpDir, 'My Weird (File) Name!.txt')))
    ->assert('sanitized name', fn(CTGTestState $state) => $state->getSubject()['file']['stored_name'], CTGTestPredicates::equals('my-weird-file-name.txt'))
    ;

$pipelines[] = CTGTest::init('handle — creates destination directory')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir . '/subdir/deep')
        ->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ->assert('directory created', fn(CTGTestState $state) => is_dir(dirname($state->getSubject()['file']['path'])), CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('handle — file permissions set to 0644')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'perms.txt')))
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ->assert('permissions are 0644', fn(CTGTestState $state) => decoct(fileperms($state->getSubject()['file']['path']) & 0777), CTGTestPredicates::equals('644'))
    ;

// ═══════════════════════════════════════════════════════════════
// VALIDATION ERRORS
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('handle — PHP upload error')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(['name' => 'test.txt', 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0]))
    ->assert('success is false', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('UPLOAD_ERROR'))
    ;

$pipelines[] = CTGTest::init('handle — no file')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(['name' => 'test.txt', 'error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'size' => 0]))
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('NO_FILE'))
    ;

$pipelines[] = CTGTest::init('handle — invalid MIME type')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg', 'image/png']
        ])->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('INVALID_TYPE'))
    ->assert('has allowed list', fn(CTGTestState $state) => $state->getSubject()['error']['data']['allowed'], CTGTestPredicates::equals(['image/jpeg', 'image/png']))
    ;

$pipelines[] = CTGTest::init('handle — invalid extension')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'allowed_extensions' => ['jpg', 'png']
        ])->handle(createTestFile($tmpDir, 'test.txt')))
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('INVALID_EXTENSION'))
    ;

$pipelines[] = CTGTest::init('handle — file too large')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'max_size' => 5
        ])->handle(createTestFile($tmpDir, 'big.txt', str_repeat('x', 100))))
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('FILE_TOO_LARGE'))
    ;

$pipelines[] = CTGTest::init('handle — file exists with overwrite off')
    ->stage('setup', function(CTGTestState $state) use ($destDir, $tmpDir){
        // Create existing file
        @mkdir($destDir, 0755, true);
        file_put_contents($destDir . '/existing.txt', 'already here');
        return TestUploader::init($destDir, ['naming' => 'original', 'overwrite' => false])
            ->handle(createTestFile($tmpDir, 'existing.txt'));
    })
    ->assert('error type is FILE_EXISTS', fn(CTGTestState $state) => $state->getSubject()['error']['type'] === 'FILE_EXISTS', CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('handle — file exists with overwrite on succeeds')
    ->stage('setup', function(CTGTestState $state) use ($destDir, $tmpDir){
        @mkdir($destDir, 0755, true);
        file_put_contents($destDir . '/overwrite-me.txt', 'old content');
        return TestUploader::init($destDir, ['naming' => 'original', 'overwrite' => true])
            ->handle(createTestFile($tmpDir, 'overwrite-me.txt', 'new content'));
    })
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ;

// ═══════════════════════════════════════════════════════════════
// SECURITY — EXECUTABLE DENY LIST
// ═══════════════════════════════════════════════════════════════

$deniedExts = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'phps', 'cgi', 'pl', 'sh', 'bash', 'htaccess', 'htpasswd'];

foreach ($deniedExts as $ext) {
    $pipelines[] = CTGTest::init("deny list — .{$ext} rejected")
        ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
            ->handle(createTestFile($tmpDir, "malicious.{$ext}")))
        ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('EXECUTABLE_DENIED'))
        ;
}

$pipelines[] = CTGTest::init('deny list — cannot override via allowed_extensions')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'allowed_extensions' => ['php', 'txt']
        ])->handle(createTestFile($tmpDir, 'script.php')))
    ->assert('still denied', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('EXECUTABLE_DENIED'))
    ;

// ═══════════════════════════════════════════════════════════════
// SECURITY — MIME-EXTENSION CROSS-VALIDATION
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('type mismatch — JPEG content with .txt extension')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(createTestJpeg($tmpDir, 'sneaky.txt')))
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('TYPE_MISMATCH'))
    ;

$pipelines[] = CTGTest::init('type mismatch — PNG content with .jpg extension')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(createTestPng($tmpDir, 'fake.jpg')))
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('TYPE_MISMATCH'))
    ;

$pipelines[] = CTGTest::init('type match — JPEG content with .jpg extension passes')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg']
        ])->handle(createTestJpeg($tmpDir, 'photo.jpg')))
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('type match — JPEG content with .jpeg extension passes')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'allowed_types' => ['image/jpeg']
        ])->handle(createTestJpeg($tmpDir, 'photo.jpeg')))
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ;

// ═══════════════════════════════════════════════════════════════
// SECURITY — MIME DETECTION USES finfo, NOT CLIENT TYPE
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('MIME detection — client-reported type ignored')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
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
    ->assert('rejected — finfo detects text, not jpeg', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isFalse())
    ->assert('error type is INVALID_TYPE', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('INVALID_TYPE'))
    ;

// ═══════════════════════════════════════════════════════════════
// HANDLE MULTIPLE
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('handleMultiple — processes each independently')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, [
            'max_size' => 50,
        ])->handleMultiple([
            createTestFile($tmpDir, 'small.txt', 'hi'),
            createTestFile($tmpDir, 'big.txt', str_repeat('x', 100)),
            createTestFile($tmpDir, 'also-small.txt', 'hey'),
        ]))
    ->assert('3 results', fn(CTGTestState $state) => count($state->getSubject()), CTGTestPredicates::equals(3))
    ->assert('first success', fn(CTGTestState $state) => $state->getSubject()[0]['success'], CTGTestPredicates::isTrue())
    ->assert('second failed (too large)', fn(CTGTestState $state) => $state->getSubject()[1]['error']['type'], CTGTestPredicates::equals('FILE_TOO_LARGE'))
    ->assert('third success', fn(CTGTestState $state) => $state->getSubject()[2]['success'], CTGTestPredicates::isTrue())
    ;

// ═══════════════════════════════════════════════════════════════
// RESULT STRUCTURE
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('success result — has all keys')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'complete.txt')))
    ->assert('has success', fn(CTGTestState $state) => isset($state->getSubject()['success']), CTGTestPredicates::isTrue())
    ->assert('has file', fn(CTGTestState $state) => isset($state->getSubject()['file']), CTGTestPredicates::isTrue())
    ->assert('has error', fn(CTGTestState $state) => array_key_exists('error', $state->getSubject()), CTGTestPredicates::isTrue())
    ->assert('file has original_name', fn(CTGTestState $state) => isset($state->getSubject()['file']['original_name']), CTGTestPredicates::isTrue())
    ->assert('file has stored_name', fn(CTGTestState $state) => isset($state->getSubject()['file']['stored_name']), CTGTestPredicates::isTrue())
    ->assert('file has path', fn(CTGTestState $state) => isset($state->getSubject()['file']['path']), CTGTestPredicates::isTrue())
    ->assert('file has size', fn(CTGTestState $state) => isset($state->getSubject()['file']['size']), CTGTestPredicates::isTrue())
    ->assert('file has type', fn(CTGTestState $state) => isset($state->getSubject()['file']['type']), CTGTestPredicates::isTrue())
    ->assert('file has extension', fn(CTGTestState $state) => isset($state->getSubject()['file']['extension']), CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('error result — has all keys')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, ['max_size' => 1])
        ->handle(createTestFile($tmpDir, 'err.txt', 'too long')))
    ->assert('has success', fn(CTGTestState $state) => isset($state->getSubject()['success']), CTGTestPredicates::isTrue())
    ->assert('success is false', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isFalse())
    ->assert('file is null', fn(CTGTestState $state) => $state->getSubject()['file'], CTGTestPredicates::isNull())
    ->assert('has error', fn(CTGTestState $state) => isset($state->getSubject()['error']), CTGTestPredicates::isTrue())
    ->assert('error has type', fn(CTGTestState $state) => isset($state->getSubject()['error']['type']), CTGTestPredicates::isTrue())
    ->assert('error has message', fn(CTGTestState $state) => isset($state->getSubject()['error']['message']), CTGTestPredicates::isTrue())
    ->assert('error has data', fn(CTGTestState $state) => isset($state->getSubject()['error']['data']), CTGTestPredicates::isTrue())
    ;

// ═══════════════════════════════════════════════════════════════
// SYSTEM ERRORS (THROWN)
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('system error — directory create disabled')
    ->stage('attempt', function(CTGTestState $state) use ($tmpDir){
        try {
            TestUploader::init('/nonexistent/path/that/wont/work', ['create_dir' => false])
                ->handle(createTestFile($tmpDir, 'test.txt'));
            return 'no exception';
        } catch (CTGUploaderError $e) {
            return $e->type;
        }
    })
    ->assert('throws DIRECTORY_CREATE_FAILED', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('DIRECTORY_CREATE_FAILED'))
    ;

// ═══════════════════════════════════════════════════════════════
// CONFIG VALIDATION
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('config — unknown key throws INVALID_CONFIG')
    ->stage('attempt', function(CTGTestState $state) use ($destDir){
        try {
            CTGUploader::init($destDir, ['allowed_type' => ['image/jpeg']]);
            return 'no exception';
        } catch (CTGUploaderError $e) {
            return ['type' => $e->type, 'unknown' => $e->data['unknown']];
        }
    })
    ->assert('throws INVALID_CONFIG', fn(CTGTestState $state) => $state->getSubject()['type'], CTGTestPredicates::equals('INVALID_CONFIG'))
    ->assert('identifies bad key', fn(CTGTestState $state) => $state->getSubject()['unknown'], CTGTestPredicates::equals(['allowed_type']))
    ;

$pipelines[] = CTGTest::init('config — multiple unknown keys reported')
    ->stage('attempt', function(CTGTestState $state) use ($destDir){
        try {
            CTGUploader::init($destDir, ['mime_types' => [], 'max_file_size' => 100]);
            return 'no exception';
        } catch (CTGUploaderError $e) {
            return $e->data['unknown'];
        }
    })
    ->assert('both bad keys listed', fn(CTGTestState $state) => count($state->getSubject()), CTGTestPredicates::equals(2))
    ;

$pipelines[] = CTGTest::init('config — valid keys accepted')
    ->stage('create', fn(CTGTestState $state) => CTGUploader::init($destDir, [
        'allowed_types' => ['image/jpeg'],
        'allowed_extensions' => ['jpg'],
        'max_size' => 1024,
        'naming' => 'uuid',
        'overwrite' => false,
        'create_dir' => true,
        'permissions' => 0755,
    ]))
    ->assert('returns CTGUploader', fn(CTGTestState $state) => $state->getSubject() instanceof CTGUploader, CTGTestPredicates::isTrue())
    ;

// ═══════════════════════════════════════════════════════════════
// EXTENSIONLESS FILES
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('handle — extensionless file has no trailing dot')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
        ->handle(createTestFile($tmpDir, 'Makefile')))
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ->assert('no trailing dot', fn(CTGTestState $state) => !str_ends_with($state->getSubject()['file']['stored_name'], '.'), CTGTestPredicates::isTrue())
    ->assert('extension is empty', fn(CTGTestState $state) => $state->getSubject()['file']['extension'], CTGTestPredicates::equals(''))
    ;

$pipelines[] = CTGTest::init('handle — extensionless file with original naming')
    ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir, ['naming' => 'original'])
        ->handle(createTestFile($tmpDir, 'LICENSE')))
    ->assert('success', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isTrue())
    ->assert('stored as license', fn(CTGTestState $state) => $state->getSubject()['file']['stored_name'], CTGTestPredicates::equals('license'))
    ;

// ═══════════════════════════════════════════════════════════════
// PHP UPLOAD ERROR CODES
// ═══════════════════════════════════════════════════════════════

$uploadErrors = [
    ['code' => UPLOAD_ERR_INI_SIZE, 'label' => 'INI_SIZE'],
    ['code' => UPLOAD_ERR_FORM_SIZE, 'label' => 'FORM_SIZE'],
    ['code' => UPLOAD_ERR_PARTIAL, 'label' => 'PARTIAL'],
    ['code' => UPLOAD_ERR_NO_FILE, 'label' => 'NO_FILE'],
    ['code' => UPLOAD_ERR_NO_TMP_DIR, 'label' => 'NO_TMP_DIR'],
    ['code' => UPLOAD_ERR_CANT_WRITE, 'label' => 'CANT_WRITE'],
    ['code' => UPLOAD_ERR_EXTENSION, 'label' => 'EXTENSION'],
];

foreach ($uploadErrors as $err) {
    $pipelines[] = CTGTest::init("upload error — {$err['label']} returns UPLOAD_ERROR")
        ->stage('execute', fn(CTGTestState $state) => TestUploader::init($destDir)
            ->handle(['name' => 'test.txt', 'error' => $err['code'], 'tmp_name' => '', 'size' => 0]))
        ->assert('success is false', fn(CTGTestState $state) => $state->getSubject()['success'], CTGTestPredicates::isFalse())
        ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('UPLOAD_ERROR'))
        ->assert('php_error code', fn(CTGTestState $state) => $state->getSubject()['error']['data']['php_error'], CTGTestPredicates::equals($err['code']))
        ;
}

// ═══════════════════════════════════════════════════════════════
// FILE SIZE USES filesize() NOT CLIENT-REPORTED
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('handle — size check uses actual file size, not client-reported')
    ->stage('execute', function(CTGTestState $state) use ($destDir, $tmpDir){
        // Create a 100-byte file but report size as 1 byte
        $file = createTestFile($tmpDir, 'sneaky.txt', str_repeat('x', 100));
        $file['size'] = 1; // lie about size
        return TestUploader::init($destDir, ['max_size' => 50])
            ->handle($file);
    })
    ->assert('rejected based on actual size', fn(CTGTestState $state) => $state->getSubject()['error']['type'], CTGTestPredicates::equals('FILE_TOO_LARGE'))
    ;

// ── Cleanup ─────────────────────────────────────────────────────
// Deferred to shutdown — in v2.2 the file returns pipelines for the
// runner to execute later, so the test directory must survive load time.
register_shutdown_function(fn() => exec("rm -rf {$testDir}"));

return $pipelines;
