<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Config;

use Bolt\Configuration\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;
use Tomvondracek\Altcha\Config\AltchaConfigLoader;
use Tomvondracek\Altcha\Config\ConfigValidationWarmer;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Exception\InvalidConfigurationException;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

/**
 * An invalid config or a weak key switches off the automatic protection instead of breaking every
 * form and the login, and fails the cache warm-up instead.
 */
final class ProtectionTest extends TestCase
{
    use AltchaTestTrait;

    private const STRONG = '0123456789abcdef0123456789abcdef';

    public function testActiveWithAValidConfigAndAStrongKey(): void
    {
        $protection = new Protection($this->configLoader(), new HmacKeyProvider(self::STRONG));

        self::assertNotNull($protection->activeConfig());
    }

    public function testOffWhenDisabled(): void
    {
        $protection = new Protection($this->configLoader([
            'enabled' => false,
        ]), new HmacKeyProvider(self::STRONG));

        self::assertNull($protection->activeConfig());
    }

    public function testInvalidConfigSwitchesItOffAndIsLoggedOnce(): void
    {
        $logger = $this->logger();
        $loader = $this->brokenLoader("challenge:\n  cost: lots\n", $logger);
        $protection = new Protection($loader, new HmacKeyProvider(self::STRONG), $logger);

        self::assertNull($protection->activeConfig());
        self::assertNull($protection->activeConfig());
        self::assertCount(1, $logger->records);
        self::assertSame('critical', $logger->records[0][0]);
        self::assertStringContainsString('challenge.cost', $logger->records[0][1]);
    }

    public function testInvalidYamlIsAConfigurationError(): void
    {
        $loader = $this->brokenLoader("challenge: [unclosed\n");

        self::assertNull($loader->tryLoad());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('not valid YAML');

        $loader->load();
    }

    public function testWeakKeySwitchesItOffAndIsLoggedOnce(): void
    {
        $logger = $this->logger();
        $protection = new Protection($this->configLoader(), new HmacKeyProvider('!ChangeMe!'), $logger);

        self::assertNull($protection->activeConfig());
        self::assertNull($protection->activeConfig());
        self::assertCount(1, $logger->records);
        self::assertStringContainsString('ALTCHA_HMAC_KEY', $logger->records[0][1]);

        $protection->reset();
        $protection->activeConfig();
        self::assertCount(2, $logger->records, 'logged again in the next request of a worker');
    }

    public function testLoaderResetReadsTheFileAgain(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/tomvondracek-altcha.yaml', "field_name: first\n");
        $loader = new AltchaConfigLoader($this->boltConfig($dir));
        self::assertSame('first', $loader->load()->fieldName);

        file_put_contents($dir . '/tomvondracek-altcha.yaml', "field_name: second\n");
        $loader->reset();

        self::assertSame('second', $loader->load()->fieldName);
    }

    public function testWarmerFailsTheDeployOnAnInvalidConfig(): void
    {
        $warmer = new ConfigValidationWarmer($this->brokenLoader("enabled: maybe\n"), new HmacKeyProvider(self::STRONG));

        self::assertFalse($warmer->isOptional());

        $this->expectException(InvalidConfigurationException::class);

        $warmer->warmUp('/tmp');
    }

    public function testWarmerChecksTheKeyOnceSomethingIsProtected(): void
    {
        $warmer = new ConfigValidationWarmer($this->configLoader([
            'bolt' => [
                'login' => true,
            ],
        ]), new HmacKeyProvider('!ChangeMe!'));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('APP_SECRET');

        $warmer->warmUp('/tmp');
    }

    /**
     * Right after `composer require`, Composer runs cache:clear: a site that still has Bolt's
     * placeholder APP_SECRET must not fail while nothing is protected.
     */
    public function testWarmerIgnoresAWeakKeyWhileNothingIsProtected(): void
    {
        $warmer = new ConfigValidationWarmer($this->configLoader(), new HmacKeyProvider('!ChangeMe!'));

        self::assertSame([], $warmer->warmUp('/tmp'));
    }

    private function brokenLoader(string $yaml, ?AbstractLogger $logger = null): AltchaConfigLoader
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/tomvondracek-altcha.yaml', $yaml);

        return $logger === null
            ? new AltchaConfigLoader($this->boltConfig($dir))
            : new AltchaConfigLoader($this->boltConfig($dir), $logger);
    }

    private function boltConfig(string $dir): Config
    {
        $config = self::createStub(Config::class);
        $config->method('getPath')
            ->willReturn($dir);

        return $config;
    }

    /**
     * @return AbstractLogger&object{records: list<array{string, string}>}
     */
    private function logger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /** @var list<array{string, string}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $text = (string) $message;
                foreach ($context as $key => $value) {
                    if (is_string($value)) {
                        $text = str_replace('{' . $key . '}', $value, $text);
                    }
                }

                $this->records[] = [is_string($level) ? $level : '', $text];
            }
        };
    }
}
