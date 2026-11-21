<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Assets;

/**
 * Whether the project's copy of config/services.yaml is the one this version ships.
 * `extensions:configure` copies it to config/packages/extension_<package directory>.yaml (and
 * overwrites it every time: projects override services in their own config/services.yaml). After
 * an update, a deploy that skipped `extensions:configure` (Composer run with `--no-scripts`, a
 * prebuilt vendor/) keeps the old copy, and with it the old wiring, e.g. stores created eagerly
 * (before 1.0.2), which a broken var/data turned into an error on every request. The dashboard
 * then shows a notice (ServicesNoticeWidget).
 */
final class ServicesStatus
{
    public static function copyPath(string $projectDir): string
    {
        // Named like Bolt's ExtensionsConfigureCommand names it: after the package's directory.
        return $projectDir . '/config/packages/extension_' . basename(self::packageDir()) . '.yaml';
    }

    /**
     * True when the copy exists and differs from this version's file. No copy (before the first
     * `extensions:configure`) is what AssetsStatus reports.
     */
    public static function isOutdated(string $projectDir, ?string $packageFile = null): bool
    {
        $copy = self::copyPath($projectDir);
        if (! is_file($copy)) {
            return false;
        }

        $installed = @file_get_contents($copy);
        $shipped = @file_get_contents($packageFile ?? self::packageDir() . '/config/services.yaml');

        return is_string($installed) && is_string($shipped) && $installed !== $shipped;
    }

    private static function packageDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
