<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Guard;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tomvondracek\Altcha\Config\Protection;

/**
 * Protects the routes under `routes:` in the config: a submission (see
 * RequestGuard::isSubmission()) to one of them needs a valid solution in the field `field_name`,
 * before the firewall and the controller run. Also a login route of an authenticator, except
 * Bolt's `bolt_login`, which `bolt.login` protects (AltchaConfig rejects it under `routes:`).
 */
final readonly class RouteGuardSubscriber implements EventSubscriberInterface
{
    /**
     * After the router (32) has set `_route` and the session is available (128), before the
     * firewall (8): authenticators (`form_login`, `json_login`, custom ones) answer the POST to
     * their route inside the firewall, so a guard after it would never see those submissions.
     */
    public const PRIORITY = 10;

    public function __construct(
        private Protection $protection,
        private RequestGuard $guard,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (! $event->isMainRequest() || ! RequestGuard::isSubmission($request)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (! is_string($route)) {
            return;
        }

        $config = $this->protection->activeConfig();
        $profile = $config?->routeProfile($route);
        if ($config === null || $profile === null) {
            return;
        }

        $response = $this->guard->check($request, $profile, $config);
        if ($response !== null) {
            $event->setResponse($response);
        }
    }
}
