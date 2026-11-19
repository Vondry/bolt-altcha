<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Controller;

use AltchaOrg\Altcha\Challenge;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Tomvondracek\Altcha\Config\Profile;
use Tomvondracek\Altcha\Controller\ChallengeController;
use Tomvondracek\Altcha\Tests\AltchaServices;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

final class ChallengeControllerTest extends TestCase
{
    use AltchaTestTrait;

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

    public function testAnIpAddressGetsALimitedNumberOfChallengesAMinute(): void
    {
        $controller = $this->controller($this->services([
            'challenge_rate_limit' => 3,
        ]));

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $controller('default', $this->request('192.0.2.1'))->getStatusCode());
        }

        $limited = $controller('default', $this->request('192.0.2.1'));
        self::assertSame(429, $limited->getStatusCode());
        self::assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));
        self::assertTrue($limited->headers->hasCacheControlDirective('no-store'));

        self::assertSame(200, $controller('default', $this->request('192.0.2.2'))->getStatusCode(), 'another address has its own limit');
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
