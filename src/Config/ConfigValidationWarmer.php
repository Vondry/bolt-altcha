<?php

declare(strict_types=1);

namespace Tomvondracek\Altcha\Config;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Tomvondracek\Altcha\Challenge\HmacKeyProvider;

/**
 * Validates the configuration while `cache:clear` or `cache:warmup` warms the cache, and the
 * signing key as soon as the config protects something, so a mistake fails the deploy instead of
 * quietly switching the protection off at runtime (Protection).
 *
 * Optional: those commands run optional warmers, but a web request that rebuilds the container
 * (after Bolt's "Clear the cache", which runs `cache:clear --no-warmup`, or a changed config file)
 * doesn't. As a required warmer, it would turn that visitor's request into an error page, while
 * every later request runs with the protection off anyway.
 *
 * The key is not checked while nothing is protected: right after `composer require`, on a site
 * that still has the placeholder APP_SECRET, Composer's `cache:clear` must not fail.
 */
final readonly class ConfigValidationWarmer implements CacheWarmerInterface
{
    public function __construct(
        private AltchaConfigLoader $configLoader,
        private HmacKeyProvider $keys,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->configLoader->reset();
        $config = $this->configLoader->load();
        if ($config->enabled && ($config->loginProfile !== null || $config->forms !== [] || $config->routes !== [])) {
            $this->keys->signatureKey();
        }

        return [];
    }
}
