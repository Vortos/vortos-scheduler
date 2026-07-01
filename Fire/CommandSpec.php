<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

use InvalidArgumentException;

/**
 * Serialized "what to enqueue when this schedule fires."
 *
 * Security layers:
 *  1. $commandClass validated as a syntactically-legal FQCN at construction (this class).
 *  2. Payload validated for JSON round-trip stability at construction (catches stdClass,
 *     resources, NaN/Inf, circular refs before they reach persistence).
 *  3. Authoritative allowlist check (#[SchedulableCommand]) lives in CommandSpecValidator
 *     (S7) and runs at both create-time and dispatch-time. Both layers run — defence in depth.
 */
final readonly class CommandSpec
{
    /** @param array<mixed> $payload */
    public function __construct(
        public readonly string $commandClass,
        public readonly array  $payload = [],
    ) {
        if (!self::isValidFqcn($commandClass)) {
            throw new InvalidArgumentException(
                sprintf('CommandSpec: "%s" is not a valid fully-qualified class name.', $commandClass)
            );
        }

        self::assertJsonRoundTrip($payload);
    }

    private static function isValidFqcn(string $class): bool
    {
        // Valid PHP FQCN segments separated by backslash, each segment starts with a letter/underscore.
        return $class !== '' && (bool) preg_match(
            '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/',
            $class,
        );
    }

    /** @param array<mixed> $payload */
    private static function assertJsonRoundTrip(array $payload): void
    {
        try {
            $encoded = json_encode($payload, \JSON_THROW_ON_ERROR);
            $decoded = json_decode($encoded, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException(
                'CommandSpec payload must be JSON-serializable: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if ($decoded !== $payload) {
            throw new InvalidArgumentException(
                'CommandSpec payload failed round-trip JSON check (contains non-serializable types or floats).'
            );
        }
    }
}
