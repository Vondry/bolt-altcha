<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rate limit of /altcha/challenge/{profile}: per IPv4 address, per IPv6 /64 (a single host
 * usually owns a whole /64, so per-address limits are no limits there).
 */
final class ChallengeEndpointE2ETest extends E2ETestCase
{
    private const CONFIG = [
        'challenge_rate_limit' => 2,
    ];

    /**
     * @return iterable<string, array{list<string>, string, int}>
     */
    public static function clients(): iterable
    {
        yield 'IPv4: the same address' => [['192.0.2.1', '192.0.2.1'], '192.0.2.1', Response::HTTP_TOO_MANY_REQUESTS];
        yield 'IPv4: another address' => [['192.0.2.1', '192.0.2.1'], '192.0.2.2', Response::HTTP_OK];
        yield 'IPv6: other addresses of the same /64' => [['2001:db8::1', '2001:db8::2'], '2001:db8::ffff:ffff:ffff:ffff', Response::HTTP_TOO_MANY_REQUESTS];
        yield 'IPv6: another /64' => [['2001:db8::1', '2001:db8::2'], '2001:db8:0:1::1', Response::HTTP_OK];
        yield 'IPv4-mapped IPv6 is the IPv4 address' => [['192.0.2.1', '::ffff:192.0.2.1'], '192.0.2.1', Response::HTTP_TOO_MANY_REQUESTS];
        yield 'IPv4-mapped addresses don\'t share one /64' => [['::ffff:192.0.2.1', '::ffff:192.0.2.1'], '::ffff:198.51.100.7', Response::HTTP_OK];
    }

    /**
     * @param list<string> $before addresses that fetch a challenge first, each successfully
     */
    #[DataProvider('clients')]
    public function testRateLimit(array $before, string $client, int $expected): void
    {
        $this->bootSite(self::CONFIG);

        foreach ($before as $address) {
            self::assertSame(Response::HTTP_OK, $this->challenge($address)->getStatusCode(), $address);
        }

        self::assertSame($expected, $this->challenge($client)->getStatusCode());
    }

    public function testAThrottledAnswerSaysWhenToRetry(): void
    {
        $this->bootSite(self::CONFIG);

        $this->challenge('192.0.2.1');
        $this->challenge('192.0.2.1');
        $response = $this->challenge('192.0.2.1');

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    private function challenge(string $address): Response
    {
        return $this->request('GET', '/altcha/challenge/default', [], [
            'REMOTE_ADDR' => $address,
        ]);
    }
}
