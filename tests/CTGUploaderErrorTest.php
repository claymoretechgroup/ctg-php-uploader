<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CTG\Test\CTGTest;
use CTG\Uploader\CTGUploaderError;

// Tests for CTGUploaderError — construction, lookup, chainable handler

$config = ['output' => 'console'];

// ── Construction ────────────────────────────────────────────────

CTGTest::init('construct with type name')
    ->stage('create', fn($_) => new CTGUploaderError('MOVE_FAILED', 'cannot move', ['path' => '/tmp']))
    ->assert('code is 2000', fn($e) => $e->getCode(), 2000)
    ->assert('type is MOVE_FAILED', fn($e) => $e->type, 'MOVE_FAILED')
    ->assert('msg is set', fn($e) => $e->msg, 'cannot move')
    ->assert('data is set', fn($e) => $e->data, ['path' => '/tmp'])
    ->start(null, $config);

CTGTest::init('construct with integer code')
    ->stage('create', fn($_) => new CTGUploaderError(1000, 'dir failed'))
    ->assert('type is DIRECTORY_CREATE_FAILED', fn($e) => $e->type, 'DIRECTORY_CREATE_FAILED')
    ->assert('code is 1000', fn($e) => $e->getCode(), 1000)
    ->start(null, $config);

CTGTest::init('construct with unknown type throws')
    ->stage('attempt', function($_) {
        try {
            new CTGUploaderError('NONEXISTENT');
            return 'no exception';
        } catch (\InvalidArgumentException $e) {
            return 'threw';
        }
    })
    ->assert('throws', fn($r) => $r, 'threw')
    ->start(null, $config);

// ── Lookup ──────────────────────────────────────────────────────

CTGTest::init('lookup — all codes')
    ->stage('execute', fn($_) => [
        CTGUploaderError::lookup('DIRECTORY_CREATE_FAILED'),
        CTGUploaderError::lookup('DIRECTORY_NOT_WRITABLE'),
        CTGUploaderError::lookup('MOVE_FAILED'),
        CTGUploaderError::lookup('INVALID_CONFIG'),
    ])
    ->assert('DIRECTORY_CREATE_FAILED', fn($r) => $r[0], 1000)
    ->assert('DIRECTORY_NOT_WRITABLE', fn($r) => $r[1], 1001)
    ->assert('MOVE_FAILED', fn($r) => $r[2], 2000)
    ->assert('INVALID_CONFIG', fn($r) => $r[3], 3000)
    ->start(null, $config);

CTGTest::init('lookup — code to name')
    ->stage('execute', fn($_) => CTGUploaderError::lookup(2000))
    ->assert('returns MOVE_FAILED', fn($r) => $r, 'MOVE_FAILED')
    ->start(null, $config);

// ── Chainable on()/otherwise() ──────────────────────────────────

CTGTest::init('on — matches and short circuits')
    ->stage('execute', function($_) {
        $e = new CTGUploaderError('MOVE_FAILED', 'fail');
        $calls = [];
        $e->on('DIRECTORY_NOT_WRITABLE', function($err) use (&$calls) { $calls[] = 'dir'; })
          ->on('MOVE_FAILED', function($err) use (&$calls) { $calls[] = 'move'; })
          ->on('MOVE_FAILED', function($err) use (&$calls) { $calls[] = 'move2'; });
        return $calls;
    })
    ->assert('only move matched', fn($r) => $r, ['move'])
    ->start(null, $config);

CTGTest::init('otherwise — called when no match')
    ->stage('execute', function($_) {
        $e = new CTGUploaderError('INVALID_CONFIG', 'bad');
        $matched = null;
        $e->on('MOVE_FAILED', function($err) use (&$matched) { $matched = 'move'; })
          ->otherwise(function($err) use (&$matched) { $matched = 'otherwise'; });
        return $matched;
    })
    ->assert('otherwise called', fn($r) => $r, 'otherwise')
    ->start(null, $config);

// ── Edge cases ──────────────────────────────────────────────────

CTGTest::init('lookup — unknown name returns null')
    ->stage('execute', fn($_) => CTGUploaderError::lookup('NONEXISTENT'))
    ->assert('returns null', fn($r) => $r, null)
    ->start(null, $config);

CTGTest::init('lookup — unknown code returns null')
    ->stage('execute', fn($_) => CTGUploaderError::lookup(9999))
    ->assert('returns null', fn($r) => $r, null)
    ->start(null, $config);

CTGTest::init('construct with unknown integer code throws')
    ->stage('attempt', function($_) {
        try {
            new CTGUploaderError(9999);
            return 'no exception';
        } catch (\InvalidArgumentException $e) {
            return 'threw';
        }
    })
    ->assert('throws', fn($r) => $r, 'threw')
    ->start(null, $config);

CTGTest::init('on — matches by integer code')
    ->stage('execute', function($_) {
        $e = new CTGUploaderError('MOVE_FAILED', 'fail');
        $matched = null;
        $e->on(2000, function($err) use (&$matched) { $matched = $err->type; });
        return $matched;
    })
    ->assert('handler called', fn($r) => $r, 'MOVE_FAILED')
    ->start(null, $config);
