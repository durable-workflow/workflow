<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class ReadmeBranchDestinationsTest extends TestCase
{
    private const REPOSITORY_PATH = '/durable-workflow/workflow';

    public function testActionsBadgeAndDestinationAreScopedToV2(): void
    {
        [$destination, $image] = $this->badgeUrls(
            'github.com',
            self::REPOSITORY_PATH . '/actions/workflows/php.yml/badge.svg',
        );

        $this->assertUrl($destination, 'github.com', self::REPOSITORY_PATH . '/actions/workflows/php.yml');
        $this->assertSame([
            'query' => 'branch:v2',
        ], $this->queryParameters($destination));

        $this->assertUrl($image, 'github.com', self::REPOSITORY_PATH . '/actions/workflows/php.yml/badge.svg');
        $this->assertSame([
            'branch' => 'v2',
        ], $this->queryParameters($image));
    }

    public function testCodecovBadgeAndDestinationAreScopedToV2(): void
    {
        [$destination, $image] = $this->badgeUrls(
            'codecov.io',
            '/gh' . self::REPOSITORY_PATH . '/branch/v2/graph/badge.svg',
        );

        $this->assertUrl($destination, 'codecov.io', '/gh' . self::REPOSITORY_PATH . '/branch/v2');
        $this->assertUrl($image, 'codecov.io', '/gh' . self::REPOSITORY_PATH . '/branch/v2/graph/badge.svg');
    }

    public function testRepositoryLinksDoNotTargetMaster(): void
    {
        preg_match_all('~https://[^\s<>"\')]+~', $this->readme(), $matches);

        foreach ($matches[0] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            $path = parse_url($url, PHP_URL_PATH);

            if ($host === 'github.com') {
                $this->assertDoesNotMatchRegularExpression(
                    '~^' . preg_quote(self::REPOSITORY_PATH, '~') . '/(?:blob|tree)/master(?:/|$)~',
                    (string) $path,
                    sprintf('Branch-local README URL targets master: %s', $url),
                );
            }

            if ($host === 'raw.githubusercontent.com') {
                $this->assertDoesNotMatchRegularExpression(
                    '~^' . preg_quote(self::REPOSITORY_PATH, '~') . '/(?:refs/heads/)?master(?:/|$)~',
                    (string) $path,
                    sprintf('Branch-local README URL targets master: %s', $url),
                );
            }
        }
    }

    /**
     * @return array{string, string}
     */
    private function badgeUrls(string $host, string $path): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadHTML($this->readme(), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $this->assertTrue($loaded);

        $images = (new DOMXPath($document))->query('//img[@src]');
        $this->assertNotFalse($images);

        $matches = [];

        foreach ($images as $candidate) {
            if (! $candidate instanceof DOMElement) {
                continue;
            }

            $source = $candidate->getAttribute('src');
            if (parse_url($source, PHP_URL_HOST) === $host && parse_url($source, PHP_URL_PATH) === $path) {
                $matches[] = $candidate;
            }
        }

        $this->assertCount(1, $matches, sprintf('Expected exactly one badge at https://%s%s.', $host, $path));

        $image = $matches[0];
        $this->assertInstanceOf(DOMElement::class, $image);
        $link = $image->parentNode;
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame('a', $link->tagName);

        return [$link->getAttribute('href'), $image->getAttribute('src')];
    }

    private function readme(): string
    {
        $readme = file_get_contents(dirname(__DIR__, 3) . '/README.md');

        $this->assertIsString($readme);

        return $readme;
    }

    private function assertUrl(string $url, string $host, string $path): void
    {
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertSame($host, parse_url($url, PHP_URL_HOST));
        $this->assertSame($path, parse_url($url, PHP_URL_PATH));
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);

        return $parameters;
    }
}
