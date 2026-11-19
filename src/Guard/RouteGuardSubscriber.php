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
 * before its controller runs.
 */
final readonly class RouteGuardSubscriber implements EventSubscriberInterface
{
    /**
     * After the router (32) has set `_route` and the firewall (8) has run.
     */
    public const PRIORITY = 0;

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
