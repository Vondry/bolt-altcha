<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Challenge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;
use Tomvondracek\Altcha\Exception\InvalidConfigurationException;

final class HmacKeyProviderTest extends TestCase
{
    private const STRONG = '0123456789abcdef0123456789abcdef';

    public function testKeysComeFromAltchaHmacKeyWhenSet(): void
    {
        $one = new HmacKeyProvider(self::STRONG . 'app', self::STRONG . 'one');
        $two = new HmacKeyProvider(self::STRONG . 'app', self::STRONG . 'two');

        self::assertNotSame($one->signatureKey(), $two->signatureKey());
        self::assertSame(
            $one->signatureKey(),
            (new HmacKeyProvider(self::STRONG . 'other-app', self::STRONG . 'one'))->signatureKey(),
            'APP_SECRET is not used while ALTCHA_HMAC_KEY is set'
        );
    }

    public function testWithoutItTheKeyIsDerivedFromAppSecret(): void
    {
        $one = new HmacKeyProvider(self::STRONG . 'one');
        $two = new HmacKeyProvider(self::STRONG . 'two', '');

        self::assertNotSame($one->signatureKey(), $two->signatureKey());
        self::assertSame($one->signatureKey(), (new HmacKeyProvider(self::STRONG . 'one'))->signatureKey());
        self::assertTrue($one->isUsable());
    }

    public function testTheKeysAreDerivedNeverTheSecretsThemselves(): void
    {
        $provider = new HmacKeyProvider(self::STRONG, self::STRONG . 'key');

        self::assertNotSame($provider->signatureKey(), $provider->keySignatureKey());
        self::assertNotSame(self::STRONG . 'key', $provider->signatureKey());
        self::assertSame(64, mb_strlen($provider->signatureKey()));
    }

    /**
     * Read through Symfony's env processors: .env, the real environment and the secrets vault.
     */
    public function testKeyIsTheAltchaHmacKeyEnvironmentVariable(): void
    {
        $attribute = (new ReflectionParameter([HmacKeyProvider::class, '__construct'], 'hmacKey'))->getAttributes(Autowire::class)[0]->newInstance();

        self::assertSame('%env(default::ALTCHA_HMAC_KEY)%', $attribute->value);
    }

    #[DataProvider('weakSecrets')]
    public function testWeakAppSecretIsRejected(string $appSecret): void
    {
        $provider = new HmacKeyProvider($appSecret);

        self::assertFalse($provider->isUsable());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('APP_SECRET');

        $provider->signatureKey();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function weakSecrets(): iterable
    {
        yield 'Bolt placeholder' => ['!ChangeMe!'];
        yield 'Symfony placeholder' => ['ThisTokenIsNotSoSecretChangeIt'];
        yield 'empty' => [''];
        yield 'short' => ['0123456789abcdef'];
    }

    public function testShortAltchaHmacKeyIsRejected(): void
    {
        $provider = new HmacKeyProvider(self::STRONG, 'too-short');

        self::assertFalse($provider->isUsable());

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('ALTCHA_HMAC_KEY');

        $provider->keySignatureKey();
    }
}
