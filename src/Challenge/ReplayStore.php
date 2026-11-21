<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Challenge;

use Closure;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Makes every solved challenge usable once. A verified challenge is remembered (by a hash of its
 * signature) until it expires; after that the library rejects it as expired anyway.
 *
 * - The check and the write are one step under a lock, so a solution sent in many parallel
 *   requests passes once. Solutions share 256 locks (LOCK_BUCKET_CHARS), so the lock files stay
 *   few.
 * - Where it is stored is the pool `bolt_altcha.replay_pool` (config/services.yaml): files in
 *   var/data/bolt-altcha, which survive `cache:clear`. On several servers behind a load balancer,
 *   point the pool and `bolt_altcha.lock_factory` at a shared store (Redis), or every server keeps
 *   its own list. Before `extensions:configure` has copied config/services.yaml, the application
 *   cache and no lock are used.
 * - A pool or lock that fails (full disk, unwritable directory, Redis down), by returning false or
 *   by throwing, is logged as an error; the solution still passes, so a broken store never locks
 *   visitors and editors out. That includes a store that can't even be created: config/services.yaml
 *   passes both as service closures, resolved here on first use, inside the same error handling.
 *   Created eagerly, a FlockStore whose directory can't be written would throw while the container
 *   builds this service, i.e. on every request (the route guard and the form extension need it).
 */
final class ReplayStore
{
    /**
     * FrameworkBundle's application cache: always defined, also before `extensions:configure`.
     */
    public const POOL = 'cache.app';

    public const KEY_PREFIX = 'bolt_altcha.replay.';

    /**
     * Kept a little longer than the challenge, so clock skew between servers never reopens it.
     */
    private const GRACE_SECONDS = 60;

    /**
     * The lock covers the solutions whose hash starts with the same this many hex characters: 256
     * locks in all. A FlockStore never deletes its lock files, so a lock per solution would leave a
     * file behind for every solution ever accepted. Two solutions sharing a lock only wait for
     * each other's cache read and write.
     */
    private const LOCK_BUCKET_CHARS = 2;

    /**
     * Expired entries are removed on about one save in this many (a filesystem pool keeps them
     * otherwise).
     */
    private const PRUNE_EVERY = 200;

    /**
     * @param CacheItemPoolInterface|(Closure(): mixed) $pool        the pool, or a closure that returns it
     * @param LockFactory|(Closure(): mixed)|null       $lockFactory the lock factory, or a closure that returns it
     */
    public function __construct(
        #[Autowire(service: self::POOL)]
        private CacheItemPoolInterface|Closure $pool,
        private readonly ClockInterface $clock,
        private LockFactory|Closure|null $lockFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Marks the challenge as used. Returns false when it already was.
     */
    public function consume(string $signature, int|float|null $expiresAt): bool
    {
        $hash = hash('sha256', $signature);
        $key = self::KEY_PREFIX . $hash;
        $lock = $this->acquireLock(self::KEY_PREFIX . 'lock.' . mb_substr($hash, 0, self::LOCK_BUCKET_CHARS));
        $pool = null;

        try {
            $pool = $this->pool();
            $item = $pool->getItem($key);
            if ($item->isHit()) {
                return false;
            }

            $ttl = $expiresAt === null
                ? 3600
                : (int) ceil($expiresAt - $this->clock->now()->getTimestamp());

            $item->set(true);
            $item->expiresAfter(max(1, $ttl) + self::GRACE_SECONDS);
            if (! $pool->save($item)) {
                $this->logger->error('ALTCHA could not remember a used solution: the replay store ({pool}) failed to save. Solutions can be reused until it works again.', [
                    'pool' => get_debug_type($pool),
                ]);
            }
        } catch (Throwable $exception) {
            $this->logger->error('ALTCHA could not check or remember a used solution: the replay store ({pool}) failed: {message} Solutions can be reused until it works again.', [
                'pool' => $pool === null ? 'bolt_altcha.replay_pool' : get_debug_type($pool),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        } finally {
            $this->releaseLock($lock);
        }

        if ($pool instanceof PruneableInterface && random_int(1, self::PRUNE_EVERY) === 1) {
            try {
                $pool->prune();
            } catch (Throwable $exception) {
                $this->logger->warning('ALTCHA could not prune the replay store ({pool}): {message}', [
                    'pool' => get_debug_type($pool),
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
            }
        }

        return true;
    }

    /**
     * The pool, created on first use when it was given as a closure.
     *
     * @throws Throwable when it can't be created
     */
    private function pool(): CacheItemPoolInterface
    {
        if ($this->pool instanceof Closure) {
            $pool = ($this->pool)();
            if (! $pool instanceof CacheItemPoolInterface) {
                throw new UnexpectedValueException(sprintf('The ALTCHA replay pool must be a %s, got %s.', CacheItemPoolInterface::class, get_debug_type($pool)));
            }

            $this->pool = $pool;
        }

        return $this->pool;
    }

    /**
     * The lock for the key, or null without a lock factory or when the lock store fails (also
     * when it can't be created); the check then runs unlocked rather than failing the request.
     */
    private function acquireLock(string $key): ?LockInterface
    {
        if ($this->lockFactory === null) {
            return null;
        }

        try {
            if ($this->lockFactory instanceof Closure) {
                $lockFactory = ($this->lockFactory)();
                if (! $lockFactory instanceof LockFactory) {
                    throw new UnexpectedValueException(sprintf('The ALTCHA lock factory must be a %s, got %s.', LockFactory::class, get_debug_type($lockFactory)));
                }

                $this->lockFactory = $lockFactory;
            }

            // Released in consume(); no auto-release, so a failed release isn't retried, and
            // thrown, from the destructor.
            $lock = $this->lockFactory->createLock($key, 30, false);
            $lock->acquire(true);

            return $lock;
        } catch (Throwable $exception) {
            $this->logger->error('ALTCHA could not lock the replay store: {message} Parallel requests with the same solution may pass more than once until it works again.', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * A lock that can't be released expires after its TTL.
     */
    private function releaseLock(?LockInterface $lock): void
    {
        try {
            $lock?->release();
        } catch (Throwable $exception) {
            $this->logger->warning('ALTCHA could not release a replay store lock: {message}', [
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
