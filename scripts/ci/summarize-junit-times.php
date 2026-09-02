<?php

declare(strict_types=1);

$options = getopt('', ['input:', 'output:', 'csv::', 'limit::']);

$input = parse_path_option($options, 'input');
$output = parse_path_option($options, 'output');
$csv = isset($options['csv']) && is_scalar($options['csv']) && $options['csv'] !== ''
    ? (string) $options['csv']
    : null;
$limit = parse_positive_int_option($options, 'limit', 25);

if (! class_exists(DOMDocument::class)) {
    fail('The DOM extension is required to summarize PHPUnit JUnit timing.');
}

$document = new DOMDocument();
$document->preserveWhiteSpace = false;

if (@$document->load($input) !== true) {
    fail("Unable to load JUnit XML from [{$input}].");
}

$rows = summarize_testcases($document);

usort(
    $rows,
    static fn (array $a, array $b): int => ($b['time'] <=> $a['time'])
        ?: strcmp($a['name'], $b['name'])
);

write_markdown_summary($output, $input, $rows, $limit);

if ($csv !== null) {
    write_csv_summary($csv, $rows);
}

/**
 * @param array<string, mixed> $options
 */
function parse_path_option(array $options, string $name): string
{
    if (! isset($options[$name]) || ! is_scalar($options[$name]) || $options[$name] === '') {
        fail("--{$name} is required.");
    }

    return (string) $options[$name];
}

/**
 * @param array<string, mixed> $options
 */
function parse_positive_int_option(array $options, string $name, int $default): int
{
    if (! isset($options[$name]) || $options[$name] === '') {
        return $default;
    }

    if (! is_scalar($options[$name]) || ! preg_match('/^[1-9]\d*$/', (string) $options[$name])) {
        fail("--{$name} must be a positive integer.");
    }

    return (int) $options[$name];
}

/**
 * @return list<array{name: string, tests: int, assertions: int, failures: int, errors: int, skipped: int, time: float}>
 */
function summarize_testcases(DOMDocument $document): array
{
    $groups = [];

    foreach ($document->getElementsByTagName('testcase') as $testcase) {
        if (! $testcase instanceof DOMElement) {
            continue;
        }

        $name = group_name($testcase);

        if (! isset($groups[$name])) {
            $groups[$name] = [
                'name' => $name,
                'tests' => 0,
                'assertions' => 0,
                'failures' => 0,
                'errors' => 0,
                'skipped' => 0,
                'time' => 0.0,
            ];
        }

        $groups[$name]['tests']++;
        $groups[$name]['assertions'] += int_attribute($testcase, 'assertions');
        $groups[$name]['failures'] += child_count($testcase, 'failure');
        $groups[$name]['errors'] += child_count($testcase, 'error');
        $groups[$name]['skipped'] += child_count($testcase, 'skipped');
        $groups[$name]['time'] += float_attribute($testcase, 'time');
    }

    return array_values($groups);
}

function group_name(DOMElement $testcase): string
{
    $file = $testcase->getAttribute('file');

    if ($file !== '') {
        return normalize_path($file);
    }

    $class = $testcase->getAttribute('class') ?: $testcase->getAttribute('classname');

    if ($class !== '') {
        return $class;
    }

    return $testcase->getAttribute('name') ?: 'unknown';
}

function normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function int_attribute(DOMElement $element, string $name): int
{
    $value = $element->getAttribute($name);

    if ($value === '' || ! preg_match('/^\d+$/', $value)) {
        return 0;
    }

    return (int) $value;
}

function float_attribute(DOMElement $element, string $name): float
{
    $value = $element->getAttribute($name);

    if ($value === '' || ! is_numeric($value)) {
        return 0.0;
    }

    return (float) $value;
}

function child_count(DOMElement $element, string $name): int
{
    $count = 0;

    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMElement && $child->tagName === $name) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param list<array<string, float|int|string>> $rows
 */
function write_markdown_summary(string $path, string $input, array $rows, int $limit): void
{
    $totalTests = array_sum(array_column($rows, 'tests'));
    $totalAssertions = array_sum(array_column($rows, 'assertions'));
    $totalFailures = array_sum(array_column($rows, 'failures'));
    $totalErrors = array_sum(array_column($rows, 'errors'));
    $totalSkipped = array_sum(array_column($rows, 'skipped'));
    $totalTime = array_sum(array_column($rows, 'time'));

    $lines = [
        '# PHPUnit Timing Summary',
        '',
        sprintf('- Source: `%s`', $input),
        sprintf('- Groups: %d', count($rows)),
        sprintf('- Tests: %d', $totalTests),
        sprintf('- Assertions: %d', $totalAssertions),
        sprintf('- Failures: %d', $totalFailures),
        sprintf('- Errors: %d', $totalErrors),
        sprintf('- Skipped: %d', $totalSkipped),
        sprintf('- Runtime: %.3fs', $totalTime),
        '',
        sprintf('## Slowest %d Groups', min($limit, count($rows))),
        '',
        '| Seconds | Tests | Assertions | Failures | Errors | Skipped | Group |',
        '| ---: | ---: | ---: | ---: | ---: | ---: | --- |',
    ];

    foreach (array_slice($rows, 0, $limit) as $row) {
        $lines[] = sprintf(
            '| %.3f | %d | %d | %d | %d | %d | `%s` |',
            $row['time'],
            $row['tests'],
            $row['assertions'],
            $row['failures'],
            $row['errors'],
            $row['skipped'],
            str_replace('|', '\\|', $row['name'])
        );
    }

    file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
}

/**
 * @param list<array<string, float|int|string>> $rows
 */
function write_csv_summary(string $path, array $rows): void
{
    $handle = fopen($path, 'wb');

    if ($handle === false) {
        fail("Unable to write CSV summary to [{$path}].");
    }

    fputcsv($handle, ['seconds', 'tests', 'assertions', 'failures', 'errors', 'skipped', 'group'], ',', '"', '');

    foreach ($rows as $row) {
        fputcsv(
            $handle,
            [
                sprintf('%.6f', $row['time']),
                $row['tests'],
                $row['assertions'],
                $row['failures'],
                $row['errors'],
                $row['skipped'],
                $row['name'],
            ],
            ',',
            '"',
            ''
        );
    }

    fclose($handle);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);

    exit(1);
}
