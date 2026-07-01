<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Enforces the "no I/O in pure components" invariant.
 *
 * Engine/, Schedule/, Fire/, Clock/ must not import DBAL, Redis, ORM,
 * Symfony DI, or Symfony HTTP. These checks parse raw PHP source without
 * requiring the container, so they are fast and run in isolation.
 */
final class SchedulerPurityArchTest extends TestCase
{
    /**
     * Directories checked in full — every .php file must be I/O-free.
     * Engine/ is excluded because FireDispatcher and DbalSchedulerEnqueuer are in it;
     * they are checked via PURE_ENGINE_FILES allowlist below.
     * Security/ is excluded because DbalFourEyesApprovalStore lives under Security/Approval/Dbal/;
     * pure Security/ gate files are checked via PURE_SECURITY_FILES allowlist below.
     */
    private const PURE_DIRS = ['Schedule', 'Fire', 'Clock', 'Registry'];

    /**
     * Allowlist of pure files in Engine/ (relative filenames only, no paths).
     * All OTHER .php files under Engine/ are allowed to import I/O namespaces.
     */
    private const PURE_ENGINE_FILES = [
        'DueScan.php',
        'MisfireResolver.php',
        'DueScanResult.php',
        'DroppedSlotRecord.php',
        'FireDispatchResult.php',
        'SchedulerEnqueuerPort.php',
        'SlotCalculator.php',
    ];

    /**
     * Pure gate files under Security/ (relative filenames only).
     * DbalFourEyesApprovalStore.php is intentionally excluded — it is the I/O driver.
     */
    private const PURE_SECURITY_FILES = [
        'CommandSpecValidator.php',
        'FourEyesGate.php',
        'NullSchedulePolicy.php',
        'SchedulePolicy.php',
        'SchedulePolicyInterface.php',
        'SchedulerPermissionCatalog.php',
        'SchedulerResourcePolicy.php',
        'SchedulableCommand.php',
        'ApprovalAction.php',
        'ApprovalRequest.php',
        'ApprovalStatus.php',
        'FourEyesApprovalStoreInterface.php',
    ];

    private const FORBIDDEN_NAMESPACES = [
        'Doctrine\\DBAL',
        'Doctrine\\ORM',
        'Doctrine\\Persistence',
        'Symfony\\Component\\DependencyInjection',
        'Symfony\\Component\\HttpFoundation',
        'Symfony\\Component\\HttpKernel',
        'RedisException',
        '\\Redis',
        'Predis\\',
    ];

    #[DataProvider('pureDirectoryProvider')]
    public function test_pure_directory_has_no_io_imports(string $dir): void
    {
        $base = $this->packageRoot() . '/' . $dir;

        if (!is_dir($base)) {
            $this->markTestSkipped("Directory {$dir} does not exist yet.");
        }

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getRealPath());
            foreach (self::FORBIDDEN_NAMESPACES as $ns) {
                if (str_contains($content, $ns)) {
                    $violations[] = sprintf('%s imports %s', $file->getFilename(), $ns);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Pure components must not import I/O namespaces:' . PHP_EOL . implode(PHP_EOL, $violations),
        );
    }

    public function test_pure_security_gate_files_have_no_io_imports(): void
    {
        $securityDir = $this->packageRoot() . '/Security';

        if (!is_dir($securityDir)) {
            $this->markTestSkipped('Security/ does not exist yet.');
        }

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($securityDir)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (!in_array($file->getFilename(), self::PURE_SECURITY_FILES, true)) {
                continue; // skip I/O-allowed files (DbalFourEyesApprovalStore, etc.)
            }

            $content = (string) file_get_contents($file->getRealPath());
            foreach (self::FORBIDDEN_NAMESPACES as $ns) {
                if (str_contains($content, $ns)) {
                    $violations[] = sprintf('%s imports %s', $file->getFilename(), $ns);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Security gate files must not import I/O namespaces:' . PHP_EOL . implode(PHP_EOL, $violations),
        );
    }

    public function test_pure_clock_files_do_not_use_bare_date_or_time_functions(): void
    {
        $clockDir = $this->packageRoot() . '/Clock';
        $files    = glob($clockDir . '/*.php') ?: [];

        $violations = [];
        foreach ($files as $file) {
            $content = (string) file_get_contents($file);
            // Match bare date() / time() function calls — but not class names like DateTimeImmutable.
            if (preg_match('/(?<![a-zA-Z_\x80-\xff])(date|time)\s*\(/', $content)) {
                $violations[] = basename($file) . ' uses bare date()/time() — use ClockPort::now()';
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_pure_engine_files_have_no_io_imports(): void
    {
        $engineDir = $this->packageRoot() . '/Engine';

        if (!is_dir($engineDir)) {
            $this->markTestSkipped('Engine/ does not exist yet.');
        }

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($engineDir)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (!in_array($file->getFilename(), self::PURE_ENGINE_FILES, true)) {
                continue; // skip I/O-allowed files (FireDispatcher, DbalSchedulerEnqueuer, etc.)
            }

            $content = (string) file_get_contents($file->getRealPath());
            foreach (self::FORBIDDEN_NAMESPACES as $ns) {
                if (str_contains($content, $ns)) {
                    $violations[] = sprintf('%s imports %s', $file->getFilename(), $ns);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'Pure Engine/ files must not import I/O namespaces:' . PHP_EOL . implode(PHP_EOL, $violations),
        );
    }

    public function test_engine_does_not_import_trigger_implementations(): void
    {
        $engineDir = $this->packageRoot() . '/Engine';

        if (!is_dir($engineDir)) {
            $this->markTestSkipped('Engine/ does not exist yet.');
        }

        $this->assertDirectoryFreeOf(
            'Engine',
            ['RecurringTrigger', 'OneShotTrigger', 'IntervalTrigger'],
            'Engine/ must not import concrete Trigger implementations — use the Trigger interface only',
        );
    }

    /** @return list<array{string}> */
    public static function pureDirectoryProvider(): array
    {
        return array_map(static fn (string $d) => [$d], self::PURE_DIRS);
    }

    /** @param list<string> $patterns */
    private function assertDirectoryFreeOf(string $relDir, array $patterns, string $message): void
    {
        $dir = $this->packageRoot() . '/' . $relDir;

        if (!is_dir($dir)) {
            $this->markTestSkipped($relDir . ' does not exist yet.');
        }

        $violations = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file->getPathname()) . ' depends on ' . $pattern;
                }
            }
        }

        self::assertSame([], $violations, $message . ":\n  - " . implode("\n  - ", $violations));
    }

    private function packageRoot(): string
    {
        // __DIR__ = Tests/Architecture/; package root is two levels up
        return dirname(__DIR__, 2);
    }
}
