<?php

declare(strict_types=1);

final class PhpComposerTrustRefused extends RuntimeException {}

Phar::loadPhar($argv[1]);
require_once 'phar://composer.phar/vendor/autoload.php';

$version = Composer\Composer::VERSION;
if (!str_starts_with($version, '2.') || version_compare($version, '2.10.3', '<')) {
    throw new PhpComposerTrustRefused('Composer 2.10.3 or newer on the 2 line is required.');
}

$paths = [
    '/etc/ssl/certs/ca-certificates.crt',
    getenv('SSL_CERT_FILE') ?: ini_get('openssl.cafile') ?: openssl_get_cert_locations()['default_cert_file'],
    getenv('COMPOSER_CAFILE') ?: Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
];
foreach (array_unique($paths) as $path) {
    $bundle = file_get_contents($path);
    if ($bundle === false || !preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $bundle, $certificates)) {
        throw new PhpComposerTrustRefused('Cannot inspect effective CA bundle: '.$path);
    }
    foreach ($certificates[0] as $certificate) {
        $parsed = openssl_x509_parse($certificate);
        if ($parsed === false) {
            throw new PhpComposerTrustRefused('Invalid certificate in effective CA bundle: '.$path);
        }
        $subject = $parsed['subject']['CN'] ?? '';
        if (in_array($subject, ['AAA Certificate Services', 'emSign ECC Root CA - G3'], true)) {
            throw new PhpComposerTrustRefused('Blocked certificate in effective CA bundle: '.$path);
        }
    }
    echo 'Verified PHP/Composer CA bundle: '.$path.PHP_EOL;
}
