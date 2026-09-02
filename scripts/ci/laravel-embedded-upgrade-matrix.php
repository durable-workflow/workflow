#!/usr/bin/env php
<?php

declare(strict_types=1);

$scope = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--scope=')) {
        $scope = substr($argument, strlen('--scope='));
    }
}

if (! in_array($scope, ['source', 'published'], true)) {
    fwrite(STDERR, "Choose --scope=source or --scope=published.\n");
    exit(1);
}

$contractPath = dirname(__DIR__, 2) . '/resources/laravel-embedded-upgrade-contract.json';
$contract = json_decode((string) file_get_contents($contractPath), true, 512, JSON_THROW_ON_ERROR);
$intersection = $contract['supported_intersection'] ?? null;

if (! is_array($intersection) || ! is_array($intersection['cells'] ?? null)) {
    fwrite(STDERR, "The Laravel embedded upgrade contract has no supported cells.\n");
    exit(1);
}

$publishedCells = $intersection['cells'];
$selectedCells = $publishedCells;

if ($scope === 'source') {
    $authority = $intersection['authority'] ?? null;
    $minimums = is_array($authority) ? ($authority['laravel_minimum_php'] ?? null) : null;

    if (! is_array($minimums)) {
        fwrite(STDERR, "The Laravel embedded upgrade contract has no framework PHP minimums.\n");
        exit(1);
    }

    $selectedCells = [];

    foreach ($minimums as $laravel => $php) {
        $cell = [
            'php' => $php,
            'laravel' => $laravel,
        ];

        if (! in_array($cell, $publishedCells, true)) {
            fwrite(STDERR, sprintf(
                "The minimum qualification cell Laravel %s / PHP %s is not supported.\n",
                $laravel,
                $php,
            ));
            exit(1);
        }

        $selectedCells[] = $cell;
    }
}

echo json_encode([
    'include' => $selectedCells,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
