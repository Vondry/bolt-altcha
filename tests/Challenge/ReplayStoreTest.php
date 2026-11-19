<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Challenge;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\Exception\LockStorageException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Tomvondracek\Altcha\Challenge\ReplayStore;

final class ReplayStoreTest extends TestCase
{
    public function testAChallengeCanBeConsumedOnce(): void
    {
        $clock = new MockClock();
        $store = new ReplayStore(new ArrayAdapter(), $clock);
        $expiresAt = $clock->now()->getTimestamp() + 600;

        self::assertTrue($store->consume('signature-a', $expiresAt));
        self::assertFalse($store->consume('signature-a', $expiresAt));
        self::assertTrue($store->consume('signature-b', $expiresAt));
    }

    /**
     * The check and the write happen under one lock, released afterwards: a parallel request with
     * the same solution waits, then finds it used.
     */
    public function testCheckAndWriteHappenUnderALock(): void
    {
        $clock = new MockClock();
        $events = new ArrayObject();
        $pool = new class($events) extends ArrayAdapter {
            /**
             * @param ArrayObject<int, string> $events
             */
            public function __construct(
                private readonly ArrayObject $events,
            ) {
                parent::__construct();
            }

            public function getItem(mixed $key): CacheItem
            {
                $this->events->append('read');

                return parent::getItem($key);
            }

            public function save(CacheItemInterface $item): bool
            {
                $this->events->append('write');

                return parent::save($item);
            }
        };
        $lockStore = new class($events) implements PersistingStoreInterface {
            /**
             * @param ArrayObject<int, string> $events
             */
            public function __construct(
                private readonly ArrayObject $events,
                private readonly InMemoryStore $inner = new InMemoryStore(),
            ) {
            }

            public function save(Key $key): void
            {
                $this->events->append('lock');
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                $this->events->append('unlock');
                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
        $store = new ReplayStore($pool, $clock, new LockFactory($lockStore));

        self::assertTrue($store->consume('signature', $clock->now()->getTimestamp() + 600));
        self::assertFalse($store->consume('signature', $clock->now()->getTimestamp() + 600));
        self::assertSame(['lock', 'read', 'write', 'unlock', 'lock', 'read', 'unlock'], $events->getArrayCopy());
    }

    public function testItemLivesUntilTheChallengeExpiresPlusGrace(): void
    {
        $clock = new MockClock();
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')
            ->willReturn(false);
        $item->expects(self::once())
            ->method('expiresAfter')
            ->with(300 + 60);

        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')
            ->with(self::matchesRegularExpression('/\Abolt_altcha\.replay\.[0-9a-f]{64}\z/'))
            ->willReturn($item);
        $pool->expects(self::once())
            ->method('save')
            ->with($item)
            ->willReturn(true);

        (new ReplayStore($pool, $clock))->consume('signature', $clock->now()->getTimestamp() + 300);
    }

    public function testChallengeWithoutExpiryIsKeptForAnHour(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')
            ->willReturn(false);
        $item->expects(self::once())
            ->method('expiresAfter')
            ->with(3600 + 60);

        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')
            ->willReturn($item);
        $pool->method('save')
            ->willReturn(true);

        (new ReplayStore($pool, new MockClock()))->consume('signature', null);
    }

    /**
     * A broken cache is logged, and does not lock visitors out.
     */
    public function testFailedSaveIsLoggedAsAnError(): void
    {
        $item = self::createStub(CacheItemInterface::class);
        $item->method('isHit')
            ->willReturn(false);
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')
            ->willReturn($item);
        $pool->method('save')
            ->willReturn(false);

        $logger = new class() extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = is_string($level) ? $level : '';
            }
        };

        self::assertTrue((new ReplayStore($pool, new MockClock(), null, $logger))->consume('signature', null));
        self::assertSame(['error'], $logger->levels);
    }

    /**
     * A pool that throws (Redis down, with a pool that doesn't catch it itself) is logged and lets
     * the solution through.
     */
    public function testThrowingPoolIsLoggedAsAnError(): void
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')
            ->willThrowException(new RuntimeException('Connection refused'));
        $logger = self::levelLogger();

        self::assertTrue((new ReplayStore($pool, new MockClock(), null, $logger))->consume('signature', null));
        self::assertSame(['error'], $logger->levels);
    }

    /**
     * A lock store that fails (unwritable var/data, Redis down) is logged, and the check runs
     * without the lock.
     */
    public function testFailingLockStoreIsLoggedAndTheCheckStillRuns(): void
    {
        $lockStore = self::createStub(PersistingStoreInterface::class);
        $lockStore->method('save')
            ->willThrowException(new LockStorageException('Failed to create the lock directory.'));
        $logger = self::levelLogger();
        $store = new ReplayStore(new ArrayAdapter(), new MockClock(), new LockFactory($lockStore), $logger);

        self::assertTrue($store->consume('signature', null));
        self::assertFalse($store->consume('signature', null));
        self::assertSame(['error', 'error'], $logger->levels);
    }

    public function testFailingLockReleaseIsLoggedAsAWarning(): void
    {
        $lockStore = new class() implements PersistingStoreInterface {
            public function __construct(
                private readonly InMemoryStore $inner = new InMemoryStore(),
            ) {
            }

            public function save(Key $key): void
            {
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                throw new LockStorageException('Connection lost.');
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };
        $logger = self::levelLogger();
        $store = new ReplayStore(new ArrayAdapter(), new MockClock(), new LockFactory($lockStore), $logger);

        self::assertTrue($store->consume('signature', null));
        self::assertSame(['warning'], $logger->levels);
    }

    public function testExpiredEntriesArePrunedNowAndThen(): void
    {
        $pool = new class() extends ArrayAdapter implements PruneableInterface {
            public int $pruned = 0;

            public function prune(): bool
            {
                ++$this->pruned;

                return true;
            }
        };
        $store = new ReplayStore($pool, new MockClock());

        for ($i = 0; $i < 3000; ++$i) {
            $store->consume('signature-' . $i, null);
        }

        self::assertGreaterThan(0, $pool->pruned);
        self::assertLessThan(100, $pool->pruned);
    }

    /**
     * @return AbstractLogger&object{levels: list<string>}
     */
    private static function levelLogger(): AbstractLogger
    {
        return new class() extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = is_string($level) ? $level : '';
            }
        };
    }
}
