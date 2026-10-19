<?php

declare(strict_types=1);

const EXIT_INVALID_INPUT = 2;
const EXIT_THRESHOLD_FAILED = 1;

if ($argc !== 4) {
    fwrite(
        STDERR,
        "Usage: php scripts/coverage-gate.php <coverage.txt> "
        . "<classes-min> <lines-min>\n",
    );

    exit(EXIT_INVALID_INPUT);
}

[, $reportPath, $classesMinimum, $linesMinimum] = $argv;

if (!is_file($reportPath) || !is_readable($reportPath)) {
    fwrite(STDERR, "Coverage report is not readable: {$reportPath}\n");

    exit(EXIT_INVALID_INPUT);
}

if (!is_numeric($classesMinimum) || !is_numeric($linesMinimum)) {
    fwrite(STDERR, "Coverage thresholds must be numeric.\n");

    exit(EXIT_INVALID_INPUT);
}

$classesThreshold = (float) $classesMinimum;
$linesThreshold = (float) $linesMinimum;

foreach ([$classesThreshold, $linesThreshold] as $threshold) {
    if ($threshold < 0 || $threshold > 100) {
        fwrite(STDERR, "Coverage thresholds must be between 0 and 100.\n");

        exit(EXIT_INVALID_INPUT);
    }
}

$report = file_get_contents($reportPath);

if ($report === false) {
    fwrite(STDERR, "Unable to read coverage report: {$reportPath}\n");

    exit(EXIT_INVALID_INPUT);
}

try {
    $classes = coverageMetric($report, 'Classes');
    $lines = coverageMetric($report, 'Lines');
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");

    exit(EXIT_INVALID_INPUT);
}

printf(
    "Coverage gate: Classes %.2f%% (minimum %.2f%%), "
    . "Lines %.2f%% (minimum %.2f%%).\n",
    $classes,
    $classesThreshold,
    $lines,
    $linesThreshold,
);

if ($classes < $classesThreshold || $lines < $linesThreshold) {
    fwrite(STDERR, "Coverage gate failed.\n");

    exit(EXIT_THRESHOLD_FAILED);
}

fwrite(STDOUT, "Coverage gate passed.\n");

/**
 * @throws RuntimeException
 */
function coverageMetric(string $report, string $metric): float
{
    $pattern = '/^\s*' . preg_quote($metric, '/')
        . ':\s+([0-9]+(?:\.[0-9]+)?)%/mi';

    if (preg_match($pattern, $report, $matches) !== 1) {
        throw new RuntimeException(
            "Metric {$metric} was not found in the coverage report.",
        );
    }

    return (float) $matches[1];
}
