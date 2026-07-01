<?php

declare(strict_types=1);

namespace Vortos\Scheduler\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class LeaseDriverPurityTest extends TestCase
{
    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function scanDir(string $relDir): string
    {
        $base    = $this->packageRoot() . '/' . $relDir;
        $content = '';

        if (!is_dir($base)) {
            return $content;
        }

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base)) as $file) {
            if ($file->getExtension() === 'php') {
                $content .= (string) file_get_contents($file->getRealPath());
            }
        }

        return $content;
    }

    public function test_engine_does_not_import_lease_driver_namespace(): void
    {
        $content = $this->scanDir('Engine');

        self::assertStringNotContainsString(
            'Vortos\Scheduler\Lease\Driver',
            $content,
            'Engine/ must not import concrete lease drivers — depend on LeasePort only.'
        );
    }

    public function test_schedule_domain_does_not_import_lease_namespace(): void
    {
        $schedulContent = $this->scanDir('Schedule');
        $fireContent    = $this->scanDir('Fire');

        foreach (['Schedule' => $schedulContent, 'Fire' => $fireContent] as $dir => $content) {
            self::assertStringNotContainsString(
                'Vortos\Scheduler\Lease',
                $content,
                "{$dir}/ must not import anything from the Lease namespace."
            );
        }
    }

    public function test_lease_port_and_value_objects_have_no_io_imports(): void
    {
        $leaseRoot   = $this->packageRoot() . '/Lease';
        $pureFiles   = [
            $leaseRoot . '/LeasePort.php',
            $leaseRoot . '/Lease.php',
            $leaseRoot . '/LeaseToken.php',
        ];
        $forbidden   = ['Doctrine\DBAL', '\Redis', 'PDO', 'Predis\\'];
        $violations  = [];

        foreach ($pureFiles as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);

            foreach ($forbidden as $ns) {
                if (str_contains($content, $ns)) {
                    $violations[] = basename($path) . ' imports ' . $ns;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'LeasePort, Lease, and LeaseToken must be pure (no I/O imports): ' . implode('; ', $violations)
        );
    }

    public function test_lease_drivers_do_not_import_engine_namespace(): void
    {
        $content = $this->scanDir('Lease/Driver');

        self::assertStringNotContainsString(
            'Vortos\Scheduler\Engine',
            $content,
            'Lease/Driver/ must not import Vortos\Scheduler\Engine — dependency flows one way.'
        );
    }
}
