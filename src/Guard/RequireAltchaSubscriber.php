<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Guard;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Tomvondracek\Altcha\Attribute\RequireAltcha;
use Tomvondracek\Altcha\Config\Protection;

/**
 * Enforces #[RequireAltcha] on controllers. With the attribute on both the class and the action,
 * the action's wins (one check, one profile). When the request fails, the controller is replaced
 * by one that returns the guard's response, so the action never runs.
 *
 * Sub-requests (`forward()`) are checked too, unless their main request already passed.
 */
final readonly class RequireAltchaSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Protection $protection,
        private RequestGuard $guard,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER_ARGUMENTS => 'onControllerArguments',
        ];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        if (! RequestGuard::isSubmission($request)) {
            return;
        }

        // Class attributes come first, the action's last: the last one is the most specific.
        $attributes = array_filter(
            $event->getAttributes(RequireAltcha::class),
            static fn (mixed $attribute): bool => $attribute instanceof RequireAltcha
        );
        $attribute = end($attributes);
        if (! $attribute instanceof RequireAltcha) {
            return;
        }

        $config = $this->protection->activeConfig();
        if ($config === null) {
            return;
        }

        $response = $this->guard->check($request, $attribute->profile, $config);
        if ($response !== null) {
            $event->setController(static fn (): Response => $response);
            $event->setArguments([]);
        }
    }
}
