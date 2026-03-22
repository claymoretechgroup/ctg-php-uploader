<?php
// Test endpoint — receives a real HTTP upload and processes it through CTGUploader
require_once __DIR__ . '/../../vendor/autoload.php';

use CTG\Uploader\CTGUploader;
use CTG\Uploader\CTGUploaderError;

header('Content-Type: application/json');

// Config from query params so tests can vary behavior per request
$config = [];
if (isset($_GET['allowed_types'])) {
    $config['allowed_types'] = explode(',', $_GET['allowed_types']);
}
if (isset($_GET['allowed_extensions'])) {
    $config['allowed_extensions'] = explode(',', $_GET['allowed_extensions']);
}
if (isset($_GET['max_size'])) {
    $config['max_size'] = (int)$_GET['max_size'];
}
if (isset($_GET['naming'])) {
    $config['naming'] = $_GET['naming'];
}

$destDir = '/tmp/ctg_upload_integration_test';

try {
    $uploader = CTGUploader::init($destDir, $config);

    if (empty($_FILES)) {
        http_response_code(400);
        echo json_encode(['error' => 'No files uploaded']);
        exit;
    }

    // Get the first file field
    $fieldName = array_key_first($_FILES);
    $files = CTGUploader::normalize($_FILES[$fieldName]);

    if (count($files) === 1) {
        $result = $uploader->handle($files[0]);
    } else {
        $result = $uploader->handleMultiple($files);
    }

    echo json_encode($result);

} catch (CTGUploaderError $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->type,
        'message' => $e->msg,
    ]);
}
