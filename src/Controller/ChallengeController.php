<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Routing\Attribute\Route;
use Tomvondracek\Altcha\Challenge\ChallengeFactory;
use Tomvondracek\Altcha\Config\Profile;
use Tomvondracek\Altcha\Config\Protection;

/**
 * GET /altcha/challenge/{profile}: a fresh challenge for the widget. Outside the admin path, so
 * it is public for the login page as well. Never cached: every visitor gets a new challenge.
 *
 * Creating a challenge costs the server one key derivation, so a client may fetch
 * `challenge_rate_limit` challenges a minute (sliding window, default 60); more get 429. A client
 * is an IPv4 address or an IPv6 /64 (see rateLimitKey()). Behind a reverse proxy, set Symfony's
 * trusted proxies, or all visitors share the proxy's address.
 */
#[AsController]
final readonly class ChallengeController
{
    public const ROUTE = 'bolt_altcha_challenge';

    public function __construct(
        private Protection $protection,
        private ChallengeFactory $challengeFactory,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $rateLimitCache,
    ) {
    }

    #[Route(
        path: '/altcha/challenge/{profile}',
        name: self::ROUTE,
        requirements: [
            'profile' => Profile::NAME_PATTERN,
        ],
        methods: ['GET'],
    )]
    public function __invoke(string $profile, Request $request): Response
    {
        $config = $this->protection->activeConfig();
        $settings = $config?->profile($profile);
        if ($config === null || $settings === null) {
            throw new NotFoundHttpException();
        }

        if ($config->challengeRateLimit > 0) {
            $limit = (new RateLimiterFactory([
                'id' => 'bolt_altcha_challenge',
                'policy' => 'sliding_window',
                'limit' => $config->challengeRateLimit,
                'interval' => '1 minute',
            ], new CacheStorage($this->rateLimitCache)))
                ->create(self::rateLimitKey($request->getClientIp()))
                ->consume();

            if (! $limit->isAccepted()) {
                return self::uncached(new Response('', Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                ]));
            }
        }

        return self::uncached(new JsonResponse($this->challengeFactory->create($settings)->toArray()));
    }

    /**
     * Whom the rate limit counts: an IPv4 address, or the /64 network of an IPv6 address. One
     * host usually gets a whole /64 (SLAAC, privacy addresses, any VPS), so a limit per IPv6
     * address could be dodged by switching addresses. An IPv4-mapped IPv6 address (::ffff:a.b.c.d,
     * from a dual-stack socket) is its IPv4 address: as a /64, every IPv4 client would share it.
     */
    public static function rateLimitKey(?string $clientIp): string
    {
        $packed = $clientIp === null ? false : @inet_pton($clientIp);
        if ($packed === false) {
            return $clientIp ?? 'unknown';
        }

        // Packed addresses are bytes: '8bit', so no character encoding applies.
        if (mb_strlen($packed, '8bit') === 16) {
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(mb_substr($packed, 12, null, '8bit'));
            }

            return inet_ntop(mb_substr($packed, 0, 8, '8bit') . str_repeat("\0", 8)) . '/64';
        }

        return (string) inet_ntop($packed);
    }

    private static function uncached(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
