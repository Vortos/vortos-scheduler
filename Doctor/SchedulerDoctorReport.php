<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Doctor;

final readonly class SchedulerDoctorReport
{
    public const SCHEMA_VERSION = 1;

    /** @param list<SchedulerDoctorFinding> $findings */
    public function __construct(public readonly array $findings) {}

    public function isClear(): bool
    {
        foreach ($this->findings as $f) {
            if ($f->isFailure()) {
                return false;
            }
        }

        return true;
    }

    public function exitCode(): int
    {
        return $this->isClear() ? 0 : 1;
    }

    public function countByStatus(SchedulerDoctorStatus $status): int
    {
        return count(array_filter($this->findings, fn($f) => $f->status === $status));
    }

    public function toJson(): string
    {
        return json_encode(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'clear'          => $this->isClear(),
                'findings'       => array_map(fn($f) => [
                    'check_id'    => $f->checkId,
                    'status'      => $f->status->value,
                    'summary'     => $f->summary,
                    'detail'      => $f->detail,
                    'remediation' => $f->remediation,
                ], $this->findings),
            ],
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }
}
