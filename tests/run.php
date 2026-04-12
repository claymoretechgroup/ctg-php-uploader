<?php
declare(strict_types=1);

// Basic runner for ctg-php-test.
//
// Copy this file to tests/run.php in your project. It discovers test
// files in the same directory (glob pattern below), invokes each
// pipeline's start(), renders the result state with the reference
// text formatter, aggregates pass/fail counts, and sets an exit code.
//
// Test files must `return` an array of CTGTest instances (or a single
// CTGTest). Each pipeline runs independently; the runner does not
// share state across pipelines.
//
// Error handling: any uncaught exception raised while loading a test
// file, iterating its pipelines, or starting a pipeline is reported
// and counted as "aborted" (distinct from in-pipeline ERROR results),
// but does not abort the rest of the run. The runner's job is to get
// through every test file regardless of individual failures.
//
// The distinction matters: `errored` in the summary counts only true
// RESULT errors — evaluations inside a pipeline that couldn't complete.
// `aborted` counts runner-level failures where the pipeline didn't
// even get to the evaluation stage (bad require, wrong return shape,
// framework error escaping start()). Both cause a non-zero exit.
//
// Not production-safe: everything executes in one process with direct
// start() calls. A hung pipeline will hang the entire run. Mitigate
// by wrapping the command with a process-level timeout:
//     timeout 300 php tests/run.php
//
// Run with: php tests/run.php

require_once __DIR__ . '/../vendor/autoload.php';

use CTG\Test\CTGTest;
use CTG\Test\CTGTestStatus;
use CTG\Test\Formatters\CTGTestTextFormatter;

// Result-level counters (individual PASS/FAIL/ERROR/skipped entries).
$passed  = 0;
$failed  = 0;
$errored = 0;
$skipped = 0;

// Pipeline-level counters (a pipeline "passes" if every non-skipped
// result it produced is PASS; otherwise it "fails").
$pipelinesPassed = 0;
$pipelinesFailed = 0;

// File-level counters (a file "passes" if it loaded successfully and
// every pipeline in it passed; otherwise it "fails").
$filesPassed = 0;
$filesFailed = 0;

// Files or pipelines that aborted outside start()'s catch boundary.
$aborted = 0;

// Sort the glob so output order is deterministic across environments.
$files = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    $fileHadFailure = false;

    // Load the test file. A bad require (parse error, missing use,
    // autoload failure) is reported and the file is skipped, but the
    // run continues.
    try {
        $pipelines = require $file;
    } catch (\Throwable $e) {
        $aborted++;
        $filesFailed++;
        fwrite(STDERR, sprintf(
            "ABORTED: failed to load test file %s\n  %s: %s\n\n",
            basename($file),
            get_class($e),
            $e->getMessage()
        ));
        continue;
    }

    // Normalize return shape: a CTGTest, an iterable of CTGTest, or
    // anything else is an error. An error here means the test file's
    // return contract is wrong; we report it and move on.
    if ($pipelines instanceof CTGTest) {
        $pipelines = [$pipelines];
    } elseif (!is_iterable($pipelines)) {
        $aborted++;
        $filesFailed++;
        fwrite(STDERR, sprintf(
            "ABORTED: test file %s returned %s; expected CTGTest or iterable of CTGTest\n\n",
            basename($file),
            get_debug_type($pipelines)
        ));
        continue;
    }

    // Iterate the pipelines. A lazy iterable or generator may throw
    // during iteration itself (not just at require time); this outer
    // try/catch handles that. The inner per-pipeline try/catch handles
    // exceptions escaping start().
    try {
        foreach ($pipelines as $pipeline) {
            if (!$pipeline instanceof CTGTest) {
                $aborted++;
                $fileHadFailure = true;
                fwrite(STDERR, sprintf(
                    "ABORTED: test file %s yielded a %s; expected CTGTest\n\n",
                    basename($file),
                    get_debug_type($pipeline)
                ));
                continue;
            }

            // start() catches user-handler exceptions and reports them
            // as ERROR results. An exception escaping start() means a
            // framework validation error (malformed pipeline, bad
            // config) or an unexpected failure. We catch it here so one
            // bad pipeline does not abort the whole run.
            try {
                $state = $pipeline->start(null, ['haltOnFailure' => false, 'timeout' => 30000]);
            } catch (\Throwable $e) {
                $aborted++;
                $pipelinesFailed++;
                $fileHadFailure = true;
                fwrite(STDERR, sprintf(
                    "ABORTED: pipeline '%s' in %s threw outside start()\n  %s: %s\n\n",
                    $pipeline->getLabel(),
                    basename($file),
                    get_class($e),
                    $e->getMessage()
                ));
                continue;
            }

            echo CTGTestTextFormatter::format($state), "\n";

            $pipelineHadFailure = false;
            foreach ($state->getResults() as $result) {
                if ($result->_skipped) {
                    $skipped++;
                    continue;
                }
                match ($result->_status) {
                    CTGTestStatus::PASS  => $passed++,
                    CTGTestStatus::FAIL  => $failed++,
                    CTGTestStatus::ERROR => $errored++,
                    // Defensive: a well-formed result always has one of
                    // the three statuses when $_skipped is false. A
                    // default arm keeps the runner from crashing if an
                    // extension subclass produces an unexpected status
                    // value.
                    default              => $errored++,
                };
                if ($result->_status !== CTGTestStatus::PASS) {
                    $pipelineHadFailure = true;
                }
            }

            if ($pipelineHadFailure) {
                $pipelinesFailed++;
                $fileHadFailure = true;
            } else {
                $pipelinesPassed++;
            }
        }
    } catch (\Throwable $e) {
        $aborted++;
        $fileHadFailure = true;
        fwrite(STDERR, sprintf(
            "ABORTED: iteration of test file %s threw\n  %s: %s\n\n",
            basename($file),
            get_class($e),
            $e->getMessage()
        ));
    }

    if ($fileHadFailure) {
        $filesFailed++;
    } else {
        $filesPassed++;
    }
}

$totalFiles     = $filesPassed + $filesFailed;
$totalPipelines = $pipelinesPassed + $pipelinesFailed;

echo "\n";
echo "Files:     {$filesPassed}/{$totalFiles} passed";
if ($filesFailed > 0) {
    echo " ({$filesFailed} failed)";
}
echo "\n";

echo "Pipelines: {$pipelinesPassed}/{$totalPipelines} passed";
if ($pipelinesFailed > 0) {
    echo " ({$pipelinesFailed} failed)";
}
echo "\n";

echo "Results:   {$passed} passed, {$failed} failed, {$errored} errored, {$skipped} skipped\n";

if ($aborted > 0) {
    echo "Aborted:   {$aborted} (runner-level — load failure, bad return shape, or exception escaping start())\n";
}

exit(($failed === 0 && $errored === 0 && $aborted === 0) ? 0 : 1);
