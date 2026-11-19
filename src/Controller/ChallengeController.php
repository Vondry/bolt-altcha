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
 * Creating a challenge costs the server one key derivation, so an IP address may fetch
 * `challenge_rate_limit` challenges a minute (sliding window, default 60); more get 429. Behind a
 * reverse proxy, set Symfony's trusted proxies, or all visitors share the proxy's address.
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
                ->create($request->getClientIp() ?? 'unknown')
                ->consume();

            if (! $limit->isAccepted()) {
                return self::uncached(new Response('', Response::HTTP_TOO_MANY_REQUESTS, [
                    'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                ]));
            }
        }

        return self::uncached(new JsonResponse($this->challengeFactory->create($settings)->toArray()));
    }

    private static function uncached(Response $response): Response
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
