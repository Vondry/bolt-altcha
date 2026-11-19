<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Tomvondracek\Altcha\Assets\AssetsStatus;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Verification\Verifier;

/**
 * Verifies the ALTCHA solution of Bolt's login (`bolt.login`) before the credentials.
 *
 * Bolt's LoginFormAuthenticator reads the POST to the route `bolt_login` itself; its LoginType
 * form is never submitted, so a form field could not check anything. This listener runs on the
 * passport check instead:
 *   - after the login throttling (priority 2080), so every failed attempt still counts;
 *   - before the user checker (256) and the password check (0), so a bot causes no database
 *     lookup and no password hashing.
 * A failure is the generic login error (MESSAGE_KEY, `security` domain) and a
 * LoginFailureEvent, which the throttling counts.
 *
 * It stands down (logged as critical) while the widget is not published: a deploy that skipped
 * `extensions:configure` must not lock every editor out. The same for an invalid configuration or
 * a weak key (Protection).
 *
 * Only the login form is covered. Bolt's default `api` firewall also accepts HTTP Basic
 * credentials; remove `http_basic` from it when the API is not used.
 */
final readonly class LoginAltchaSubscriber implements EventSubscriberInterface
{
    public const PRIORITY = 1024;
    public const LOGIN_ROUTE = 'bolt_login';

    /**
     * Message id in the `security` domain, in which Bolt's login template translates the error.
     */
    public const MESSAGE_KEY = 'The check that you are not a robot failed. Please try to log in again.';

    public function __construct(
        private Protection $protection,
        private Verifier $verifier,
        private RequestStack $requestStack,
        private AssetsStatus $assets,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', self::PRIORITY],
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null || $request->attributes->get('_route') !== self::LOGIN_ROUTE || ! $request->isMethod('POST')) {
            return;
        }

        $config = $this->protection->activeConfig();
        if ($config === null || $config->loginProfile === null) {
            return;
        }

        if (! $this->assets->isPublished()) {
            $this->logger->critical('ALTCHA does not check the login: the widget is not published in public/assets/altcha. Run `bin/console extensions:configure`.');

            return;
        }

        if (! $this->verifier->verifyRequest($request, $config->loginProfile)->isValid()) {
            throw new CustomUserMessageAuthenticationException(self::MESSAGE_KEY);
        }
    }
}
