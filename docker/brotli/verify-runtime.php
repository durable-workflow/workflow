<?php

declare(strict_types=1);

final class BrotliRuntimeRefused extends RuntimeException {}

function brotliCommand(array $command): string
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new BrotliRuntimeRefused('Cannot inspect the Brotli runtime.');
    }
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new BrotliRuntimeRefused(implode(' ', $command).' failed: '.trim($error));
    }

    return $output;
}

function brotliElfMentionsDecoder(string $path): bool
{
    $input = fopen($path, 'rb');
    if ($input === false) {
        throw new BrotliRuntimeRefused('Cannot inspect '.$path);
    }
    try {
        if (fread($input, 4) !== "\x7fELF") {
            return false;
        }
        $tail = '';
        while (!feof($input)) {
            $chunk = $tail.fread($input, 1024 * 1024);
            if (str_contains($chunk, "libbrotlidec.so.1\0")) {
                return true;
            }
            $tail = substr($chunk, -32);
        }

        return false;
    } finally {
        fclose($input);
    }
}

function brotliInspectFile(SplFileInfo $file, array $owned): int
{
    $path = $file->getPathname();
    if (str_starts_with($file->getFilename(), 'libbrotlidec.so') && !$file->isDir()
        && !isset($owned[realpath($path) ?: $path])) {
        throw new BrotliRuntimeRefused('A decoder is outside the patched package: '.$path);
    }
    if (!$file->isFile() || $file->isLink() || !brotliElfMentionsDecoder($path)) {
        return 0;
    }
    $links = brotliCommand(['ldd', $path]);
    // A library also contains its own SONAME. Only ldd's dependency mapping
    // identifies a consumer; an unresolved mapping still fails.
    if (!preg_match('~libbrotlidec\.so\.1\s+=>\s+(\S+)~', $links, $match)) {
        return 0;
    }
    if (!isset($owned[realpath($match[1]) ?: $match[1]])) {
        throw new BrotliRuntimeRefused('An ELF consumer does not load the patched decoder: '.$path);
    }

    return 1;
}

function verifyBrotliRuntime(): void
{
    $expected = trim((string) file_get_contents(__DIR__.'/version'));
    $actual = trim(brotliCommand(['dpkg-query', '-W', '-f=${Version}', 'libbrotli1']));
    if ($actual !== $expected) {
        throw new BrotliRuntimeRefused('libbrotli1 is '.$actual.'; expected the reviewed vendor package '.$expected.'.');
    }
    $owned = [];
    foreach (explode("\n", brotliCommand(['dpkg-query', '-L', 'libbrotli1'])) as $path) {
        if (str_contains(basename($path), 'libbrotlidec.so')) {
            $resolved = realpath($path);
            if ($resolved !== false) {
                $owned[$resolved] = true;
            }
        }
    }
    if ($owned === []) {
        throw new BrotliRuntimeRefused('The patched package owns no decoder.');
    }

    $consumers = 0;
    // These are the image's tool, dependency, application and browser roots.
    // Symlinked directories are not followed; Debian /lib and /bin resolve into /usr.
    foreach (['/usr', '/opt', '/app', '/var/www', '/ms-playwright'] as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $consumers += brotliInspectFile($file, $owned);
        }
    }
    if ($consumers === 0) {
        throw new BrotliRuntimeRefused('No system Brotli consumer was inspected.');
    }
    echo brotliCommand([__DIR__.'/verify-system-brotli']);
    echo json_encode(['package' => $actual, 'elf_consumers' => $consumers], JSON_THROW_ON_ERROR).PHP_EOL;
}

if (realpath($argv[0] ?? '') === __FILE__) {
    verifyBrotliRuntime();
}
