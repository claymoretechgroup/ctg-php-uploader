<?php
declare(strict_types=1);


use CTG\Test\CTGTest;
use CTG\Test\CTGTestState;
use CTG\Test\Predicates\CTGTestPredicates;
use CTG\ApiClient\CTGAPIClient;

$pipelines = [];

// Integration tests — real HTTP uploads through staging Apache
// Uses CTGAPIClient to upload files to the test endpoint, which
// processes them through the real CTGUploader with move_uploaded_file()

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

$pipelines[] = CTGTest::init('integration — text file upload succeeds')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile('hello world', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('HTTP 200', fn(CTGTestState $state) => $state->getSubject()['status'], CTGTestPredicates::equals(200))
    ->assert('upload succeeded', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isTrue())
    ->assert('has file metadata', fn(CTGTestState $state) => isset($state->getSubject()['body']['file']), CTGTestPredicates::isTrue())
    ->assert('has stored_name', fn(CTGTestState $state) => isset($state->getSubject()['body']['file']['stored_name']), CTGTestPredicates::isTrue())
    ->assert('has path', fn(CTGTestState $state) => isset($state->getSubject()['body']['file']['path']), CTGTestPredicates::isTrue())
    ->assert('extension is txt', fn(CTGTestState $state) => $state->getSubject()['body']['file']['extension'], CTGTestPredicates::equals('txt'))
    ->assert('original name preserved', fn(CTGTestState $state) => str_contains($state->getSubject()['body']['file']['original_name'], '.txt'), CTGTestPredicates::isTrue())
    ->assert('error is null', fn(CTGTestState $state) => $state->getSubject()['body']['error'], CTGTestPredicates::isNull())
    ;

$pipelines[] = CTGTest::init('integration — JPEG upload succeeds')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempJpeg();
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_types=image/jpeg', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload succeeded', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isTrue())
    ->assert('type is image/jpeg', fn(CTGTestState $state) => $state->getSubject()['body']['file']['type'], CTGTestPredicates::equals('image/jpeg'))
    ->assert('extension is jpg', fn(CTGTestState $state) => $state->getSubject()['body']['file']['extension'], CTGTestPredicates::equals('jpg'))
    ;

$pipelines[] = CTGTest::init('integration — UUID naming produces valid UUID')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile('uuid test', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?naming=uuid', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('stored name is UUID', fn(CTGTestState $state) => (bool)preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.txt$/',
        $state->getSubject()['body']['file']['stored_name']
    ), CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('integration — timestamp naming')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile('timestamp test', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?naming=timestamp', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('stored name matches pattern', fn(CTGTestState $state) => (bool)preg_match(
        '/^\d+_[a-f0-9]{4}\.txt$/',
        $state->getSubject()['body']['file']['stored_name']
    ), CTGTestPredicates::isTrue())
    ;

$pipelines[] = CTGTest::init('integration — original naming sanitizes')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
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
    ->assert('upload succeeded', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isTrue())
    ->assert('name sanitized', fn(CTGTestState $state) => $state->getSubject()['body']['file']['stored_name'], CTGTestPredicates::equals('my-weird-file.txt'))
    ;

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — VALIDATION FAILURES
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('integration — wrong MIME type rejected')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile('not an image', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_types=image/jpeg,image/png', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('INVALID_TYPE'))
    ;

$pipelines[] = CTGTest::init('integration — wrong extension rejected')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile('text content', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_extensions=jpg,png', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('INVALID_EXTENSION'))
    ;

$pipelines[] = CTGTest::init('integration — file too large rejected')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile(str_repeat('x', 1000), 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?max_size=100', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('FILE_TOO_LARGE'))
    ;

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — SECURITY
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('integration — .php extension denied')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = tempnam('/tmp', 'ctg_int_');
        $phpFile = $tmp . '.php';
        rename($tmp, $phpFile);
        file_put_contents($phpFile, '<?php echo "pwned"; ?>');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $phpFile);
        @unlink($phpFile);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('EXECUTABLE_DENIED'))
    ;

$pipelines[] = CTGTest::init('integration — .phtml extension denied')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = tempnam('/tmp', 'ctg_int_');
        $phtmlFile = $tmp . '.phtml';
        rename($tmp, $phtmlFile);
        file_put_contents($phtmlFile, '<?php echo "pwned"; ?>');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $phtmlFile);
        @unlink($phtmlFile);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('EXECUTABLE_DENIED'))
    ;

$pipelines[] = CTGTest::init('integration — .htaccess denied')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = tempnam('/tmp', 'ctg_int_');
        $htFile = $tmp . '.htaccess';
        rename($tmp, $htFile);
        file_put_contents($htFile, 'AddType application/x-httpd-php .jpg');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $htFile);
        @unlink($htFile);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('EXECUTABLE_DENIED'))
    ;

$pipelines[] = CTGTest::init('integration — MIME-extension mismatch rejected')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
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
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('error type', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('TYPE_MISMATCH'))
    ;

$pipelines[] = CTGTest::init('integration — spoofed client MIME type ignored')
    ->stage('upload', function($_) use ($baseUrl, $endpoint) {
        // Upload a text file but only allow image/jpeg
        // Client might claim it's a JPEG but finfo will detect text
        $tmp = makeTempFile('I am not a JPEG', 'jpg');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint . '?allowed_types=image/jpeg', $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload failed', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isFalse())
    ->assert('rejected by server MIME detection', fn(CTGTestState $state) => $state->getSubject()['body']['error']['type'], CTGTestPredicates::equals('INVALID_TYPE'))
    ;

$pipelines[] = CTGTest::init('integration — stored file is not executable')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $tmp = makeTempFile('check permissions', 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $tmp);
        unlink($tmp);
        return $result;
    })
    ->assert('upload succeeded', fn(CTGTestState $state) => $state->getSubject()['body']['success'], CTGTestPredicates::isTrue())
    ->assert('file permissions are 0644', fn(CTGTestState $state) => decoct(fileperms($state->getSubject()['body']['file']['path']) & 0777), CTGTestPredicates::equals('644'))
    ;

// ═══════════════════════════════════════════════════════════════
// REAL UPLOAD — move_uploaded_file() ACTUALLY WORKS
// ═══════════════════════════════════════════════════════════════

$pipelines[] = CTGTest::init('integration — file content survives upload')
    ->stage('upload', function(CTGTestState $state) use ($baseUrl, $endpoint){
        $content = 'The quick brown fox jumps over the lazy dog. ' . uniqid();
        $tmp = makeTempFile($content, 'txt');
        $result = CTGAPIClient::init($baseUrl)
            ->upload($endpoint, $tmp);
        unlink($tmp);
        return ['result' => $result, 'expected_content' => $content];
    })
    ->assert('upload succeeded', fn(CTGTestState $state) => $state->getSubject()['result']['body']['success'], CTGTestPredicates::isTrue())
    ->assert('content preserved', fn(CTGTestState $state) => file_get_contents($state->getSubject()['result']['body']['file']['path']) === $state->getSubject()['expected_content'], CTGTestPredicates::isTrue())
    ;

// Deferred to shutdown — pipelines execute after file load in v2.2.
register_shutdown_function(fn() => exec('rm -rf /tmp/ctg_upload_integration_test'));

return $pipelines;
