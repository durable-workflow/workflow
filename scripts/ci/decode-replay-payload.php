<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Workflow\Serializers\Serializer;

require __DIR__ . '/../../vendor/autoload.php';

try {
    $container = Container::getInstance() ?? new Container();
    Container::setInstance($container);
    if (! $container->bound('config')) {
        $appKey = getenv('APP_KEY');
        $container->instance('config', new Repository([
            'app' => [
                'key' => is_string($appKey) && $appKey !== ''
                    ? $appKey
                    : 'base64:i3g6f+dV8FfsIkcxqd7gbiPn2oXk5r00sTmdD6V5utI=',
            ],
        ]));
    }

    $request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($request)) {
        throw new InvalidArgumentException('The replay payload request must be a JSON object.');
    }

    if (($request['operation'] ?? null) === 'decode') {
        $codec = $request['codec'] ?? null;
        $blob = $request['blob'] ?? null;

        if (! is_string($codec) || $codec === '' || ! is_string($blob)) {
            throw new InvalidArgumentException('A decode request requires string codec and blob fields.');
        }

        $value = Serializer::unserializeWithCodec($codec, $blob);
    } elseif (($request['operation'] ?? null) === 'value' && array_key_exists('value', $request)) {
        $value = $request['value'];
    } else {
        throw new InvalidArgumentException('The replay payload request operation is unsupported.');
    }

    // The runner exposes PHP array insertion order to workflow code, so the
    // evidence identity must preserve the same order at every nesting level.
    fwrite(STDOUT, base64_encode(serialize($value)));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
