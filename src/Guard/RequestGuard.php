<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Guard;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tomvondracek\Altcha\Config\AltchaConfig;
use Tomvondracek\Altcha\Verification\Verifier;

/**
 * Verifies a request to a protected route (`routes:` in the config, or #[RequireAltcha]) and
 * builds the answer to one that fails, per `on_route_failure`:
 *   - `reject`: 403 with an empty body;
 *   - `redirect_back`: 303 to the Referer's path and query when it is on the same host (no open
 *     redirect, see backUrl()), else to the homepage, with the flash message FLASH_MESSAGE (a
 *     message id, type `error`).
 *
 * Only submissions are checked: requests whose real HTTP method or overridden method
 * (X-HTTP-Method-Override, `_method`) is unsafe. A POST that claims to be a GET is still checked.
 *
 * A request is checked once per profile: after it passed, further checks for the same profile, of
 * the same request or of a sub-request it forwards to, pass too (the solution is single-use, so a
 * second check would otherwise always fail). A check for another profile verifies the solution
 * again, and fails: a solution for a cheaper profile never satisfies a stricter one.
 */
final readonly class RequestGuard
{
    public const FLASH_TYPE = 'error';

    /**
     * Message id in the `messages` domain, in which Bolt and themes translate flash messages.
     */
    public const FLASH_MESSAGE = 'The check that you are not a robot failed. Please submit the form again.';

    /**
     * Request attribute: the profile of the check this request passed.
     */
    public const VERIFIED = '_bolt_altcha_verified';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(
        private Verifier $verifier,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Whether the request sends something: its real or its overridden method is unsafe.
     */
    public static function isSubmission(Request $request): bool
    {
        return ! $request->isMethodSafe()
            || ! in_array(mb_strtoupper($request->getRealMethod()), self::SAFE_METHODS, true);
    }

    /**
     * Null when the request may go on, otherwise the response to send instead.
     */
    public function check(Request $request, string $profile, AltchaConfig $config): ?Response
    {
        if (! self::isSubmission($request) || $this->alreadyVerified($request, $profile)) {
            return null;
        }

        if ($this->verifier->verifyRequest($request, $profile)->isValid()) {
            $request->attributes->set(self::VERIFIED, $profile);
            $this->requestStack->getMainRequest()?->attributes->set(self::VERIFIED, $profile);

            return null;
        }

        if ($config->onRouteFailure === AltchaConfig::ON_FAILURE_REJECT) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(self::FLASH_TYPE, self::FLASH_MESSAGE);
            }
        }

        return new RedirectResponse($this->backUrl($request), Response::HTTP_SEE_OTHER);
    }

    private function alreadyVerified(Request $request, string $profile): bool
    {
        return $request->attributes->get(self::VERIFIED) === $profile
            || $this->requestStack->getMainRequest()?->attributes->get(self::VERIFIED) === $profile;
    }

    /**
     * The Referer's path and query when it is a page of this host, else the homepage. Only the
     * path and query are used, as a relative URL, never the Referer as given: browsers read some
     * URLs differently from parse_url(), e.g. `https://evil.example\@this.site/` goes to
     * evil.example. A path that starts with `//` or `/\` would be another host as well, hence the
     * single leading slash. (parse_url() replaces control characters with `_`, so a tab browsers
     * would drop can't join two slashes either.)
     */
    private function backUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer !== null) {
            $parts = parse_url($referer);
            $scheme = $parts['scheme'] ?? null;
            if (
                is_array($parts)
                && ($scheme === 'http' || $scheme === 'https')
                && isset($parts['host'])
                && strcasecmp($parts['host'], $request->getHost()) === 0
            ) {
                $path = '/' . ltrim($parts['path'] ?? '', '/\\');

                return isset($parts['query']) ? $path . '?' . $parts['query'] : $path;
            }
        }

        return $this->urlGenerator->generate('homepage');
    }
}
