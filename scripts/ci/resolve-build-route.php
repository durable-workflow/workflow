<?php

declare(strict_types=1);

const ROUTE_BOUNDED = 'bounded';
const ROUTE_COMPLETE = 'complete';
const ROUTE_STRUCTURAL = 'structural';
const ROUTE_LOCAL_SENTINEL = 'local-sentinel';

$options = getopt('', ['server-url:', 'event-name:', 'alpha-bootstrap::', 'self-test']);

if (isset($options['self-test'])) {
    self_test();
    exit(0);
}

$serverUrl = option($options, 'server-url');
$eventName = option($options, 'event-name');
$alphaBootstrap = optional_option($options, 'alpha-bootstrap');

printf("route=%s\n", resolve_build_route($serverUrl, $eventName, $alphaBootstrap));

function resolve_build_route(string $serverUrl, string $eventName, string $alphaBootstrap): string
{
    $isGitHub = $serverUrl === 'https://github.com';
    $isPullRequest = $eventName === 'pull_request';
    $isAlphaBootstrap = $alphaBootstrap === 'true';

    if ($isGitHub && $isPullRequest) {
        return ROUTE_BOUNDED;
    }

    if ($isGitHub) {
        return ROUTE_COMPLETE;
    }

    if ($isPullRequest) {
        return $isAlphaBootstrap ? ROUTE_STRUCTURAL : ROUTE_BOUNDED;
    }

    return ROUTE_LOCAL_SENTINEL;
}

/**
 * @param array<string, mixed> $options
 */
function option(array $options, string $name): string
{
    if (! isset($options[$name]) || ! is_scalar($options[$name])) {
        fail("--{$name} is required.");
    }

    return (string) $options[$name];
}

/**
 * @param array<string, mixed> $options
 */
function optional_option(array $options, string $name): string
{
    if (! array_key_exists($name, $options) || $options[$name] === false) {
        return '';
    }

    if (! is_scalar($options[$name])) {
        fail("--{$name} must be a scalar value.");
    }

    return (string) $options[$name];
}

function self_test(): void
{
    $cases = [
        'GitHub pull requests remain bounded during alpha' => [
            'https://github.com', 'pull_request', 'true', ROUTE_BOUNDED,
        ],
        'GitHub pushes run the complete gate during alpha' => [
            'https://github.com', 'push', 'true', ROUTE_COMPLETE,
        ],
        'GitHub pushes run the complete gate after alpha' => [
            'https://github.com', 'push', 'false', ROUTE_COMPLETE,
        ],
        'an absent alpha override fails safe on GitHub pushes' => [
            'https://github.com', 'push', '', ROUTE_COMPLETE,
        ],
        'manual GitHub runs always run the complete gate' => [
            'https://github.com', 'workflow_dispatch', 'true', ROUTE_COMPLETE,
        ],
        'manual GitHub runs run the complete gate without an override' => [
            'https://github.com', 'workflow_dispatch', '', ROUTE_COMPLETE,
        ],
        'Forgejo pull requests remain structural during alpha' => [
            'https://code.example.test', 'pull_request', 'true', ROUTE_STRUCTURAL,
        ],
        'Forgejo pull requests become bounded after alpha' => [
            'https://code.example.test', 'pull_request', 'false', ROUTE_BOUNDED,
        ],
        'Forgejo pushes remain lightweight sentinels' => [
            'https://code.example.test', 'push', 'true', ROUTE_LOCAL_SENTINEL,
        ],
    ];

    foreach ($cases as $description => [$serverUrl, $eventName, $alphaBootstrap, $expected]) {
        $actual = resolve_build_route($serverUrl, $eventName, $alphaBootstrap);

        if ($actual !== $expected) {
            fail("Route self-test failed: {$description}; expected {$expected}, got {$actual}.");
        }
    }

    printf("Build route self-test passed (%d cases).\n", count($cases));
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
