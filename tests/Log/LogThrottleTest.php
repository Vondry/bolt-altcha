<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Tests\Log;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Tomvondracek\Altcha\Log\LogThrottle;

final class LogThrottleTest extends TestCase
{
    public function testALineIsLetThroughOncePerWindow(): void
    {
        $pool = new ArrayAdapter();
        $throttle = new LogThrottle($pool);

        self::assertTrue($throttle->allow('a'));
        self::assertFalse($throttle->allow('a'));
        self::assertFalse((new LogThrottle($pool))->allow('a'), 'another request, the same cache');
        self::assertTrue($throttle->allow('b'), 'another line');
    }

    /**
     * Better a repeated line than a lost one.
     */
    public function testAFailingCacheLetsEveryLineThrough(): void
    {
        $pool = self::createStub(CacheItemPoolInterface::class);
        $pool->method('getItem')
            ->willThrowException(new RuntimeException('Redis is down.'));
        $throttle = new LogThrottle($pool);

        self::assertTrue($throttle->allow('a'));
        self::assertTrue($throttle->allow('a'));
    }
}
