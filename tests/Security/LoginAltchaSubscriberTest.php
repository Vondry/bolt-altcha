<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\CheckCredentialsListener;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;
use Symfony\Component\Security\Http\EventListener\UserCheckerListener;
use Tomvondracek\Altcha\Security\LoginAltchaSubscriber;
use Tomvondracek\Altcha\Tests\AltchaServices;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;

final class LoginAltchaSubscriberTest extends TestCase
{
    use AltchaTestTrait;

    private const LOGIN_ON = [
        'profiles' => [
            'login' => [],
        ],
        'bolt' => [
            'login' => true,
        ],
    ];

    /**
     * After the throttling (so failures count), before the user lookup and the password check (so
     * a bot costs no database query and no hashing).
     */
    public function testRunsBetweenTheThrottlingAndTheUserLookup(): void
    {
        self::assertGreaterThan(LoginAltchaSubscriber::PRIORITY, self::priority(LoginThrottlingListener::getSubscribedEvents()));
        self::assertLessThan(LoginAltchaSubscriber::PRIORITY, self::priority(UserCheckerListener::getSubscribedEvents()));
        self::assertLessThan(LoginAltchaSubscriber::PRIORITY, self::priority(CheckCredentialsListener::getSubscribedEvents()));
        self::assertSame(
            [
                CheckPassportEvent::class => ['onCheckPassport', LoginAltchaSubscriber::PRIORITY],
            ],
            LoginAltchaSubscriber::getSubscribedEvents()
        );
    }

    public function testLoginWithoutSolutionFailsWithTheGenericMessage(): void
    {
        $services = $this->services(self::LOGIN_ON);

        try {
            $this->subscriber($services, $this->loginRequest([]))->onCheckPassport($this->event());
            self::fail('The login must fail.');
        } catch (CustomUserMessageAuthenticationException $exception) {
            self::assertSame(LoginAltchaSubscriber::MESSAGE_KEY, $exception->getMessageKey());
        }
    }

    public function testLoginWithSolutionGoesOn(): void
    {
        $services = $this->services(self::LOGIN_ON);
        $request = $this->loginRequest([
            'altcha' => $this->solvedPayload($services, 'login'),
        ]);

        $this->subscriber($services, $request)->onCheckPassport($this->event());

        $this->expectNotToPerformAssertions();
    }

    public function testOtherRequestsAndSettingsAreLeftAlone(): void
    {
        $on = $this->services(self::LOGIN_ON);

        $otherRoute = Request::create('/api/login', 'POST');
        $otherRoute->attributes->set('_route', 'api_login');
        $get = Request::create('/bolt/login');
        $get->attributes->set('_route', LoginAltchaSubscriber::LOGIN_ROUTE);

        $this->subscriber($on, $otherRoute)->onCheckPassport($this->event());
        $this->subscriber($on, $get)->onCheckPassport($this->event());
        $this->subscriber($on, null)->onCheckPassport($this->event());
        $this->subscriber($this->services(), $this->loginRequest([]))->onCheckPassport($this->event());
        $this->subscriber($this->services(self::LOGIN_ON + [
            'enabled' => false,
        ]), $this->loginRequest([]))->onCheckPassport($this->event());

        $this->expectNotToPerformAssertions();
    }

    /**
     * A weak key or a broken config must not lock the editors out (Protection logs it).
     */
    public function testWeakKeyLetsTheLoginThrough(): void
    {
        $services = $this->services(self::LOGIN_ON, null, '!ChangeMe!');

        $this->subscriber($services, $this->loginRequest([]))->onCheckPassport($this->event());

        $this->expectNotToPerformAssertions();
    }

    /**
     * A deploy that skipped `extensions:configure` has no widget on the login page: no editor
     * could solve anything, so the check stands down and says so in the log.
     */
    public function testUnpublishedWidgetLetsTheLoginThroughAndIsLogged(): void
    {
        $services = $this->services(self::LOGIN_ON);
        $logger = new class() extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = is_string($level) ? $level : '';
            }
        };

        $this->subscriber($services, $this->loginRequest([]), false, $logger)->onCheckPassport($this->event());

        self::assertSame(['critical'], $logger->levels);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function loginRequest(array $post): Request
    {
        $request = Request::create('/bolt/login', 'POST', $post + [
            'login' => [
                'username' => 'admin',
                'password' => 'secret',
            ],
        ]);
        $request->attributes->set('_route', LoginAltchaSubscriber::LOGIN_ROUTE);

        return $request;
    }

    private function subscriber(AltchaServices $services, ?Request $request, bool $published = true, ?AbstractLogger $logger = null): LoginAltchaSubscriber
    {
        $requestStack = new RequestStack();
        if ($request instanceof Request) {
            $requestStack->push($request);
        }

        return $logger === null
            ? new LoginAltchaSubscriber($services->protection, $services->verifier, $requestStack, $this->assets($published))
            : new LoginAltchaSubscriber($services->protection, $services->verifier, $requestStack, $this->assets($published), $logger);
    }

    private function event(): CheckPassportEvent
    {
        return new CheckPassportEvent(
            self::createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('admin'))
        );
    }

    /**
     * @param array<string, mixed> $events
     */
    private static function priority(array $events): int
    {
        $listener = $events[CheckPassportEvent::class] ?? null;
        if (is_string($listener)) {
            return 0;
        }

        self::assertIsArray($listener);
        $priority = $listener[1] ?? 0;
        self::assertIsInt($priority);

        return $priority;
    }
}
