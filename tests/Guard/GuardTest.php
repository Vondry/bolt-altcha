<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Guard;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tomvondracek\Altcha\Config\AltchaConfig;
use Tomvondracek\Altcha\Guard\RequestGuard;
use Tomvondracek\Altcha\Guard\RequireAltchaSubscriber;
use Tomvondracek\Altcha\Guard\RouteGuardSubscriber;
use Tomvondracek\Altcha\Tests\AltchaServices;
use Tomvondracek\Altcha\Tests\AltchaTestTrait;
use Tomvondracek\Altcha\Tests\Fixtures\GuardedClassController;
use Tomvondracek\Altcha\Tests\Fixtures\GuardedController;

final class GuardTest extends TestCase
{
    use AltchaTestTrait;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->requestStack = new RequestStack();
    }

    public function testSafeRequestsAndValidSolutionsPass(): void
    {
        $services = $this->services();
        $guard = $this->guard($services);
        $config = $services->loader->load();

        self::assertNull($guard->check(Request::create('/newsletter'), 'default', $config));
        self::assertNull($guard->check(Request::create('/newsletter', 'HEAD'), 'default', $config));
        self::assertNull($guard->check(Request::create('/newsletter', 'POST', [
            'altcha' => $this->solvedPayload($services),
        ]), 'default', $config));
    }

    /**
     * A POST that claims to be a GET (X-HTTP-Method-Override, `_method`) is still a submission.
     */
    public function testMethodOverrideDoesNotSkipTheCheck(): void
    {
        Request::enableHttpMethodParameterOverride();

        try {
            $header = Request::create('/newsletter', 'POST', server: [
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'GET',
            ]);
            $parameter = Request::create('/newsletter', 'POST', [
                '_method' => 'GET',
            ]);

            foreach ([$header, $parameter] as $request) {
                self::assertSame('GET', $request->getMethod(), 'the override took effect');
                self::assertTrue(RequestGuard::isSubmission($request));
                self::assertInstanceOf(RedirectResponse::class, $this->guard($this->services())->check($request, 'default', AltchaConfig::fromArray([])));
            }

            // Symfony honours an override only on a real POST: a GET stays a GET.
            self::assertFalse(RequestGuard::isSubmission(Request::create('/newsletter', 'GET', server: [
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST',
            ])));
            self::assertFalse(RequestGuard::isSubmission(Request::create('/newsletter')));
        } finally {
            (fn (): false => self::$httpMethodParameterOverride = false)->call(new Request());
        }
    }

    /**
     * The solution is single-use: a second check of the same request (routes: and the attribute
     * on one route, or a forward()) must not fail as a replay.
     */
    public function testARequestIsCheckedOnce(): void
    {
        $services = $this->services();
        $guard = $this->guard($services);
        $config = $services->loader->load();
        $request = Request::create('/newsletter', 'POST', [
            'altcha' => $this->solvedPayload($services),
        ]);
        $this->requestStack->push($request);

        self::assertNull($guard->check($request, 'default', $config));
        self::assertSame('default', $request->attributes->get(RequestGuard::VERIFIED));
        self::assertNull($guard->check($request, 'default', $config), 'second check of the same request');

        $forwarded = $request->duplicate(attributes: [
            '_controller' => 'other',
        ]);
        $this->requestStack->push($forwarded);
        self::assertNull($guard->check($forwarded, 'default', $config), 'a sub-request of a checked request');
    }

    public function testRejectAnswers403WithoutABody(): void
    {
        $services = $this->services([
            'on_route_failure' => 'reject',
        ]);
        $response = $this->guard($services)
            ->check(Request::create('/newsletter', 'POST'), 'default', $services->loader->load());

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function testRedirectBackGoesToTheSameHostRefererWithAFlash(): void
    {
        $request = Request::create('https://example.com/newsletter', 'POST', server: [
            'HTTP_REFERER' => 'https://EXAMPLE.com/contact?x=1',
        ]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $response = $this->guard($this->services())
            ->check($request, 'default', AltchaConfig::fromArray([]));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://EXAMPLE.com/contact?x=1', $response->getTargetUrl());
        self::assertSame([RequestGuard::FLASH_MESSAGE], $session->getFlashBag()->get(RequestGuard::FLASH_TYPE));
        self::assertFalse($request->attributes->has(RequestGuard::VERIFIED));
    }

    public function testForeignOrOddReferersFallBackToTheHomepage(): void
    {
        $guard = $this->guard($this->services());

        foreach ([null, 'https://evil.example/phish', 'javascript:alert(1)', '//evil.example/', 'https://example.com.evil.example/'] as $referer) {
            $request = Request::create('https://example.com/newsletter', 'POST');
            if ($referer !== null) {
                $request->headers->set('referer', $referer);
            }

            $response = $guard->check($request, 'default', AltchaConfig::fromArray([]));

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('/', $response->getTargetUrl(), (string) $referer);
        }
    }

    public function testRouteGuardChecksOnlyConfiguredRoutesOnSubmit(): void
    {
        $services = $this->services([
            'routes' => [
                'newsletter_subscribe' => true,
            ],
        ]);
        $subscriber = new RouteGuardSubscriber($services->protection, $this->guard($services));

        self::assertSame([
            KernelEvents::REQUEST => ['onKernelRequest', RouteGuardSubscriber::PRIORITY],
        ], RouteGuardSubscriber::getSubscribedEvents());

        $blocked = $this->requestEvent('newsletter_subscribe', 'POST');
        $subscriber->onKernelRequest($blocked);
        self::assertInstanceOf(RedirectResponse::class, $blocked->getResponse());

        $valid = $this->requestEvent('newsletter_subscribe', 'POST', [
            'altcha' => $this->solvedPayload($services),
        ]);
        $subscriber->onKernelRequest($valid);
        self::assertNull($valid->getResponse());

        foreach ([
            $this->requestEvent('newsletter_subscribe', 'GET'),
            $this->requestEvent('contact', 'POST'),
            $this->requestEvent(null, 'POST'),
            $this->requestEvent('newsletter_subscribe', 'POST', [], HttpKernelInterface::SUB_REQUEST),
        ] as $event) {
            $subscriber->onKernelRequest($event);
            self::assertNull($event->getResponse());
        }
    }

    public function testRouteGuardDoesNothingWhileTheProtectionIsOff(): void
    {
        foreach ([
            $this->services([
                'enabled' => false,
                'routes' => [
                    'newsletter_subscribe' => true,
                ],
            ]),
            $this->services([
                'routes' => [
                    'newsletter_subscribe' => true,
                ],
            ], null, '!ChangeMe!'),
        ] as $services) {
            $event = $this->requestEvent('newsletter_subscribe', 'POST');

            (new RouteGuardSubscriber($services->protection, $this->guard($services)))->onKernelRequest($event);

            self::assertNull($event->getResponse());
        }
    }

    public function testRequireAltchaReplacesTheControllerOnFailure(): void
    {
        $services = $this->services([
            'profiles' => [
                'newsletter' => [],
            ],
        ]);
        $subscriber = new RequireAltchaSubscriber($services->protection, $this->guard($services));

        self::assertSame([
            KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments',
        ], RequireAltchaSubscriber::getSubscribedEvents());

        $controller = new GuardedController();

        $failed = $this->argumentsEvent($controller, Request::create('/newsletter', 'POST'));
        $subscriber->onControllerArguments($failed);
        $response = ($failed->getController())(...$failed->getArguments());
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame([], $failed->getArguments());

        $passed = $this->argumentsEvent($controller, Request::create('/newsletter', 'POST', [
            'altcha' => $this->solvedPayload($services, 'newsletter'),
        ]));
        $subscriber->onControllerArguments($passed);
        self::assertSame($controller, $passed->getController());

        $get = $this->argumentsEvent($controller, Request::create('/newsletter'));
        $subscriber->onControllerArguments($get);
        self::assertSame($controller, $get->getController());

        $unguarded = static fn (): Response => new Response('ok');
        $plain = $this->argumentsEvent($unguarded, Request::create('/newsletter', 'POST'));
        $subscriber->onControllerArguments($plain);
        self::assertSame($unguarded, $plain->getController());
    }

    /**
     * With the attribute on the class and on the action, the action's profile decides and the
     * solution is checked once.
     */
    public function testActionAttributeWinsOverTheClassAttribute(): void
    {
        $services = $this->services([
            'profiles' => [
                'newsletter' => [],
                'contact' => [],
            ],
        ]);
        $subscriber = new RequireAltchaSubscriber($services->protection, $this->guard($services));
        $controller = [new GuardedClassController(), 'subscribe'];

        $event = $this->argumentsEvent($controller, Request::create('/newsletter', 'POST', [
            'altcha' => $this->solvedPayload($services, 'newsletter'),
        ]));
        $subscriber->onControllerArguments($event);

        self::assertSame($controller, $event->getController(), 'the action asks for `newsletter`');
    }

    public function testRequireAltchaDoesNothingWhileTheProtectionIsOff(): void
    {
        $services = $this->services([
            'enabled' => false,
        ]);
        $controller = new GuardedController();
        $event = $this->argumentsEvent($controller, Request::create('/newsletter', 'POST'));

        (new RequireAltchaSubscriber($services->protection, $this->guard($services)))->onControllerArguments($event);

        self::assertSame($controller, $event->getController());
    }

    private function guard(AltchaServices $services): RequestGuard
    {
        $urlGenerator = self::createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->willReturn('/');

        return new RequestGuard($services->verifier, $urlGenerator, $this->requestStack);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function requestEvent(?string $route, string $method, array $post = [], int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create('/newsletter', $method, $post);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $type);
    }

    private function argumentsEvent(callable $controller, Request $request): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent(
            self::createStub(HttpKernelInterface::class),
            $controller,
            [],
            $request,
            HttpKernelInterface::MAIN_REQUEST
        );
    }
}
