<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\SolveChallengeOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tomvondracek\Altcha\Challenge\Algorithms;
use Tomvondracek\Altcha\Controller\ChallengeController;
use Tomvondracek\Altcha\Tests\E2E\App\TestUserRepository;

/**
 * The rate limit of /altcha/challenge/{profile}: per IPv4 address, per IPv6 /64 (a single host
 * usually owns a whole /64, so per-address limits are no limits there). Over the limit,
 * challenges get harder; nobody is refused, so clients that share an address can't lock each
 * other out.
 */
final class ChallengeEndpointE2ETest extends E2ETestCase
{
    private const CONFIG = [
        'challenge_rate_limit' => 2,
        // A narrow counter range, so a challenge's difficulty shows in its counter.
        'challenge' => [
            'counter_min' => 10,
            'counter_max' => 12,
        ],
        'profiles' => [
            'login' => [],
        ],
        'bolt' => [
            'login' => true,
        ],
    ];

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function clients(): iterable
    {
        yield 'IPv4: the same address' => [['192.0.2.1', '192.0.2.1'], '192.0.2.1', true];
        yield 'IPv4: another address' => [['192.0.2.1', '192.0.2.1'], '192.0.2.2', false];
        yield 'IPv6: other addresses of the same /64' => [['2001:db8::1', '2001:db8::2'], '2001:db8::ffff:ffff:ffff:ffff', true];
        yield 'IPv6: another /64' => [['2001:db8::1', '2001:db8::2'], '2001:db8:0:1::1', false];
        yield 'IPv4-mapped IPv6 is the IPv4 address' => [['192.0.2.1', '::ffff:192.0.2.1'], '192.0.2.1', true];
        yield 'IPv4-mapped addresses don\'t share one /64' => [['::ffff:192.0.2.1', '::ffff:192.0.2.1'], '::ffff:198.51.100.7', false];
    }

    /**
     * @param list<string> $before addresses that fetch a challenge first, each at normal difficulty
     */
    #[DataProvider('clients')]
    public function testRateLimit(array $before, string $client, bool $harder): void
    {
        $this->bootSite(self::CONFIG);

        foreach ($before as $address) {
            self::assertSame(1, $this->difficulty($address), $address);
        }

        self::assertSame($harder ? ChallengeController::HARDER : 1, $this->difficulty($client));
    }

    /**
     * Behind a proxy Symfony doesn't trust, every visitor has the proxy's address. A bot that
     * fetches challenges as fast as it can makes them harder for everybody behind it, but the
     * editor still gets one, solves it and logs in.
     */
    public function testABusyClientBehindTheSameProxyCannotLockAnEditorOut(): void
    {
        $this->bootSite(self::CONFIG);
        $proxy = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.66',
        ];

        for ($i = 0; $i < 60; ++$i) {
            $this->request('GET', '/altcha/challenge/default', [], $proxy);
        }

        $token = self::inputValue((string) $this->request('GET', '/bolt/login', [], $proxy)->getContent(), 'login[_token]');
        $solution = $this->solution('login', $proxy);
        $response = $this->request('POST', '/bolt/login', [
            'login' => [
                'username' => TestUserRepository::USERNAME,
                'password' => TestUserRepository::PASSWORD,
                '_token' => $token,
            ],
            'altcha' => $solution,
        ], $proxy);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringNotContainsString('/bolt/login', (string) $response->headers->get('Location'));
        self::assertCount(1, $this->logger()->messages('warning'), 'the untrusted proxy is logged, once');
    }

    /**
     * The multiple of the profile's counter range the challenge was made with.
     */
    private function difficulty(string $address): int
    {
        $response = $this->request('GET', '/altcha/challenge/default', [], [
            'REMOTE_ADDR' => $address,
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        $challenge = Challenge::fromArray($data);
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(
            algorithm: Algorithms::create($challenge->parameters->algorithm),
            challenge: $challenge,
        ));
        self::assertNotNull($solution);

        return intdiv($solution->counter, 10);
    }
}
