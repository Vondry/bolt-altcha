<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Verification;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Tomvondracek\Altcha\Challenge\Algorithms;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;
use Tomvondracek\Altcha\Verification\Status;
use Tomvondracek\Altcha\Verification\Verifier;

final class VerifierTest extends TestCase
{
    use AltchaTestTrait;

    public function testSolvedChallengeIsValid(): void
    {
        $services = $this->services();

        $result = $services->verifier->verify($this->solvedPayload($services), 'default');

        self::assertSame(Status::Valid, $result->status);
        self::assertTrue($result->isValid());
        self::assertSame('default', $result->profile);
    }

    public function testEverySupportedAlgorithmVerifies(): void
    {
        foreach (['PBKDF2/SHA-512', 'SHA-384'] as $algorithm) {
            $services = $this->services([
                'challenge' => [
                    'algorithm' => $algorithm,
                ],
            ]);

            self::assertSame(Status::Valid, $services->verifier->verify($this->solvedPayload($services), 'default')->status, $algorithm);
        }
    }

    public function testDisabledPassesEverything(): void
    {
        $services = $this->services([
            'enabled' => false,
        ]);

        $result = $services->verifier->verify(null, 'default');

        self::assertSame(Status::Disabled, $result->status);
        self::assertTrue($result->isValid());
    }

    public function testMissingPayload(): void
    {
        $services = $this->services();

        foreach ([null, '', '   ', ['x'], 42] as $payload) {
            self::assertSame(Status::Missing, $services->verifier->verify($payload, 'default')->status);
        }
    }

    public function testMalformedPayload(): void
    {
        $services = $this->services();

        foreach ([
            'not base64 !!!',
            base64_encode('not json'),
            base64_encode('"a string"'),
            base64_encode('{"challenge": {}}'),
            str_repeat('A', Verifier::MAX_PAYLOAD_LENGTH + 1),
        ] as $payload) {
            self::assertSame(Status::Malformed, $services->verifier->verify($payload, 'default')->status, $payload);
        }
    }

    public function testChallengeOfAnotherAlgorithmIsRejectedBeforeVerification(): void
    {
        $sha = $this->services([
            'challenge' => [
                'algorithm' => 'SHA-256',
            ],
        ]);
        $pbkdf2 = $this->services();

        self::assertSame(Status::Malformed, $pbkdf2->verifier->verify($this->solvedPayload($sha), 'default')->status);
    }

    public function testExpiredChallenge(): void
    {
        $services = $this->services();
        $challenge = $services->factory->altcha()->createChallenge(new CreateChallengeOptions(
            algorithm: Algorithms::create('PBKDF2/SHA-256'),
            cost: 1,
            counter: 5,
            expiresAt: time() - 1,
            data: [
                'profile' => 'default',
            ],
        ));

        self::assertSame(Status::Expired, $services->verifier->verify(self::solve($challenge), 'default')->status);
    }

    public function testChallengeOfAnotherSiteIsInvalid(): void
    {
        $services = $this->services();
        $foreign = (new Altcha('another-site-secret', 'another-key-secret'))->createChallenge(new CreateChallengeOptions(
            algorithm: Algorithms::create('PBKDF2/SHA-256'),
            cost: 1,
            counter: 5,
            expiresAt: time() + 600,
            data: [
                'profile' => 'default',
            ],
        ));

        self::assertSame(Status::Invalid, $services->verifier->verify(self::solve($foreign), 'default')->status);
    }

    public function testTamperedParametersAreInvalid(): void
    {
        $services = $this->services();
        $settings = $services->loader->load()->profile('default');
        self::assertNotNull($settings);
        $challenge = $services->factory->create($settings);
        $parameters = $challenge->parameters;

        // A bot lowers the cost and lengthens the expiry of a real challenge.
        $tampered = new Challenge(new ChallengeParameters(
            algorithm: $parameters->algorithm,
            nonce: $parameters->nonce,
            salt: $parameters->salt,
            cost: 1,
            keyLength: $parameters->keyLength,
            keyPrefix: $parameters->keyPrefix,
            keySignature: $parameters->keySignature,
            expiresAt: time() + 999999,
            data: $parameters->data,
        ), $challenge->signature);

        self::assertSame(Status::Invalid, $services->verifier->verify(self::solve($tampered), 'default')->status);
    }

    public function testWrongSolutionIsInvalid(): void
    {
        $services = $this->services();
        $payload = Payload::fromBase64($this->solvedPayload($services));
        $wrong = new Payload($payload->challenge, new Solution($payload->solution->counter + 1, str_repeat('0', 64)));

        self::assertSame(Status::Invalid, $services->verifier->verify($wrong->toBase64(), 'default')->status);
    }

    public function testSolutionForAnotherProfileIsRejected(): void
    {
        $services = $this->services([
            'profiles' => [
                'newsletter' => [],
            ],
        ]);

        $result = $services->verifier->verify($this->solvedPayload($services, 'newsletter'), 'default');

        self::assertSame(Status::WrongProfile, $result->status);
    }

    public function testSolutionWorksOnce(): void
    {
        $services = $this->services();
        $payload = $this->solvedPayload($services);

        self::assertSame(Status::Valid, $services->verifier->verify($payload, 'default')->status);
        self::assertSame(Status::Replayed, $services->verifier->verify($payload, 'default')->status);
    }

    public function testUnknownProfileIsAProgrammingError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown ALTCHA profile "nope"');

        $this->services()
            ->verifier->verify('x', 'nope');
    }

    public function testVerifyRequestReadsTheConfiguredField(): void
    {
        $services = $this->services([
            'field_name' => 'captcha',
        ]);

        $valid = Request::create('/subscribe', 'POST', [
            'captcha' => $this->solvedPayload($services),
        ]);
        $nested = Request::create('/subscribe', 'POST', [
            'captcha' => [
                'x' => 'y',
            ],
        ]);
        $other = Request::create('/subscribe', 'POST', [
            'altcha' => $this->solvedPayload($services),
        ]);

        self::assertSame(Status::Valid, $services->verifier->verifyRequest($valid, 'default')->status);
        self::assertSame(Status::Missing, $services->verifier->verifyRequest($nested, 'default')->status);
        self::assertSame(Status::Missing, $services->verifier->verifyRequest($other, 'default')->status);
    }

    public function testFailuresAreLoggedWithoutTheirInput(): void
    {
        $services = $this->services();
        $logger = new class() extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $verifier = new Verifier($services->loader, $services->factory, $services->replayStore, $logger);

        $verifier->verify('secret-looking-input', 'default');
        $verifier->verify($this->solvedPayload($services), 'default');

        self::assertSame([
            [
                'notice',
                'ALTCHA verification failed: {status} (profile "{profile}").',
                [
                    'status' => 'malformed',
                    'profile' => 'default',
                ],
            ],
        ], $logger->records);
    }
}
