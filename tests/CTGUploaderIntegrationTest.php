<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CTG\Test\CTGTest;
use CTG\ApiClient\CTGAPIClient;

// Integration tests — real HTTP uploads through staging Apache
// Uses CTGAPIClient to upload files to the test endpoint, which
// processes them through the real CTGUploader with move_uploaded_file()

$config = ['output' => 'console'];
$baseUrl = 'http://localhost';
$endpoint = '/tests/endpoints/upload.php';

// Helper: create a temp file with specific content
function makeTempFile(string $content, string $extension): string {
    $tmp = tempnam('/tmp', 'ctg_int_') . '.' . $extension;
    file_put_contents($tmp, $content);
    return $tmp;
}

// Helper: create a minimal valid JPEG
function makeTempJpeg(): string {
    $tmp = tempnam('/tmp', 'ctg_int_') . '.jpg';
    $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
    file_put_contents($tmp, $jpeg);
    return $tmp;
}

// Helper: create a minimal valid PNG
function makeTempPng(): string {
    $tmp = tempnam('/tmp', 'ctg_int_') . '.png';
    $png = "\x89PNG\r\n\x1a\n"
        . "\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90wS\xDE"
        . "\x00\x00\x00\x00IEND\xAEB`\x82";
    file_put_contents($tmp, $png);
    return $tmp;
}

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — SUCCESS
// ═══════════════════════════════════════════════════════════════

CTGTest::init('integration — text file upload succeeds')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile('hello world', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('HTTP 200', fn($r) => $r['status'], 200)
    ->assert('upload succeeded', fn($r) => $r['body']['success'], true)
    ->assert('has file metadata', fn($r) => isset($r['body']['file']), true)
    ->assert('has stored_name', fn($r) => isset($r['body']['file']['stored_name']), true)
    ->assert('has path', fn($r) => isset($r['body']['file']['path']), true)
    ->assert('extension is txt', fn($r) => $r['body']['file']['extension'], 'txt')
    ->assert('original name preserved', fn($r) => str_contains($r['body']['file']['original_name'], '.txt'), true)
    ->assert('error is null', fn($r) => $r['body']['error'], null)
    ->start(null, $config);

CTGTest::init('integration — JPEG upload succeeds')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempJpeg();
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_types=image/jpeg', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload succeeded', fn($r) => $r['body']['success'], true)
    ->assert('type is image/jpeg', fn($r) => $r['body']['file']['type'], 'image/jpeg')
    ->assert('extension is jpg', fn($r) => $r['body']['file']['extension'], 'jpg')
    ->start(null, $config);

CTGTest::init('integration — UUID naming produces valid UUID')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile('uuid test', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?naming=uuid', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('stored name is UUID', fn($r) => (bool)preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.txt$/',
        $r['body']['file']['stored_name']
    ), true)
    ->start(null, $config);

CTGTest::init('integration — timestamp naming')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile('timestamp test', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?naming=timestamp', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('stored name matches pattern', fn($r) => (bool)preg_match(
        '/^\d+_[a-f0-9]{4}\.txt$/',
        $r['body']['file']['stored_name']
    ), true)
    ->start(null, $config);

CTGTest::init('integration — original naming sanitizes')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = tempnam('/tmp', 'ctg_int_');
        // Rename to have spaces and special chars
        $named = dirname($tmp) . '/My Weird (File).txt';
        rename($tmp, $named);
        file_put_contents($named, 'content');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?naming=original', $named);
        @unlink($named);
        return $result;
    })
    ->assert('upload succeeded', fn($r) => $r['body']['success'], true)
    ->assert('name sanitized', fn($r) => $r['body']['file']['stored_name'], 'my-weird-file.txt')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — VALIDATION FAILURES
// ═══════════════════════════════════════════════════════════════

CTGTest::init('integration — wrong MIME type rejected')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile('not an image', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_types=image/jpeg,image/png', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'INVALID_TYPE')
    ->start(null, $config);

CTGTest::init('integration — wrong extension rejected')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile('text content', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_extensions=jpg,png', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'INVALID_EXTENSION')
    ->start(null, $config);

CTGTest::init('integration — file too large rejected')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile(str_repeat('x', 1000), 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?max_size=100', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'FILE_TOO_LARGE')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — SECURITY
// ═══════════════════════════════════════════════════════════════

CTGTest::init('integration — .php extension denied')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = tempnam('/tmp', 'ctg_int_');
        $phpFile = $tmp . '.php';
        rename($tmp, $phpFile);
        file_put_contents($phpFile, '<?php echo "pwned"; ?>');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $phpFile);
        @unlink($phpFile);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'EXECUTABLE_DENIED')
    ->start(null, $config);

CTGTest::init('integration — .phtml extension denied')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = tempnam('/tmp', 'ctg_int_');
        $phtmlFile = $tmp . '.phtml';
        rename($tmp, $phtmlFile);
        file_put_contents($phtmlFile, '<?php echo "pwned"; ?>');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $phtmlFile);
        @unlink($phtmlFile);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'EXECUTABLE_DENIED')
    ->start(null, $config);

CTGTest::init('integration — .htaccess denied')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = tempnam('/tmp', 'ctg_int_');
        $htFile = $tmp . '.htaccess';
        rename($tmp, $htFile);
        file_put_contents($htFile, 'AddType application/x-httpd-php .jpg');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $htFile);
        @unlink($htFile);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'EXECUTABLE_DENIED')
    ->start(null, $config);

CTGTest::init('integration — MIME-extension mismatch rejected')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        // Create a real JPEG but name it .txt
        $tmp = tempnam('/tmp', 'ctg_int_');
        $misnamed = $tmp . '.txt';
        rename($tmp, $misnamed);
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
        file_put_contents($misnamed, $jpeg);
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $misnamed);
        @unlink($misnamed);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('error type', fn($r) => $r['body']['error']['type'], 'TYPE_MISMATCH')
    ->start(null, $config);

CTGTest::init('integration — spoofed client MIME type ignored')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        // Upload a text file but only allow image/jpeg
        // Client might claim it's a JPEG but finfo will detect text
        $tmp = makeTempFile('I am not a JPEG', 'jpg');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_types=image/jpeg', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn($r) => $r['body']['success'], false)
    ->assert('rejected by server MIME detection', fn($r) => $r['body']['error']['type'], 'INVALID_TYPE')
    ->start(null, $config);

CTGTest::init('integration — stored file is not executable')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $tmp = makeTempFile('check permissions', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload succeeded', fn($r) => $r['body']['success'], true)
    ->assert('file permissions are 0644', fn($r) =>
        decoct(fileperms($r['body']['file']['path']) & 0777), '644')
    ->start(null, $config);

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — move_uploaded_file() ACTUALLY WORKS
// ═══════════════════════════════════════════════════════════════

CTGTest::init('integration — file content survives upload')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        $content = 'The quick brown fox jumps over the lazy dog. ' . uniqid();
        $tmp = makeTempFile($content, 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $tmp);
        unlink($tmp);
        return ['result' => $result, 'expected_content' => $content];
    })
    ->assert('upload succeeded', fn($r) => $r['result']['body']['success'], true)
    ->assert('content preserved', fn($r) =>
        file_get_contents($r['result']['body']['file']['path']) === $r['expected_content'], true)
    ->start(null, $config);

// Cleanup integration test uploads
exec('rm -rf /tmp/ctg_upload_integration_test');
