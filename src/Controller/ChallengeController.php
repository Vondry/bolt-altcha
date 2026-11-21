<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use Tomvondracek\Altcha\Challenge\ChallengeFactory;
use Tomvondracek\Altcha\Config\AltchaConfig;
use Tomvondracek\Altcha\Config\Profile;
use Tomvondracek\Altcha\Config\Protection;
use Tomvondracek\Altcha\Log\LogThrottle;

/**
 * GET /altcha/challenge/{profile}: a fresh challenge for the widget. Outside the admin path, so
 * it is public for the login page as well. Never cached: every visitor gets a new challenge.
 *
 * A client (an IPv4 address or an IPv6 /64, see rateLimitKey()) gets `challenge_rate_limit`
 * challenges a minute (sliding window, default 60) at the profile's difficulty; beyond that,
 * challenges are HARDER times harder (the counter range is multiplied) until the client slows
 * down. A client is never refused: visitors that share an address (a proxy Symfony doesn't trust,
 * NAT, one IPv6 network) share the limit, and a refusal would let any one of them lock all the
 * others out of the login. Over the limit, bots pay more for every challenge and people still get
 * in. The server's cost per challenge (one derivation at `cost`, ChallengeSettings::MAX_COST) is
 * the same either way and no more than a page view.
 *
 * Over the limit, a request that came through a proxy Symfony doesn't trust is logged as a
 * warning: that is the misconfiguration that makes every visitor share one limit.
 *
 * A rate limiter store that fails is logged as an error and the challenge is handed out at the
 * profile's difficulty.
 */
#[AsController]
final readonly class ChallengeController
{
    public const ROUTE = 'bolt_altcha_challenge';

    /**
     * Over the rate limit, the counter range is multiplied by this: about four times the work for
     * the visitor (the login profile's defaults: ~3 s on a laptop instead of ~0.8 s), well within the
     * widget's 90 s timeout.
     */
    public const HARDER = 4;

    /**
     * Expired rate limiter entries are removed on about one counted challenge in this many.
     */
    private const PRUNE_EVERY = 500;

    public function __construct(
        private Protection $protection,
        private ChallengeFactory $challengeFactory,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $rateLimitCache,
        private LoggerInterface $logger = new NullLogger(),
        private ?LogThrottle $logThrottle = null,
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

        $difficulty = $this->isOverLimit($config, $request) ? self::HARDER : 1;

        return self::uncached(new JsonResponse($this->challengeFactory->create($settings, $difficulty)->toArray()));
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

    private function isOverLimit(AltchaConfig $config, Request $request): bool
    {
        if ($config->challengeRateLimit === 0) {
            return false;
        }

        try {
            $accepted = (new RateLimiterFactory([
                'id' => 'bolt_altcha_challenge',
                'policy' => 'sliding_window',
                'limit' => $config->challengeRateLimit,
                'interval' => '1 minute',
            ], new CacheStorage($this->rateLimitCache)))
                ->create(self::rateLimitKey($request->getClientIp()))
                ->consume()
                ->isAccepted();
        } catch (Throwable $exception) {
            if ($this->logThrottle?->allow('challenge.rate_limit_store') ?? true) {
                $this->logger->error('ALTCHA could not count challenges for the rate limit: {message} Challenges are handed out at their normal difficulty until it works again.', [
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }

            return false;
        }

        $this->pruneNowAndThen();

        if (! $accepted && self::isFromUntrustedProxy($request) && ($this->logThrottle?->allow('challenge.untrusted_proxy') ?? true)) {
            $this->logger->warning('ALTCHA: challenge requests from {address} carry X-Forwarded-For or Forwarded, but Symfony does not trust that address as a proxy. If it is your reverse proxy, every visitor shares its rate limit and gets harder challenges while any one of them is busy: configure Symfony\'s trusted proxies (framework.trusted_proxies).', [
                'address' => $request->server->get('REMOTE_ADDR'),
            ]);
        }

        return ! $accepted;
    }

    private static function isFromUntrustedProxy(Request $request): bool
    {
        return ($request->headers->has('X-Forwarded-For') || $request->headers->has('Forwarded'))
            && ! $request->isFromTrustedProxy();
    }

    /**
     * Each client leaves an entry in the store; a filesystem pool keeps expired ones otherwise.
     */
    private function pruneNowAndThen(): void
    {
        if (! $this->rateLimitCache instanceof PruneableInterface || random_int(1, self::PRUNE_EVERY) !== 1) {
            return;
        }

        try {
            $this->rateLimitCache->prune();
        } catch (Throwable $exception) {
            $this->logger->warning('ALTCHA could not prune the rate limiter store: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }

    private static function uncached(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
