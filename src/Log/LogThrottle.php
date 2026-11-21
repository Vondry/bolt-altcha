<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Log;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;

/**
 * Lets a log line through once per WINDOW, across requests and processes: the extension's
 * `critical` and `warning` lines about a misconfiguration would otherwise repeat on every request,
 * and anyone could send requests to turn them into a flood of alerts.
 *
 * Remembered in the application cache. A cache that fails lets every line through: better a
 * repeated line than a lost one.
 */
final readonly class LogThrottle
{
    public const WINDOW = 300;
    private const KEY_PREFIX = 'bolt_altcha.logged.';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * Whether the line `$id` may be logged now; if so, the next WINDOW seconds won't let it through.
     */
    public function allow(string $id): bool
    {
        try {
            $item = $this->pool->getItem(self::KEY_PREFIX . hash('sha256', $id));
            if ($item->isHit()) {
                return false;
            }

            $item->set(true);
            $item->expiresAfter(self::WINDOW);
            $this->pool->save($item);
        } catch (Throwable) {
            // Log it, as without a throttle.
        }

        return true;
    }
}
