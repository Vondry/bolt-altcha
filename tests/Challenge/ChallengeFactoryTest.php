<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Challenge;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Tomvondracek\Altcha\Challenge\Algorithms;
use Tomvondracek\Altcha\Config\ChallengeSettings;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

final class ChallengeFactoryTest extends TestCase
{
    use AltchaTestTrait;

    public function testChallengeCarriesTheProfileSettingsSignedWithAnExpiry(): void
    {
        $clock = new MockClock(new DateTimeImmutable('2026-11-19 12:00:00'));
        $services = $this->services([
            'challenge' => [
                'algorithm' => 'SHA-256',
                'cost' => 3,
                'key_length' => 16,
                'expires' => 120,
            ],
            'profiles' => [
                'newsletter' => [],
            ],
        ], $clock);

        $profile = $services->loader->load()->profile('newsletter');
        self::assertNotNull($profile);
        $challenge = $services->factory->create($profile);
        $parameters = $challenge->parameters;

        self::assertSame('SHA-256', $parameters->algorithm);
        self::assertSame(3, $parameters->cost);
        self::assertSame(16, $parameters->keyLength);
        self::assertSame($clock->now()->getTimestamp() + 120, $parameters->expiresAt);
        self::assertSame([
            'profile' => 'newsletter',
        ], $parameters->data);
        self::assertNotNull($challenge->signature);
        self::assertNotNull($parameters->keySignature, 'key signature: verification needs one HMAC, no derivation');
    }

    public function testEveryChallengeIsDifferent(): void
    {
        $services = $this->services();
        $profile = $services->loader->load()->profile('default');
        self::assertNotNull($profile);

        self::assertNotSame(
            $services->factory->create($profile)->parameters->nonce,
            $services->factory->create($profile)->parameters->nonce
        );
    }

    #[DataProvider('algorithmNames')]
    public function testEverySupportedAlgorithmMapsToTheLibrary(string $name): void
    {
        self::assertSame($name, Algorithms::create($name)->getAlgorithmName());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function algorithmNames(): iterable
    {
        foreach (ChallengeSettings::ALGORITHMS as $name) {
            yield $name => [$name];
        }
    }

    public function testUnsupportedAlgorithmIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Algorithms::create('ARGON2ID');
    }
}
