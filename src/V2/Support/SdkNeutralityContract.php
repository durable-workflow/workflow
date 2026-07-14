<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use RuntimeException;

/**
 * Loader for the packaged, machine-readable SDK neutrality contract.
 *
 * The platform ships a deliberately narrow set of first-party SDKs
 * (PHP `durable-workflow/sdk`, Python `durable_workflow`, and Rust
 * `durable-workflow`). The `durable-workflow/workflow` package remains the
 * embedded Laravel engine and replay owner; it is not the standalone PHP SDK.
 * Building or maintaining additional first-party SDKs is not a release goal.
 * Public contracts must nevertheless avoid language-specific assumptions
 * that would require a protocol redesign for a future TypeScript, Go, Java,
 * or .NET SDK.
 *
 * The exact field-shape authority ships at
 * `resources/sdk-neutrality-contract.json`. This class re-exports that
 * document for PHP consumers and the standalone workflow-server; it does not
 * maintain a second semantic model.
 *
 * The standalone `workflow-server` re-exports this manifest from
 * `GET /api/cluster/info` under `sdk_neutrality_contract`.
 *
 * Adding a neutrality rule, tightening an existing rule, adding a required
 * audit step, adding a surface family to the audit scope, or changing the
 * official-SDK breadth policy is a contract change. Bump VERSION and
 * MIRROR_SHA256, then align the architecture doc, the public JSON mirror, and
 * the per-package stability documents in the same change. Removing a
 * neutrality rule or audit step is a major change.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 */
final class SdkNeutralityContract
{
    public const SCHEMA = 'durable-workflow.v2.sdk-neutrality.contract';

    public const VERSION = 4;

    public const PACKAGE_CONTRACT_PATH = 'resources/sdk-neutrality-contract.json';

    public const MIRROR_SHA256 = '93ab20aa7c69a4994affef4b3d511c0bf99d2304828d9626615b2da3f60064fd';

    public const AUTHORITY_DOC = 'https://github.com/durable-workflow/workflow/blob/v2/docs/architecture/sdk-neutrality.md';

    public const PUBLIC_CONTRACT_URL = 'https://durable-workflow.github.io/sdk-neutrality-contract.json';

    public const PROTOCOL_CATALOG_URL = 'https://durable-workflow.github.io/platform-protocol-specs.json';

    public const CONFORMANCE_SUITE_URL = 'https://durable-workflow.github.io/platform-conformance-contract.json';

    public const REPLAY_SCENARIOS_URL = 'https://durable-workflow.github.io/platform-conformance/replay-runtime-scenarios.json';

    public const SIGNAL_QUERY_SCENARIOS_URL = 'https://durable-workflow.github.io/platform-conformance/signal-query-runtime-scenarios.json';

    public const POSTURE_PRIORITY = 'priority';

    public const POSTURE_DEMAND_DRIVEN = 'demand_driven';

    public const POSTURE_OUT_OF_SCOPE = 'out_of_scope';

    public const POSTURES = [
        self::POSTURE_PRIORITY,
        self::POSTURE_DEMAND_DRIVEN,
        self::POSTURE_OUT_OF_SCOPE,
    ];

    public const RULE_PROTOCOL = 'protocol_neutrality';

    public const RULE_CODEC = 'codec_neutrality';

    public const RULE_ERROR_SHAPE = 'error_shape_neutrality';

    public const RULE_TYPE_IDENTITY = 'type_identity_neutrality';

    public const RULE_REPLAY_FIXTURE = 'replay_fixture_neutrality';

    public const RULE_DISCOVERY = 'discovery_neutrality';

    public const RULE_DOCUMENTATION = 'documentation_neutrality';

    public const RULES = [
        self::RULE_PROTOCOL,
        self::RULE_CODEC,
        self::RULE_ERROR_SHAPE,
        self::RULE_TYPE_IDENTITY,
        self::RULE_REPLAY_FIXTURE,
        self::RULE_DISCOVERY,
        self::RULE_DOCUMENTATION,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        $path = dirname(__DIR__, 3) . '/' . self::PACKAGE_CONTRACT_PATH;
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("SDK neutrality contract is missing at {$path}.");
        }

        if (hash('sha256', $json) !== self::MIRROR_SHA256) {
            throw new RuntimeException('SDK neutrality contract digest does not match the packaged authority.');
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('SDK neutrality contract is not valid JSON.', 0, $exception);
        }

        if (!is_array($decoded) || ($decoded['schema'] ?? null) !== self::SCHEMA || ($decoded['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('SDK neutrality contract identity does not match the class contract.');
        }

        self::$manifest = $decoded;

        return self::$manifest;
    }

    /**
     * @return array<int, string>
     */
    public static function ruleNames(): array
    {
        return self::RULES;
    }

    /**
     * @return array<int, string>
     */
    public static function postureValues(): array
    {
        return self::POSTURES;
    }
}
