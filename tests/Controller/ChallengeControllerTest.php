<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Controller;

use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\SolveChallengeOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Tomvondracek\Altcha\Challenge\Algorithms;
use Tomvondracek\Altcha\Config\Profile;
use Tomvondracek\Altcha\Controller\ChallengeController;
use Tomvondracek\Altcha\Log\LogThrottle;
use Tomvondracek\Altcha\Tests\AltchaServices;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

final class ChallengeControllerTest extends TestCase
{
    use AltchaTestTrait;

    /**
     * A narrow counter range, so a challenge's difficulty shows in its counter.
     */
    private const NARROW = [
        'challenge' => [
            'counter_min' => 10,
            'counter_max' => 12,
        ],
    ];

    public function testReturnsAFreshUncachedChallenge(): void
    {
        $services = $this->services();

        $response = $this->controller($services)('default', $this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */
        $challenge = Challenge::fromArray($data);
        self::assertSame([
            'profile' => 'default',
        ], $challenge->parameters->data);

        // What the browser gets is what the widget solves and the verifier accepts.
        self::assertTrue($services->verifier->verify(self::solve($challenge), 'default')->isValid());
    }

    /**
     * Over the limit a client is never refused (clients behind one address share the limit, and a
     * refusal would lock them all out): its challenges get harder instead.
     */
    public function testOverTheLimitChallengesGetHarderInsteadOfRefused(): void
    {
        $services = $this->services(self::NARROW + [
            'challenge_rate_limit' => 3,
        ]);
        $controller = $this->controller($services);

        for ($i = 0; $i < 3; ++$i) {
            self::assertCounterBetween(10, 12, $controller('default', $this->request('192.0.2.1')));
        }

        $harder = $controller('default', $this->request('192.0.2.1'));
        self::assertSame(200, $harder->getStatusCode());
        self::assertTrue($harder->headers->hasCacheControlDirective('no-store'));
        self::assertCounterBetween(10 * ChallengeController::HARDER, 12 * ChallengeController::HARDER, $harder);
        self::assertTrue($services->verifier->verify(self::solve(self::challenge($harder)), 'default')->isValid(), 'a harder challenge still verifies');

        self::assertCounterBetween(10, 12, $controller('default', $this->request('192.0.2.2')), 'another address has its own limit');
    }

    public function testAFailingRateLimiterStoreHandsOutNormalChallengesAndIsLogged(): void
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')
            ->willThrowException(new RuntimeException('Redis is down.'));
        $logger = self::levelLogger();
        $services = $this->services(self::NARROW);
        $controller = new ChallengeController($services->protection, $services->factory, $pool, $logger);

        self::assertCounterBetween(10, 12, $controller('default', $this->request()));
        self::assertSame(['error'], $logger->levels);
    }

    /**
     * Requests through a proxy Symfony doesn't trust share its address, and so its limit: logged
     * when that matters (over the limit), at most once per LogThrottle::WINDOW.
     */
    public function testOverTheLimitThroughAnUntrustedProxyIsLoggedOnce(): void
    {
        $logger = self::levelLogger();
        $services = $this->services(self::NARROW + [
            'challenge_rate_limit' => 1,
        ]);
        $controller = new ChallengeController($services->protection, $services->factory, new ArrayAdapter(), $logger, new LogThrottle(new ArrayAdapter()));
        $proxied = static function (): Request {
            $request = Request::create('/altcha/challenge/default', server: [
                'REMOTE_ADDR' => '10.0.0.1',
            ]);
            $request->headers->set('X-Forwarded-For', '198.51.100.7');

            return $request;
        };

        $controller('default', $proxied());
        self::assertSame([], $logger->levels, 'under the limit: nothing to say');

        $controller('default', $proxied());
        $controller('default', $proxied());
        self::assertSame(['warning'], $logger->levels);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function rateLimitKeys(): iterable
    {
        yield 'IPv4' => ['192.0.2.1', '192.0.2.1'];
        yield 'IPv6: its /64' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64'];
        yield 'IPv6: written differently, same /64' => ['2001:0DB8:0001:0002:ffff::1', '2001:db8:1:2::/64'];
        yield 'IPv6 loopback' => ['::1', '::/64'];
        yield 'IPv4-mapped IPv6: the IPv4 address' => ['::ffff:192.0.2.1', '192.0.2.1'];
        yield 'not an address' => ['unix-socket', 'unix-socket'];
        yield 'no address' => [null, 'unknown'];
    }

    #[DataProvider('rateLimitKeys')]
    public function testRateLimitCountsIpv4AddressesAndIpv6Networks(?string $clientIp, string $expected): void
    {
        self::assertSame($expected, ChallengeController::rateLimitKey($clientIp));
    }

    public function testRateLimitCanBeSwitchedOff(): void
    {
        $controller = $this->controller($this->services([
            'challenge_rate_limit' => 0,
        ]));

        for ($i = 0; $i < 100; ++$i) {
            self::assertSame(200, $controller('default', $this->request())->getStatusCode());
        }
    }

    public function testUnknownProfileIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->services())('missing', $this->request());
    }

    public function testDisabledIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->services([
            'enabled' => false,
        ]))('default', $this->request());
    }

    public function testWeakKeyIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->services([], null, '!ChangeMe!'))('default', $this->request());
    }

    public function testRouteAcceptsExactlyTheProfileNames(): void
    {
        $arguments = (new ReflectionMethod(ChallengeController::class, '__invoke'))->getAttributes(Route::class)[0]->getArguments();

        self::assertSame([
            'path' => '/altcha/challenge/{profile}',
            'name' => ChallengeController::ROUTE,
            'requirements' => [
                'profile' => Profile::NAME_PATTERN,
            ],
            'methods' => ['GET'],
        ], $arguments);
    }

    private static function challenge(Response $response): Challenge
    {
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return Challenge::fromArray($data);
    }

    /**
     * The secret counter, found the way the widget finds it.
     */
    private static function assertCounterBetween(int $min, int $max, Response $response, string $message = ''): void
    {
        self::assertSame(200, $response->getStatusCode(), $message);
        $challenge = self::challenge($response);
        $solution = (new Altcha())->solveChallenge(new SolveChallengeOptions(
            algorithm: Algorithms::create($challenge->parameters->algorithm),
            challenge: $challenge,
        ));
        self::assertNotNull($solution, $message);
        self::assertGreaterThanOrEqual($min, $solution->counter, $message);
        self::assertLessThanOrEqual($max, $solution->counter, $message);
    }

    /**
     * @return AbstractLogger&object{levels: list<string>}
     */
    private static function levelLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = is_string($level) ? $level : '';
            }
        };
    }

    private function controller(AltchaServices $services): ChallengeController
    {
        return new ChallengeController($services->protection, $services->factory, new ArrayAdapter());
    }

    private function request(string $ip = '192.0.2.10'): Request
    {
        return Request::create('/altcha/challenge/default', server: [
            'REMOTE_ADDR' => $ip,
        ]);
    }
}
