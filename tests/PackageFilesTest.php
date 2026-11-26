<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests;

use PHPUnit\Framework\TestCase;
use Tomvondracek\Altcha\Config\ChallengeSettings;
use Tomvondracek\Altcha\Widget\WidgetRenderer;

/**
 * The files the extension ships besides its code: the vendored widget (the translations: TranslationsTest).
 */
final class PackageFilesTest extends TestCase
{
    private const ASSETS = __DIR__ . '/../assets/altcha';

    public function testWidgetFilesAreVendored(): void
    {
        foreach (['altcha.js', 'altcha.css', 'bolt-altcha.js', 'LICENSE.txt', 'workers/pbkdf2.js', 'workers/sha.js'] as $file) {
            self::assertFileExists(self::ASSETS . '/' . $file);
        }
    }

    /**
     * The vendored widget is the version package.json pins, and the cache buster says so.
     */
    public function testAssetsVersionMatchesTheWidget(): void
    {
        $package = json_decode((string) file_get_contents(__DIR__ . '/../package.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($package);
        $dependencies = $package['devDependencies'] ?? null;
        self::assertIsArray($dependencies);
        $version = $dependencies['altcha'] ?? null;
        self::assertIsString($version);

        self::assertStringStartsWith($version . '-', WidgetRenderer::ASSETS_VERSION);
    }

    /**
     * Every algorithm the config accepts has a worker in the loader, so the widget can solve it.
     */
    public function testLoaderRegistersAWorkerForEveryAlgorithm(): void
    {
        $loader = (string) file_get_contents(self::ASSETS . '/bolt-altcha.js');

        foreach (ChallengeSettings::ALGORITHMS as $algorithm) {
            self::assertStringContainsString("'" . $algorithm . "'", $loader);
        }
    }

    /**
     * Under Trusted Types the widget assigns its footer to innerHTML and constructs its workers
     * from plain strings; without a `default` policy created before the widget loads, it can't
     * render and no protected form can be sent.
     */
    public function testLoaderCreatesTheDefaultTrustedTypesPolicyBeforeTheWidget(): void
    {
        $loader = (string) file_get_contents(self::ASSETS . '/bolt-altcha.js');

        $policy = mb_strpos($loader, "createPolicy('default'");
        $import = mb_strpos($loader, "await import(asset('./altcha.js'))");
        self::assertNotFalse($policy);
        self::assertNotFalse($import);
        self::assertLessThan($import, $policy);
        self::assertStringContainsString('createHTML:', $loader);
        self::assertStringContainsString('createScriptURL:', $loader);
    }
}
