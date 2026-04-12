<?php
declare(strict_types=1);


use CTG\Test\CTGTest;
use CTG\Test\CTGTestState;
use CTG\Test\Predicates\CTGTestPredicates;
use CTG\Uploader\CTGUploaderError;

$pipelines = [];

// Tests for CTGUploaderError — construction, lookup, chainable handler


// ── Construction ────────────────────────────────────────────────

$pipelines[] = CTGTest::init('construct with type name')
    ->stage('create', fn(CTGTestState $state) => new CTGUploaderError('MOVE_FAILED', 'cannot move', ['path' => '/tmp']))
    ->assert('code is 2000', fn(CTGTestState $state) => $state->getSubject()->getCode(), CTGTestPredicates::equals(2000))
    ->assert('type is MOVE_FAILED', fn(CTGTestState $state) => $state->getSubject()->type, CTGTestPredicates::equals('MOVE_FAILED'))
    ->assert('msg is set', fn(CTGTestState $state) => $state->getSubject()->msg, CTGTestPredicates::equals('cannot move'))
    ->assert('data is set', fn(CTGTestState $state) => $state->getSubject()->data, CTGTestPredicates::equals(['path' => '/tmp']))
    ;

$pipelines[] = CTGTest::init('construct with integer code')
    ->stage('create', fn(CTGTestState $state) => new CTGUploaderError(1000, 'dir failed'))
    ->assert('type is DIRECTORY_CREATE_FAILED', fn(CTGTestState $state) => $state->getSubject()->type, CTGTestPredicates::equals('DIRECTORY_CREATE_FAILED'))
    ->assert('code is 1000', fn(CTGTestState $state) => $state->getSubject()->getCode(), CTGTestPredicates::equals(1000))
    ;

$pipelines[] = CTGTest::init('construct with unknown type throws')
    ->stage('attempt', function(CTGTestState $state) {
        try {
            new CTGUploaderError('NONEXISTENT');
            return 'no exception';
        } catch (\InvalidArgumentException $e) {
            return 'threw';
        }
    })
    ->assert('throws', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('threw'))
    ;

// ── Lookup ──────────────────────────────────────────────────────

$pipelines[] = CTGTest::init('lookup — all codes')
    ->stage('execute', fn(CTGTestState $state) => [
        CTGUploaderError::lookup('DIRECTORY_CREATE_FAILED'),
        CTGUploaderError::lookup('DIRECTORY_NOT_WRITABLE'),
        CTGUploaderError::lookup('MOVE_FAILED'),
        CTGUploaderError::lookup('INVALID_CONFIG'),
    ])
    ->assert('DIRECTORY_CREATE_FAILED', fn(CTGTestState $state) => $state->getSubject()[0], CTGTestPredicates::equals(1000))
    ->assert('DIRECTORY_NOT_WRITABLE', fn(CTGTestState $state) => $state->getSubject()[1], CTGTestPredicates::equals(1001))
    ->assert('MOVE_FAILED', fn(CTGTestState $state) => $state->getSubject()[2], CTGTestPredicates::equals(2000))
    ->assert('INVALID_CONFIG', fn(CTGTestState $state) => $state->getSubject()[3], CTGTestPredicates::equals(3000))
    ;

$pipelines[] = CTGTest::init('lookup — code to name')
    ->stage('execute', fn(CTGTestState $state) => CTGUploaderError::lookup(2000))
    ->assert('returns MOVE_FAILED', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('MOVE_FAILED'))
    ;

// ── Chainable on()/otherwise() ──────────────────────────────────

$pipelines[] = CTGTest::init('on — matches and short circuits')
    ->stage('execute', function(CTGTestState $state) {
        $e = new CTGUploaderError('MOVE_FAILED', 'fail');
        $calls = [];
        $e->on('DIRECTORY_NOT_WRITABLE', function($err) use (&$calls) { $calls[] = 'dir'; })
          ->on('MOVE_FAILED', function($err) use (&$calls) { $calls[] = 'move'; })
          ->on('MOVE_FAILED', function($err) use (&$calls) { $calls[] = 'move2'; });
        return $calls;
    })
    ->assert('only move matched', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals(['move']))
    ;

$pipelines[] = CTGTest::init('otherwise — called when no match')
    ->stage('execute', function(CTGTestState $state) {
        $e = new CTGUploaderError('INVALID_CONFIG', 'bad');
        $matched = null;
        $e->on('MOVE_FAILED', function($err) use (&$matched) { $matched = 'move'; })
          ->otherwise(function($err) use (&$matched) { $matched = 'otherwise'; });
        return $matched;
    })
    ->assert('otherwise called', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('otherwise'))
    ;

// ── Edge cases ──────────────────────────────────────────────────

$pipelines[] = CTGTest::init('lookup — unknown name returns null')
    ->stage('execute', fn(CTGTestState $state) => CTGUploaderError::lookup('NONEXISTENT'))
    ->assert('returns null', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::isNull())
    ;

$pipelines[] = CTGTest::init('lookup — unknown code returns null')
    ->stage('execute', fn(CTGTestState $state) => CTGUploaderError::lookup(9999))
    ->assert('returns null', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::isNull())
    ;

$pipelines[] = CTGTest::init('construct with unknown integer code throws')
    ->stage('attempt', function(CTGTestState $state) {
        try {
            new CTGUploaderError(9999);
            return 'no exception';
        } catch (\InvalidArgumentException $e) {
            return 'threw';
        }
    })
    ->assert('throws', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('threw'))
    ;

$pipelines[] = CTGTest::init('on — matches by integer code')
    ->stage('execute', function(CTGTestState $state) {
        $e = new CTGUploaderError('MOVE_FAILED', 'fail');
        $matched = null;
        $e->on(2000, function($err) use (&$matched) { $matched = $err->type; });
        return $matched;
    })
    ->assert('handler called', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('MOVE_FAILED'))
    ;

$pipelines[] = CTGTest::init('on — unknown string type throws InvalidArgumentException')
    ->stage('execute', function(CTGTestState $state) {
        $e = new CTGUploaderError('MOVE_FAILED', 'fail');
        try {
            $e->on('NONEXISTENT_TYPE', fn($err) => null);
            return 'no exception';
        } catch (\InvalidArgumentException $ex) {
            return 'thrown';
        }
    })
    ->assert('throws', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('thrown'))
    ;

$pipelines[] = CTGTest::init('on — unknown integer code throws InvalidArgumentException')
    ->stage('execute', function(CTGTestState $state) {
        $e = new CTGUploaderError('MOVE_FAILED', 'fail');
        try {
            $e->on(99999, fn($err) => null);
            return 'no exception';
        } catch (\InvalidArgumentException $ex) {
            return 'thrown';
        }
    })
    ->assert('throws', fn(CTGTestState $state) => $state->getSubject(), CTGTestPredicates::equals('thrown'))
    ;

return $pipelines;
