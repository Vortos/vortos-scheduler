<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Fire;

use ReflectionClass;
use ReflectionNamedType;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Exception\InvalidCommandPayloadException;

/**
 * Turns a CommandSpec's (commandClass, payload) pair back into a live command
 * instance for FireQueueConsumer to hand to the CQRS CommandBus.
 *
 * Payload keys are matched to the command's constructor named parameters —
 * the same keyword-construction convention every value object in this package
 * already uses (ScheduleRun, RunStamp, CommandSpec itself). No reflection-based
 * property injection, no external serializer: the command class's constructor
 * signature is the single source of truth for its shape.
 */
final class CommandHydrator
{
    /**
     * @param class-string $commandClass
     * @param array<string, mixed> $payload
     *
     * @throws InvalidCommandPayloadException if a required parameter is missing,
     *                                         an unknown key is present, or the
     *                                         class cannot be constructed.
     */
    public function hydrate(string $commandClass, array $payload): CommandInterface
    {
        $reflection  = new ReflectionClass($commandClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            if ($payload !== []) {
                throw new InvalidCommandPayloadException(
                    $commandClass,
                    'command has no constructor but payload is non-empty',
                );
            }

            /** @var CommandInterface */
            return $reflection->newInstance();
        }

        $params       = $constructor->getParameters();
        $knownNames   = array_map(static fn ($p) => $p->getName(), $params);
        $unknownKeys  = array_diff(array_keys($payload), $knownNames);

        if ($unknownKeys !== []) {
            throw new InvalidCommandPayloadException(
                $commandClass,
                sprintf('unknown payload key(s): %s', implode(', ', $unknownKeys)),
            );
        }

        $args = [];

        foreach ($params as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $payload)) {
                $args[$name] = $this->coerce($param->getType(), $payload[$name]);

                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                continue; // let the constructor apply its own default
            }

            if ($param->allowsNull()) {
                $args[$name] = null;

                continue;
            }

            throw new InvalidCommandPayloadException(
                $commandClass,
                sprintf('missing required payload key "%s"', $name),
            );
        }

        try {
            /** @var CommandInterface */
            return $reflection->newInstanceArgs($args);
        } catch (\Throwable $e) {
            throw new InvalidCommandPayloadException($commandClass, $e->getMessage(), $e);
        }
    }

    private function coerce(?ReflectionNamedType $type, mixed $value): mixed
    {
        // JSON round-trips ints as ints and floats as floats already (CommandSpec
        // enforces round-trip stability at construction), so the only gap is
        // DateTimeImmutable-typed parameters — payload always carries an ISO-8601
        // string for these since DateTimeImmutable itself isn't JSON-serializable.
        if ($type !== null && !$type->isBuiltin() && $type->getName() === \DateTimeImmutable::class && is_string($value)) {
            return new \DateTimeImmutable($value);
        }

        return $value;
    }
}
