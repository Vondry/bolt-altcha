<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Config;

use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

final class AltchaConfigLoaderTest extends TestCase
{
    use AltchaTestTrait;

    public function testWithoutAProjectFileThePackageDefaultsApply(): void
    {
        $loader = new AltchaConfigLoader($this->boltConfig($this->tempDir()));

        self::assertSame(['default', 'login'], array_keys($loader->load()->profiles));
    }

    public function testLocalFileOverridesSingleKeys(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/tomvondracek-altcha.yaml', <<<'YAML'
            field_name: captcha
            challenge:
              cost: 2000
              expires: 300
            bolt:
              login: true
            YAML);
        file_put_contents($dir . '/tomvondracek-altcha_local.yaml', <<<'YAML'
            enabled: false
            challenge:
              cost: 10
            YAML);

        $config = (new AltchaConfigLoader($this->boltConfig($dir)))->load();

        self::assertFalse($config->enabled);
        self::assertSame('captcha', $config->fieldName);
        self::assertSame(10, $config->profiles['default']->challenge->cost);
        self::assertSame(300, $config->profiles['default']->challenge->expires);
        self::assertSame('default', $config->loginProfile);
    }

    public function testProjectFileReplacesThePackageDefaultsInsteadOfMergingWithThem(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/tomvondracek-altcha.yaml', "enabled: true\n");

        self::assertSame(['default'], array_keys((new AltchaConfigLoader($this->boltConfig($dir)))->load()->profiles));
    }

    public function testConfigIsLoadedOncePerLoader(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/tomvondracek-altcha.yaml', "field_name: first\n");
        $loader = new AltchaConfigLoader($this->boltConfig($dir));
        $first = $loader->load();

        file_put_contents($dir . '/tomvondracek-altcha.yaml', "field_name: second\n");

        self::assertSame($first, $loader->load());
        self::assertSame('first', $loader->load()->fieldName);
    }

    public function testListsAndEmptyFilesAreHandled(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/a.yaml', "x: [1, 2]\ny: { a: 1 }\n");
        file_put_contents($dir . '/b.yaml', "x: [3]\ny: { b: 2 }\n");
        file_put_contents($dir . '/empty.yaml', '');

        self::assertSame(
            [
                'x' => [3],
                'y' => [
                    'a' => 1,
                    'b' => 2,
                ],
            ],
            AltchaConfigLoader::parseFiles([$dir . '/a.yaml', $dir . '/empty.yaml', $dir . '/missing.yaml', $dir . '/b.yaml'])
        );
    }

    private function boltConfig(string $dir): Config
    {
        $config = self::createStub(Config::class);
        $config->method('getPath')
            ->willReturn($dir);

        return $config;
    }
}
